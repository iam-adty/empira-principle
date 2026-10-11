<?php

declare(strict_types=1);

namespace IamAdty\Empira\Tests;

use IamAdty\Empira\Config\Config;
use IamAdty\Empira\Config\DatabaseConfig;
use IamAdty\Empira\Console\Application as ConsoleApplication;
use IamAdty\Empira\Console\Output;
use PHPUnit\Framework\TestCase;

/**
 * Migrations as a deploy step, on Doctrine.
 *
 * The command used to run a hand-rolled migrator against its own copy of the
 * database configuration — a copy with *different defaults*, so `empira migrate`
 * run without `DB_*` set migrated an in-memory SQLite that vanished on exit while
 * the application sat on a real one. It reported success. These tests pin the
 * two things that were wrong: which database it reaches, and what it actually
 * does to it.
 */
final class MigrationsTest extends TestCase
{
    /** @var list<string> */
    private array $temporary = [];

    /**
     * A distinct migration version per fixture.
     *
     * Doctrine `require_once`s migration files rather than re-reading them, so a
     * repeated version is a fatal redeclaration halfway through the run.
     */
    private static int $sequence = 1;

    /** The migrations directory for this test, created on first use. */
    private string $directory = '';

    /** The one migration version this test declares. */
    private string $version = '';

    protected function tearDown(): void
    {
        foreach ($this->temporary as $path) {
            // Recursive: these paths are migrations *directories* holding
            // generated PHP, and unlink() on a directory is a silent no-op that
            // leaves `tmp/` filling up one fixture tree per run.
            Temp::remove($path);
        }

        $this->temporary = [];
    }

    // ------------------------------------------------------- which database

    public function testMigrateReachesTheDatabaseTheEnvironmentNames(): void
    {
        $path = $this->database();

        $first = $this->migrate($path);

        self::assertSame(0, $first['code'], $first['output']);
        // Asserted on the shape of the line, not one hardcoded version: each test gets
        // its own fixture version, and the point is that *a* migration ran and
        // said so. Doctrine prints the fully-qualified class name.
        self::assertMatchesRegularExpression('/migrated \S+/', $first['output']);

        // The whole point of the shared config. Under the old command this
        // migration went into `:memory:` and this file stayed empty — and the
        // command still printed "migrated".
        self::assertFileExists($path);
        self::assertGreaterThan(0, (int) $this->scalar($path, "SELECT count(*) FROM sqlite_master WHERE type='table' AND name='widgets'"));
    }

    public function testTheTwoConsumersOfTheConfigurationAgree(): void
    {
        // Both the command and the runtime read DB_* through this one reader, so
        // "which database" cannot have two answers that disagree.
        $_ENV['DB_DRIVER'] = 'pgsql';
        $_ENV['DB_DATABASE'] = 'somewhere';
        $_ENV['DB_HOST'] = 'elsewhere';

        $fromEnv = DatabaseConfig::fromEnvironment();

        self::assertSame('pgsql', $fromEnv->string('driver'));
        self::assertSame('somewhere', $fromEnv->string('database'));
        self::assertSame('elsewhere', $fromEnv->string('host'));

        // …and what Doctrine is handed is derived from that, not read again.
        $parameters = DatabaseConfig::dbalParameters($fromEnv);

        self::assertSame('pdo_pgsql', $parameters['driver']);
        self::assertSame('somewhere', $parameters['dbname']);
        self::assertSame('elsewhere', $parameters['host']);
    }

    public function testAnUnknownDriverIsRefusedRatherThanDefaultedToSqlite(): void
    {
        // A connection that quietly opens the wrong database is a working
        // application holding no data, which is the worst failure available.
        $this->expectException(\InvalidArgumentException::class);

        DatabaseConfig::dbalParameters(new Config(['driver' => 'oracle']));
    }

    public function testAPortOfZeroIsLeftOutSoTheDriverDecides(): void
    {
        $parameters = DatabaseConfig::dbalParameters(new Config([
            'driver' => 'pgsql',
            'database' => 'app',
            'port' => 0,
        ]));

        self::assertArrayNotHasKey('port', $parameters);
    }

    // ---------------------------------------------------- what it does to it

    public function testMigratingTwiceChangesNothingTheSecondTime(): void
    {
        $path = $this->database();

        self::assertSame(0, $this->migrate($path)['code']);
        $second = $this->migrate($path);

        self::assertSame(0, $second['code']);
        self::assertStringContainsString('Nothing to migrate', $second['output']);
    }

    public function testStatusReportsWhatHasRunWithoutChangingIt(): void
    {
        $path = $this->database();

        $before = $this->migrate($path, '--status');

        // Before migrating: listed, not applied.
        self::assertMatchesRegularExpression('/\[ \] \S+/', $before['output']);

        self::assertSame(0, $this->migrate($path)['code']);

        $after = $this->migrate($path, '--status');

        self::assertMatchesRegularExpression('/\[x\] \S+/', $after['output']);
    }

