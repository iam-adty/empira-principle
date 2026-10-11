<?php

declare(strict_types=1);

namespace IamAdty\Empira\Data;

/**
 * Runs the migration files in a directory, once each, in order.
 *
 * There is no clever versioning scheme here and no way to edit a migration
 * after it has run — a migration that changes meaning halfway through a
 * project's life is how databases end up disagreeing with each other. What is
 * here is the smallest thing that works: a table recording which names have
 * run, files named so they sort into the order they happened, and the rule that
 * a failed migration is not recorded.
 *
 *     $migrator = new Migrator($db->connection(), __DIR__ . '/../migrations');
 *
 *     $migrator->pending();   // ['2026_10_11_000001_create_users']
 *     $migrator->run();       // ['2026_10_11_000001_create_users']
 *     $migrator->rollback();  // ['2026_10_11_000001_create_users']
 *
 * @see Migration
 */
final class Migrator
{
    private const LEDGER = 'migrations';

    /** @var list<string>|null memoised, because a scan hits the filesystem */
    private ?array $discovered = null;

    public function __construct(
        private readonly Connection $connection,
        private readonly string $directory,
    ) {
    }

    /**
     * Migration names, sorted as they should run.
     *
     * @return list<string>
     */
    public function all(): array
    {
        if ($this->discovered !== null) {
            return $this->discovered;
        }

        if (!is_dir($this->directory)) {
            return $this->discovered = [];
        }

        $found = glob(rtrim($this->directory, '/') . '/*.php') ?: [];

        // Sorted by filename, which is why the naming convention carries a
        // timestamp: the order is in the name, so it survives a file being moved.
        sort($found);

        $this->discovered = array_values(array_map(
            static fn(string $path): string => basename($path, '.php'),
            $found
        ));

        return $this->discovered;
    }

    /**
     * @return list<string>
     */
    public function ran(): array
    {
        $this->ensureLedger();

        $rows = $this->connection->fetchAll(
            sprintf('SELECT %s FROM %s ORDER BY %s ASC', $this->quote('name'), $this->quote(self::LEDGER), $this->quote('name'))
        );

        return array_map(static fn(array $row): string => (string) $row['name'], $rows);
    }

    /**
     * @return list<string>
     */
    public function pending(): array
    {
        return array_values(array_diff($this->all(), $this->ran()));
    }

    /**
     * Run everything that has not run yet.
     *
     * Each migration is recorded only after it succeeds. A failure leaves the
     * ledger honest, so the next run retries the migration that broke rather
     * than skipping past a half-applied one.
     *
     * @return list<string> the migrations that ran
     */
    public function run(): array
    {
        $did = [];

        foreach ($this->pending() as $name) {
            $this->connection->transaction(function () use ($name): void {
                $this->load($name)->up($this->connection);

                $this->connection->execute(
                    sprintf('INSERT INTO %s (%s, %s) VALUES (?, ?)', $this->quote(self::LEDGER), $this->quote('name'), $this->quote('ran_at')),
                    [$name, gmdate('c')]
                );
            });

            $did[] = $name;
        }

        return $did;
    }

    /**
     * Reverse the last migration that ran, in reverse order.
     *
     * @return list<string> the migrations that were reversed
     */
    public function rollback(int $steps = 1): array
    {
        $undid = [];

        $names = array_reverse($this->ran());

        for ($i = 0; $i < $steps && isset($names[$i]); $i++) {
            $name = $names[$i];

            $this->connection->transaction(function () use ($name): void {
                $this->load($name)->down($this->connection);

                $this->connection->execute(
                    sprintf('DELETE FROM %s WHERE %s = ?', $this->quote(self::LEDGER), $this->quote('name')),
                    [$name]
                );
            });

            $undid[] = $name;
        }

        return $undid;
    }

    private function load(string $name): Migration
    {
        $path = rtrim($this->directory, '/') . '/' . $name . '.php';

        if (!is_file($path)) {
            throw new \RuntimeException(sprintf('No migration file at %s.', $path));
        }

        $migration = require $path;

        if (!$migration instanceof Migration) {
            throw new \RuntimeException(sprintf(
                '%s must return an instance of %s.',
                $path,
                Migration::class
            ));
        }

        return $migration;
    }

    /**
     * The ledger exists before anything reads it, so `pending()` works on a
     * database that has never been migrated.
     */
    private function ensureLedger(): void
    {
        $this->connection->execute(sprintf(
            'CREATE TABLE IF NOT EXISTS %s (%s TEXT PRIMARY KEY, %s TEXT NOT NULL)',
            $this->quote(self::LEDGER),
            $this->quote('name'),
            $this->quote('ran_at')
        ));
    }

    private function quote(string $identifier): string
    {
        return $this->connection->grammar()->quoteIdentifier($identifier);
    }
}
