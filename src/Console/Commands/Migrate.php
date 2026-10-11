<?php

declare(strict_types=1);

namespace IamAdty\Empira\Console\Commands;

use Doctrine\Migrations\Configuration\Connection\ExistingConnection;
use Doctrine\Migrations\Configuration\Migration\ConfigurationArray;
use Doctrine\Migrations\DependencyFactory;
use Doctrine\Migrations\Exception\NoMigrationsToExecute;
use Doctrine\Migrations\Metadata\MigrationPlanList;
use Doctrine\Migrations\MigratorConfiguration;
use Doctrine\Migrations\Version\Direction;
use Doctrine\Migrations\Version\Version;
use IamAdty\Empira\Config\DatabaseConfig;
use IamAdty\Empira\Console\Command;
use IamAdty\Empira\Console\Output;

/**
 * Runs the migrations in a project's `migrations/` directory, through Doctrine.
 *
 * The database comes from {@see DatabaseConfig} — the same reader the
 * application uses at runtime. That is not tidiness. This command used to read
 * `DB_*` a second time with different defaults, so `empira migrate` run without
 * those variables set migrated a throwaway in-memory SQLite while the
 * application sat on a real one: a migration that reported success against a
 * database nobody would ever see again.
 *
 *     php bin/empira migrate
 *     php bin/empira migrate --target=path/to/app
 *     php bin/empira migrate --status
 *     php bin/empira migrate --step=down
 *
 * Doctrine owns the versioning table, the ordering, and the transaction
 * handling. What is left here is argument parsing and printing.
 */
final class Migrate implements Command
{
    /**
     * The namespace project migration classes are looked up under.
     *
     * Doctrine needs one namespace it can `require` the migration file into,
     * so an application using anonymous migration classes — which this command
     * has always supported, and which needs no autoloading — still works.
     */
    private const MIGRATION_NAMESPACE = 'Empira\\Migrations';

    public function name(): string
    {
        return 'migrate';
    }

    public function description(): string
    {
        return 'Run the migrations in a project\'s migrations/ directory.';
    }

    public function arguments(): array
    {
        return ['[--target=dir]', '[--step=down]', '[--status]'];
    }

    public function handle(array $options, Output $output): int
    {
        $target = rtrim(trim((string) ($options['target'] ?? '')), '/');
        $target = $target === '' ? (getcwd() ?: '.') : $target;

        $directory = $target . '/migrations';

        if (!is_dir($directory)) {
            $output->error(sprintf('No migrations directory at %s.', $directory));

            return 1;
        }

        try {
            $dependencies = $this->dependencies($directory);

            // Doctrine tracks what has run in a table of its own, and refuses to
            // plan anything until that table exists. Creating it here rather
            // than asking the operator to remember a `sync-metadata-storage`
            // step is the difference between a working first deploy and a
            // deploy that fails because nobody knew about a setup command.
            $dependencies->getMetadataStorage()->ensureInitialized();
        } catch (\Throwable $e) {
            $output->error($e->getMessage());

            return 1;
        }

        if ((bool) ($options['status'] ?? false)) {
            return $this->report($output, $dependencies, $directory);
        }

        $down = trim((string) ($options['step'] ?? '')) === 'down';

        try {
            $plan = $down ? $this->lastStepDown($dependencies) : $this->everythingUp($dependencies);

            if ($plan->count() === 0) {
                $output->warn($down ? 'Nothing to roll back.' : 'Nothing to migrate.');

                return 0;
            }

            $dependencies->getMigrator()->migrate($plan, new MigratorConfiguration());
        } catch (\Throwable $e) {
            $output->error($e->getMessage());

            return 1;
        }

        foreach ($plan->getItems() as $item) {
            $output->line(sprintf('  %s %s', $down ? 'rolled back' : 'migrated', $item->getVersion()));
        }

        return 0;
    }

    /**
     * The plan that runs every migration not yet applied.
     *
     * The target is resolved through the alias resolver rather than assumed,
     * because "everything" is `latest` — the newest migration that *exists* —
     * and Doctrine's own bookkeeping is what knows which have already run.
     *
     * @return MigrationPlanList empty when the directory holds no migrations
     */
    private function everythingUp(DependencyFactory $dependencies): MigrationPlanList
    {
        try {
            $latest = $dependencies->getVersionAliasResolver()->resolveVersionAlias('latest');
        } catch (NoMigrationsToExecute) {
            // An empty migrations/ directory is a project that has not written
            // any yet. That is not an error, and certainly not one to report as
            // a failed deploy.
            return new MigrationPlanList([], Direction::UP);
        }

        return $dependencies->getMigrationPlanCalculator()->getPlanUntilVersion($latest);
    }

    /**
     * Print what has run and what has not, and change nothing.
     *
     * An empty migration list and a database that was never migrated look
     * identical from the outside — both answer 200 to the next request — so
     * being able to ask without changing anything is worth the flag.
     */
    private function report(Output $output, DependencyFactory $dependencies, string $directory): int
    {
        $executed = [];

        foreach ($dependencies->getMetadataStorage()->getExecutedMigrations()->getItems() as $migration) {
            $executed[(string) $migration->getVersion()] = true;
        }

        $lines = [];

        foreach ($dependencies->getMigrationRepository()->getMigrations()->getItems() as $migration) {
            $version = (string) $migration->getVersion();
            $lines[$version] = isset($executed[$version]);
        }

        ksort($lines);

        $output->line(sprintf('Migrations in %s:', $directory));

        if ($lines === []) {
            $output->warn('  none');

            return 0;
        }

        foreach ($lines as $version => $done) {
            $output->line(sprintf('  %s %s', $done ? '[x]' : '[ ]', $version));
        }

        return 0;
    }

    /**
     * The plan that undoes exactly the most recent migration.
     *
     * `migrate --step=down` means "take back the last thing I did", so a
     * mistake costs one migration rather than all of them. Undoing the entire
     * history is not something a flag should do by accident.
     *
     * @return MigrationPlanList empty when nothing has been migrated yet
     */
    private function lastStepDown(DependencyFactory $dependencies): MigrationPlanList
    {
        $executed = $dependencies->getMetadataStorage()->getExecutedMigrations();

        if ($executed->count() === 0) {
            return new MigrationPlanList([], Direction::DOWN);
        }

        // getLast() is the most recently applied migration. Asking the library
        // is clearer than taking the last entry of a list and trusting the
        // iteration order to stay that way across versions.
        $newest = $executed->getLast()->getVersion();

        return $dependencies->getMigrationPlanCalculator()->getPlanForVersions(
            [$newest],
            Direction::DOWN,
        );
    }

    /**
     * Doctrine's migration machinery, wired to this project's directory.
     *
     * @throws \Doctrine\DBAL\Exception when the database is not there
     */
    private function dependencies(string $directory): DependencyFactory
    {
        return DependencyFactory::fromConnection(
            new ConfigurationArray([
                'migrations_paths' => [self::MIGRATION_NAMESPACE => $directory],

                // Each migration commits on its own. Wrapping the whole run in
                // one transaction is friendlier on a transaction-aware server
                // and is quietly ignored on one that cannot — which is most of
                // them, SQLite included, where it would be a lie.
                'all_or_nothing' => false,

                'table_storage' => ['table_name' => 'migrations'],
            ]),
            new ExistingConnection(DatabaseConfig::connection(DatabaseConfig::fromEnvironment())),
        );
    }
}