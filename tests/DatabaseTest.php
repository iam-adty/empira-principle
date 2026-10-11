<?php

declare(strict_types=1);

namespace IamAdty\Empira\Tests;

use IamAdty\Empira\Application;
use IamAdty\Empira\Config\Config;
use IamAdty\Empira\Container\ContainerException;
use IamAdty\Empira\Data\Connection;
use IamAdty\Empira\Data\ConnectionFactory;
use IamAdty\Empira\Data\Db;
use IamAdty\Empira\Data\Grammars\PostgresGrammar;
use IamAdty\Empira\Data\Grammars\SqliteGrammar;
use IamAdty\Empira\Data\Migration;
use IamAdty\Empira\Data\Migrator;
use IamAdty\Empira\State\NullState;
use PHPUnit\Framework\TestCase;

/**
 * Connecting, and changing schema.
 */
final class DatabaseTest extends TestCase
{
    private const SECRET = 'a-secret-that-is-long-enough';

    private string $directory = '';

    protected function tearDown(): void
    {
        if ($this->directory !== '' && is_dir($this->directory)) {
            foreach (glob($this->directory . '/*') ?: [] as $file) {
                unlink($file);
            }

            rmdir($this->directory);
        }

        $this->directory = '';
    }

    private function migrationDirectory(): string
    {
        if ($this->directory === '') {
            $this->directory = sys_get_temp_dir() . '/empira-migrations-' . bin2hex(random_bytes(6));

            mkdir($this->directory, 0o777, true);
        }

        return $this->directory;
    }

    /**
     * Write a migration file.
     *
     * It returns an anonymous class, so two tests can each declare a migration
     * called `create_users` without PHP refusing to load the second one.
     *
     * The template is a nowdoc on purpose: in a double-quoted string the `$c`
     * in the method signature would be interpolated away and the file would
     * not parse.
     *
     * @param string $body the body of `up()`, as PHP source
     */
    private function migration(string $name, string $body): void
    {
        $template = <<<'PHP'
            <?php

            declare(strict_types=1);

            use IamAdty\Empira\Data\Connection;
            use IamAdty\Empira\Data\Migration;

            return new class implements Migration
            {
                public function up(Connection $c): void
                {
                    %s
                }

                public function down(Connection $c): void
                {
                }
            };

            PHP;

        file_put_contents(
            $this->migrationDirectory() . '/' . $name . '.php',
            sprintf($template, $body)
        );
    }

    // ------------------------------------------------------------- factory

    public function testTheFactoryBuildsASqliteConnectionFromNothing(): void
    {
        $connection = ConnectionFactory::make(new Config());

        $this->assertInstanceOf(SqliteGrammar::class, $connection->grammar());
    }

    public function testTheFactoryRefusesADriverItCannotBuild(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown database driver "oracle"');

        ConnectionFactory::make(new Config(['db' => ['driver' => 'oracle']]));
    }

    public function testSqliteTurnsOnForeignKeys(): void
    {
        // Off by default, and silently so: a cascade delete that quietly does
        // not happen is the kind of bug found in production.
        $connection = ConnectionFactory::make(new Config(['db' => ['driver' => 'sqlite', 'database' => ':memory:']]));

        $this->assertSame('1', (string) $connection->fetchOne('PRAGMA foreign_keys')['foreign_keys']);
    }

    // -------------------------------------------------------------- wiring

    public function testDbCannotBeAutowiredUntilTheApplicationIsWired(): void
    {
        $app = new Application(self::SECRET, new NullState());

        try {
            $app->container()->get(Db::class);

            $this->fail('Db should not be resolvable before database() is called.');
        } catch (ContainerException $e) {
            $this->assertStringContainsString('PDO', $e->getMessage());
        }
    }

    public function testDatabaseMakesDbAutowired(): void
    {
        $app = new Application(self::SECRET, new NullState());
        $app->database(['driver' => 'sqlite', 'database' => ':memory:']);

        $this->assertInstanceOf(Db::class, $app->container()->get(Db::class));
        $this->assertInstanceOf(Connection::class, $app->container()->get(Connection::class));
    }

    public function testAskingForTheDatabaseBeforeWiringExplainsItself(): void
    {
        $app = new Application(self::SECRET, new NullState());

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('No database is configured');

        $app->db();
    }

    public function testTheDatabaseCanBeReadFromBoundConfiguration(): void
    {
        $app = new Application(self::SECRET, new NullState());
        $app->container()->instance(Config::class, new Config([
            'db' => ['driver' => 'sqlite', 'database' => ':memory:'],
        ]));

        $app->database();

        $this->assertInstanceOf(Db::class, $app->container()->get(Db::class));
    }

    public function testAnInjectedRepositoryCanAutowireTheDatabase(): void
    {
        $app = new Application(self::SECRET, new NullState());
        $app->database(['driver' => 'sqlite', 'database' => ':memory:']);

        $connection = $app->container()->get(Connection::class);
        $connection->execute('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT NOT NULL)');
        $connection->execute("INSERT INTO users (name) VALUES ('Ada')");

        // What a future ORM or repository does: ask for Db and be handed one.
        $db = $app->container()->get(Db::class);

        $this->assertSame('Ada', $db->first('users')['name']);
    }

