<?php

declare(strict_types=1);

namespace IamAdty\Empira\Tests;

use IamAdty\Empira\Data\Connection;
use IamAdty\Empira\Data\Grammars\PostgresGrammar;
use IamAdty\Empira\Data\Query;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * The query builder against a real PostgreSQL server.
 *
 * These are the tests that make the grammar claim true rather than plausible.
 * Everything the builder emits has to be valid PostgreSQL, and the only way to
 * know that is to hand it to PostgreSQL.
 *
 * They are grouped `driver` and skipped when the environment has no server, so
 * a plain `vendor/bin/phpunit` still passes on a machine that has none. The
 * point is that they are never skipped silently in the Docker runner, where
 * `tools/test` refuses to start without a healthy server.
 *
 * @see tools/test
 */
#[Group('driver')]
final class PostgresTest extends TestCase
{
    private ?Connection $connection = null;

    protected function setUp(): void
    {
        $dsn = getenv('EMPIRA_TEST_PGSQL_DSN');

        if (!is_string($dsn) || $dsn === '') {
            $this->markTestSkipped('No PostgreSQL configured; set EMPIRA_TEST_PGSQL_DSN to run the driver tests.');
        }

        $this->connection = new Connection(new \PDO(
            $dsn,
            (string) getenv('EMPIRA_TEST_PGSQL_USER'),
            (string) getenv('EMPIRA_TEST_PGSQL_PASSWORD'),
        ));
    }

    private function connection(): Connection
    {
        $this->connection?->execute('DROP TABLE IF EXISTS widgets');
        $this->connection?->execute('DROP TABLE IF EXISTS teams');

        $this->connection->execute('CREATE TABLE widgets (
            id SERIAL PRIMARY KEY,
            name TEXT NOT NULL,
            team_id INTEGER,
            price INTEGER
        )');

        $this->connection->execute(
            "INSERT INTO widgets (name, team_id, price) VALUES ('alpha', 1, 10), ('beta', 1, 20), ('gamma', 2, 40)"
        );

        return $this->connection;
    }

    public function testItSpeaksTheDialectTheServerActuallySpeaks(): void
    {
        $connection = $this->connection();

        $this->assertInstanceOf(PostgresGrammar::class, $connection->grammar());
    }

    public function testAQuotedIdentifierIsAcceptedByPostgres(): void
    {
        $connection = $this->connection();

        // Backticks here would be a syntax error on arrival, which is the whole
        // reason the grammar seam exists.
        $rows = $connection->fetchAll('SELECT * FROM "widgets" ORDER BY "name"');

        $this->assertSame(['alpha', 'beta', 'gamma'], array_column($rows, 'name'));
    }

    public function testItRoundTripsThroughTheBuilderOnPostgres(): void
    {
        $connection = $this->connection();

        $rows = (new Query($connection))
            ->get('widgets')
            ->where('team_id', 1)
            ->orderBy('price', 'desc')
            ->fetch();

        $this->assertSame(['beta', 'alpha'], array_column($rows, 'name'));
    }

    public function testItInsertsAndReadsBackTheGeneratedKey(): void
    {
        $connection = $this->connection();

        $inserted = (new Query($connection))
            ->get('widgets')
            ->insert(['name' => 'delta', 'team_id' => 3, 'price' => 40]);

        $this->assertSame(1, $inserted->affected());

        $row = (new Query($connection))->get('widgets')->where('id', (int) $connection->lastInsertId())->first();

        $this->assertSame('delta', $row['name']);
    }

    public function testItJoinsAcrossTables(): void
    {
        $connection = $this->connection();
        $connection->execute('CREATE TABLE teams (id INTEGER PRIMARY KEY, name TEXT NOT NULL)');
        $connection->execute("INSERT INTO teams (id, name) VALUES (1, 'red'), (2, 'blue')");

        // Only the joined column is selected: two columns both called `name`
        // would arrive as a single associative key, because that is how a
        // duplicate column name behaves on every driver, not a builder defect.
        $rows = (new Query($connection))
            ->get('widgets')
            ->select('teams.name')
            ->join('teams', 'widgets.team_id', '=', 'teams.id')
            ->orderBy('teams.name', 'desc')
            ->fetch();

        $this->assertSame(['red', 'red', 'blue'], array_column($rows, 'name'));
    }

    public function testALeftJoinKeepsRowsWithNoMatch(): void
    {
        $connection = $this->connection();
        $connection->execute('CREATE TABLE teams (id INTEGER PRIMARY KEY, name TEXT NOT NULL)');
        $connection->execute("INSERT INTO teams (id, name) VALUES (1, 'red')");

        // team_id 2 has no team row; an inner join would drop `gamma` entirely.
        $rows = (new Query($connection))
            ->get('widgets')
            ->select('widgets.name')
            ->leftJoin('teams', 'widgets.team_id', '=', 'teams.id')
            ->orderBy('widgets.name')
            ->fetch();

        $this->assertSame(['alpha', 'beta', 'gamma'], array_column($rows, 'name'));
    }

    public function testItCounts(): void
    {
        $connection = $this->connection();

        $rows = (new Query($connection))
            ->get('widgets')
            ->select('team_id')
            ->count()
            ->groupBy('team_id')
            ->orderBy('team_id')
            ->fetch();

        // An int, not a string: the connection turns off STRINGIFY_FETCHES, so
        // a numeric column arrives with its type intact.
        $this->assertSame([['team_id' => 1, 'aggregate' => 2], ['team_id' => 2, 'aggregate' => 1]], $rows);
    }

    public function testItSumsWithAHavingClause(): void
    {
        $connection = $this->connection();

        // Team 1 sums to 30 and team 2 to 40, so a threshold of 40 admits
        // exactly one group — which is what makes this a test of HAVING rather
        // than of the fixture.
        $rows = (new Query($connection))
            ->get('widgets')
            ->select('team_id')
            ->sum('price')
            ->groupBy('team_id')
            ->having('SUM', 'price', 40, '>=')
            ->fetch();

        $this->assertSame([['team_id' => 2, 'aggregate' => 40]], $rows);
    }

    public function testItFiltersWithWhereIn(): void
    {
        $connection = $this->connection();

        $rows = (new Query($connection))
            ->get('widgets')
            ->whereIn('name', ['alpha', 'gamma'])
            ->orderBy('name')
            ->fetch();

        $this->assertSame(['alpha', 'gamma'], array_column($rows, 'name'));
    }

    public function testItRollsBackAFailedTransaction(): void
    {
        $connection = $this->connection();

        try {
            $connection->transaction(function (Connection $db): void {
                $db->execute("INSERT INTO widgets (name) VALUES ('doomed')");

                throw new \RuntimeException('changed my mind');
            });

            $this->fail('The exception should have propagated.');
        } catch (\RuntimeException $e) {
            $this->assertSame('changed my mind', $e->getMessage());
        }

        $rows = $connection->fetchAll('SELECT * FROM "widgets" WHERE "name" = ?', ['doomed']);

        $this->assertSame([], $rows);
    }
}