    public function testSteppingDownUndoesExactlyTheLastMigration(): void
    {
        $path = $this->database();

        self::assertSame(0, $this->migrate($path)['code']);
        self::assertSame(0, $this->migrate($path)['code']);

        $back = $this->migrate($path, '--step=down');

        self::assertSame(0, $back['code'], $back['output']);
        self::assertStringContainsString('rolled back', $back['output']);

        // The table is gone, but the ledger that records it is not — losing the
        // bookkeeping would mean losing the ability to say what happened.
        self::assertSame(0, (int) $this->scalar($path, "SELECT count(*) FROM sqlite_master WHERE type='table' AND name='widgets'"));
        self::assertSame(1, (int) $this->scalar($path, "SELECT count(*) FROM sqlite_master WHERE type='table' AND name='migrations'"));
    }

    public function testSteppingDownWithNothingAppliedSaysSoRatherThanFailing(): void
    {
        $path = $this->database();

        $result = $this->migrate($path, '--step=down');

        self::assertSame(0, $result['code'], $result['output']);
        self::assertStringContainsString('Nothing to roll back', $result['output']);
    }

    public function testAMissingMigrationsDirectoryIsAnErrorNotASilentSuccess(): void
    {
        $directory = $this->tempdir();

        $stream = fopen('php://memory', 'w+');
        $code = (new ConsoleApplication())->run(
            ['empira', 'migrate', '--target=' . $directory],
            new Output($stream)
        );
        rewind($stream);
        $output = (string) stream_get_contents($stream);
        fclose($stream);

        self::assertSame(1, $code);
        self::assertStringContainsString('No migrations directory', $output);
    }

    // ------------------------------------------------------------- helpers

    /**
     * A migrations directory holding one migration that creates `widgets`.
     */
    private function tempdir(): string
    {
        $directory = Temp::dir('empira-migrations-');
        $this->temporary[] = $directory;

        return $directory;
    }

    private function database(): string
    {
        $path = Temp::file('empira-migrations-db-');
        $this->temporary[] = $path;

        return $path;
    }

    /**
     * The one migrations directory this test uses.
     *
     * Created once and reused: a project has a single `migrations/`, and making
     * a fresh one per invocation would mean each `migrate()` call saw a
     * different set of migrations entirely.
     */
private function migrationsDirectory(): string
    {
        if ($this->directory === '') {
            $root = Temp::dir('empira-migrations-');

            // The *parent* is registered, not the `migrations/` directory
            // itself: removing the parent takes both, and registering the child
            // would leave an empty Temp directory behind on every run.
            $this->temporary[] = $root;

            $this->directory = $root . '/migrations';

            mkdir($this->directory, 0o777, true);
        }

        return $this->directory;
    }

private function migrate(string $database, string $flag = ''): array
    {
        $directory = $this->migrationsDirectory();

        if ($this->version === '') {
            // Doctrine `require_once`s migration files, so a version number has to
            // be unique across the whole run — two fixtures both declaring the
            // same class is a fatal redeclaration, not a failed assertion.
            $this->version = 'Version' . (string) self::$sequence++;

            file_put_contents($directory . '/' . $this->version . '.php', str_replace(
                '__VERSION__',
                $this->version,
                <<<'PHP'
                    <?php

                    declare(strict_types=1);

                    namespace Empira\Migrations;

                    use Doctrine\DBAL\Schema\Schema;
                    use Doctrine\Migrations\AbstractMigration;

                    final class __VERSION__ extends AbstractMigration
                    {
                        public function up(Schema $schema): void
                        {
                            $widgets = $schema->createTable('widgets');
                            $widgets->addColumn('id', 'integer', ['autoincrement' => true]);
                            $widgets->setPrimaryKey(['id']);
                            $widgets->addColumn('name', 'string', ['length' => 100]);
                        }

                        public function down(Schema $schema): void
                        {
                            $schema->dropTable('widgets');
                        }
                    }
                    PHP
            ));
        }

        $stream = fopen('php://memory', 'w+');

        $_ENV['DB_DRIVER'] = 'sqlite';
        $_ENV['DB_DATABASE'] = $database;

        try {
            $code = (new ConsoleApplication())->run(
                ['empira', 'migrate', '--target=' . dirname($directory), $flag],
                new Output($stream)
            );
        } finally {
            unset($_ENV['DB_DRIVER'], $_ENV['DB_DATABASE']);
        }

        rewind($stream);
        $output = (string) stream_get_contents($stream);
        fclose($stream);

        return ['code' => $code, 'output' => $output];
    }

    private function scalar(string $database, string $sql): mixed
    {
        $pdo = new \PDO('sqlite:' . $database);

        return $pdo->query($sql)->fetchColumn();
    }
}