    // ------------------------------------------------------------ migrator

    public function testItRunsAPendingMigrationAndRecordsIt(): void
    {
        $this->migration('2026_10_11_000001_create_users', '$c->execute(\'CREATE TABLE users (id INTEGER PRIMARY KEY)\');');

        $connection = Connection::sqlite(':memory:');
        $migrator = new Migrator($connection, $this->migrationDirectory());

        $this->assertSame(['2026_10_11_000001_create_users'], $migrator->pending());
        $this->assertSame([], $migrator->ran());

        $this->assertSame(['2026_10_11_000001_create_users'], $migrator->run());
        $this->assertSame(['2026_10_11_000001_create_users'], $migrator->ran());
        $this->assertSame([], $migrator->pending());

        $this->assertNotNull($connection->fetchOne("SELECT * FROM sqlite_master WHERE name = 'users'"));
    }

    public function testItDoesNotRunTheSameMigrationTwice(): void
    {
        $this->migration('2026_10_11_000001_create_users', '$c->execute(\'CREATE TABLE users (id INTEGER PRIMARY KEY)\');');

        $migrator = new Migrator(Connection::sqlite(':memory:'), $this->migrationDirectory());

        $this->assertCount(1, $migrator->run());
        $this->assertSame([], $migrator->run(), 'the second run has nothing to do');
    }

    public function testItRunsMigrationsInFilenameOrder(): void
    {
        $this->migration('2026_10_11_000002_second', '$c->execute(\'CREATE TABLE second (id INTEGER)\');');
        $this->migration('2026_10_11_000001_first', '$c->execute(\'CREATE TABLE first (id INTEGER)\');');

        $migrator = new Migrator(Connection::sqlite(':memory:'), $this->migrationDirectory());

        $this->assertSame(
            ['2026_10_11_000001_first', '2026_10_11_000002_second'],
            $migrator->run(),
            'the timestamp in the name is the order'
        );
    }

    public function testAFailedMigrationIsNotRecorded(): void
    {
        // The ledger has to stay honest: a migration recorded as done but not
        // applied is skipped forever, and the schema quietly disagrees with it.
        $this->migration('2026_10_11_000001_broken', 'throw new \\RuntimeException(\'no such table\');');

        $migrator = new Migrator(Connection::sqlite(':memory:'), $this->migrationDirectory());

        try {
            $migrator->run();

            $this->fail('The migration should have failed.');
        } catch (\RuntimeException $e) {
            $this->assertSame('no such table', $e->getMessage());
        }

        $this->assertSame([], $migrator->ran());
        $this->assertSame(['2026_10_11_000001_broken'], $migrator->pending(), 'it is retried, not skipped');
    }

    public function testItRollsBackTheLastMigration(): void
    {
        $this->migration('2026_10_11_000001_create_users', '$c->execute(\'CREATE TABLE users (id INTEGER PRIMARY KEY)\');');

        $connection = Connection::sqlite(':memory:');
        $migrator = new Migrator($connection, $this->migrationDirectory());

        $migrator->run();

        $this->assertSame(['2026_10_11_000001_create_users'], $migrator->rollback());
        $this->assertSame([], $migrator->ran());
    }

    public function testItRefusesAMigrationFileThatReturnsSomethingElse(): void
    {
        file_put_contents($this->migrationDirectory() . '/2026_10_11_000001_wrong.php', "<?php\n\nreturn 'not a migration';\n");

        $migrator = new Migrator(Connection::sqlite(':memory:'), $this->migrationDirectory());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('must return an instance of');

        $migrator->run();
    }

    public function testAnAbsentMigrationsDirectoryIsEmptyNotAnError(): void
    {
        $migrator = new Migrator(Connection::sqlite(':memory:'), '/nowhere/at/all');

        $this->assertSame([], $migrator->all());
        $this->assertSame([], $migrator->run());
    }

    public function testTheLedgerIsQuotedThroughTheConnectionDialect(): void
    {
        // The ledger is a real table on a real server, so it has to be named
        // the way that server quotes.
        $this->assertSame(
            'CREATE TABLE IF NOT EXISTS `migrations` (`name` TEXT PRIMARY KEY, `ran_at` TEXT NOT NULL)',
            $this->ledgerSql(new SqliteGrammar())
        );

        $this->assertSame(
            'CREATE TABLE IF NOT EXISTS "migrations" ("name" TEXT PRIMARY KEY, "ran_at" TEXT NOT NULL)',
            $this->ledgerSql(new PostgresGrammar())
        );
    }

    /**
     * The statement {@see Migrator} issues, with whatever quoting the dialect
     * gives it.
     */
    private function ledgerSql(\IamAdty\Empira\Data\Grammar $grammar): string
    {
        $logged = new Connection(Connection::sqlite(':memory:')->pdo(), true, $grammar);

        (new Migrator($logged, sys_get_temp_dir()))->ran();

        $queries = $logged->queries();

        $this->assertNotSame([], $queries, 'the ledger should have been created');

        return $queries[0]['sql'];
    }
}
