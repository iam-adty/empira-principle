<?php

declare(strict_types=1);

namespace IamAdty\Empira\Tests;

use IamAdty\Empira\Data\Connection;
use IamAdty\Empira\Data\Db;
use IamAdty\Empira\Data\Query;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DataTest extends TestCase
{
    private const SCHEMA = [
        'CREATE TABLE users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            team_id INTEGER,
            age INTEGER
        )',
        'CREATE TABLE teams (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL
        )',
    ];

    /**
     * A value a script kiddie would paste. None of it may reach the SQL.
     */
    private const NASTY = "Ada' OR '1'='1";

    // ---------------------------------------------------------------- select

    public function testItBuildsASelectWithNoFilters(): void
    {
        $query = (new Query())->get('users');

        $this->assertSame('SELECT * FROM `users`', $query->toSql());
        $this->assertSame([], $query->bindings());
    }

    public function testItDefaultsToAStarWhenNoColumnsAreChosen(): void
    {
        $query = (new Query())->from('users');

        $this->assertSame('SELECT * FROM `users`', $query->toSql());
    }

    public function testItQuotesEachSegmentOfAQualifiedIdentifier(): void
    {
        $query = (new Query())->from('users')->select('users.id', 'users.name', 'users.*');

        $this->assertSame('SELECT `users`.`id`, `users`.`name`, `users`.* FROM `users`', $query->toSql());
    }

    public function testItBuildsAWhereWithAPlaceholder(): void
    {
        $query = (new Query())->get('users')->where('age', 36, '>');

        $this->assertSame('SELECT * FROM `users` WHERE `age` > ?', $query->toSql());
        $this->assertSame([36], $query->bindings());
    }

    public function testItTreatsANullValueAsIsNull(): void
    {
        $query = (new Query())->get('users')->where('team_id', null);

        $this->assertSame('SELECT * FROM `users` WHERE `team_id` IS NULL', $query->toSql());
        $this->assertSame([], $query->bindings());
    }

    public function testItAndsEveryConditionTogether(): void
    {
        $query = (new Query())->get('users')
            ->where('age', 36)
            ->where('name', 'Ada')
            ->whereNull('team_id');

        $this->assertSame(
            'SELECT * FROM `users` WHERE `age` = ? AND `name` = ? AND `team_id` IS NULL',
            $query->toSql()
        );
        $this->assertSame([36, 'Ada'], $query->bindings());
    }

    public function testItBuildsAWhereIn(): void
    {
        $query = (new Query())->get('users')->whereIn('name', ['Ada', 'Grace']);

        $this->assertSame('SELECT * FROM `users` WHERE `name` IN (?, ?)', $query->toSql());
        $this->assertSame(['Ada', 'Grace'], $query->bindings());
    }

    public function testItRefusesAnEmptyWhereIn(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new Query())->get('users')->whereIn('name', []);
    }

    public function testItBuildsAnOrderBy(): void
    {
        $query = (new Query())->get('users')->orderBy('name')->orderBy('age', 'DESC');

        $this->assertSame('SELECT * FROM `users` ORDER BY `name` asc, `age` desc', $query->toSql());
        $this->assertSame([], $query->bindings());
    }

    public function testItRefusesAnUnknownSortDirection(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new Query())->get('users')->orderBy('name', 'asc; DROP TABLE users');
    }

    public function testItBuildsALimitAndAnOffset(): void
    {
        $query = (new Query())->get('users')->orderBy('name')->limit(10)->offset(20);

        $this->assertSame('SELECT * FROM `users` ORDER BY `name` asc LIMIT ? OFFSET ?', $query->toSql());
        $this->assertSame([10, 20], $query->bindings());
    }

    public function testItTreatsABareOffsetAsUnlimited(): void
    {
        $query = (new Query())->get('users')->offset(5);

        $this->assertSame('SELECT * FROM `users` LIMIT ? OFFSET ?', $query->toSql());
        $this->assertSame([-1, 5], $query->bindings());
    }

    public function testItRefusesANegativeLimit(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new Query())->get('users')->limit(-1);
    }

    public function testItRefusesANegativeOffset(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new Query())->get('users')->offset(-1);
    }

    public function testItBuildsAGroupBy(): void
    {
        $query = (new Query())->get('users')->groupBy('team_id', 'role');

        $this->assertSame('SELECT * FROM `users` GROUP BY `team_id`, `role`', $query->toSql());
    }

    public function testItBuildsAnAggregate(): void
    {
        $query = (new Query())->get('users')->count();

        $this->assertSame('SELECT COUNT(*) AS `aggregate` FROM `users`', $query->toSql());
    }

    public function testAnAggregateSitsAlongsideTheColumnsItIsGroupedBy(): void
    {
        $query = (new Query())->get('users')->select('team_id')->count()->groupBy('team_id');

        $this->assertSame(
            'SELECT `team_id`, COUNT(*) AS `aggregate` FROM `users` GROUP BY `team_id`',
            $query->toSql()
        );
    }

    public function testItBuildsEveryAggregate(): void
    {
        foreach (['sum', 'avg', 'min', 'max'] as $function) {
            $query = (new Query())->get('users')->{$function}('age');

            $this->assertSame(
                sprintf('SELECT %s(`age`) AS `aggregate` FROM `users`', strtoupper($function)),
                $query->toSql()
            );
        }
    }

    public function testItRefusesAnUnknownAggregate(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown aggregate');

        (new Query())->get('users')->having('MEDIAN', 'age', 30);
    }

    public function testItBuildsAHavingAndBindsItsValue(): void
    {
        $query = (new Query())->get('users')->sum('age')->groupBy('team')->having('SUM', 'age', 30, '>=');

        $this->assertSame(
            'SELECT SUM(`age`) AS `aggregate` FROM `users` GROUP BY `team` HAVING SUM(`age`) >= ?',
            $query->toSql()
        );
        $this->assertSame([30], $query->bindings());
    }

    public function testHavingBindsAfterWhereBinds(): void
    {
        // The order of these two lists has to match the order of the
        // placeholders in the SQL, or a condition silently reads the wrong
        // column's value.
        $query = (new Query())
            ->get('users')
            ->where('active', true)
            ->sum('age')
            ->having('SUM', 'age', 30);

        $this->assertSame([true, 30], $query->bindings());
    }

    public function testItBuildsLeftAndRightJoins(): void
    {
        $this->assertSame(
            'SELECT * FROM `users` LEFT JOIN `teams` ON `users`.`team_id` = `teams`.`id`',
            (new Query())->get('users')->leftJoin('teams', 'users.team_id', '=', 'teams.id')->toSql()
        );

        $this->assertSame(
            'SELECT * FROM `users` RIGHT JOIN `teams` ON `users`.`team_id` = `teams`.`id`',
            (new Query())->get('users')->rightJoin('teams', 'users.team_id', '=', 'teams.id')->toSql()
        );
    }

    public function testAnAggregateQuotesItsAliasThroughTheGrammar(): void
    {
        // Written by hand this is the bug PostgreSQL rejects: backticks are a
        // syntax error there, so the alias has to travel through the dialect.
        $query = (new Query(null, new \IamAdty\Empira\Data\Grammars\PostgresGrammar()))
            ->get('users')
            ->count();

        $this->assertSame('SELECT COUNT(*) AS "aggregate" FROM "users"', $query->toSql());
    }

    public function testItBuildsAJoin(): void
    {
        $query = (new Query())->get('users')->join('teams', 'users.team_id', '=', 'teams.id');

        $this->assertSame(
            'SELECT * FROM `users` INNER JOIN `teams` ON `users`.`team_id` = `teams`.`id`',
            $query->toSql()
        );
        $this->assertSame([], $query->bindings());
    }

    public function testItAssemblesACompleteSelect(): void
    {
        $query = (new Query())
            ->from('users')
            ->select('users.id', 'users.name')
            ->join('teams', 'users.team_id', '=', 'teams.id')
            ->where('users.age', 30, '>=')
            ->whereIn('users.team_id', [1, 2])
            ->whereNull('users.age')
            ->orderBy('users.name', 'desc')
            ->limit(25)
            ->offset(50);

        $this->assertSame(
            'SELECT `users`.`id`, `users`.`name`'
            . ' FROM `users`'
            . ' INNER JOIN `teams` ON `users`.`team_id` = `teams`.`id`'
            . ' WHERE `users`.`age` >= ? AND `users`.`team_id` IN (?, ?) AND `users`.`age` IS NULL'
            . ' ORDER BY `users`.`name` desc'
            . ' LIMIT ? OFFSET ?',
            $query->toSql()
        );
        $this->assertSame([30, 1, 2, 25, 50], $query->bindings());
    }

    // ---------------------------------------------------------------- writes

    public function testItBuildsAnInsert(): void
    {
        $query = (new Query())->from('users')->insert(['name' => 'Ada', 'team_id' => 1]);

        $this->assertSame('INSERT INTO `users` (`name`, `team_id`) VALUES (?, ?)', $query->toSql());
        $this->assertSame(['Ada', 1], $query->bindings());
    }

    public function testItBuildsAnUpdate(): void
    {
        $query = (new Query())->from('users')->where('id', 7)->update(['name' => 'Ada']);

        $this->assertSame('UPDATE `users` SET `name` = ? WHERE `id` = ?', $query->toSql());
        $this->assertSame(['Ada', 7], $query->bindings());
    }

    public function testItBuildsADelete(): void
    {
        $query = (new Query())->from('users')->where('id', 7)->delete();

        $this->assertSame('DELETE FROM `users` WHERE `id` = ?', $query->toSql());
        $this->assertSame([7], $query->bindings());
    }

    public function testItRefusesAnUpdateWithNoColumns(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new Query())->from('users')->update([]);
    }

    public function testItNeedsATableBeforeItCanBuildSql(): void
    {
        $this->expectException(\LogicException::class);

        (new Query())->toSql();
    }

    public function testItNeedsATableBeforeItCanInsert(): void
    {
        $this->expectException(\LogicException::class);

        (new Query())->insert(['name' => 'Ada'])->toSql();
    }

    // ------------------------------------------------------------- injection

    public function testItNeverInterpolatesAValueIntoTheSql(): void
    {
        $query = (new Query())->get('users')->where('name', self::NASTY);

        $this->assertSame('SELECT * FROM `users` WHERE `name` = ?', $query->toSql());
        $this->assertStringNotContainsString(self::NASTY, $query->toSql());
        $this->assertStringContainsString('?', $query->toSql());
        $this->assertSame([self::NASTY], $query->bindings());
    }

    /**
     * @param callable(string): Query $build
     */
    #[DataProvider('unsafeIdentifiersAtEveryEntryPoint')]
    public function testItRefusesUnsafeIdentifiers(string $where, callable $build, string $identifier): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $build($identifier);
    }

    /**
     * Every place a caller can hand the builder a name has to be checked; one
     * that forgets is the hole the whole class is built to close.
     *
     * @return list<array{string, callable(string): Query, string}>
     */
    public static function unsafeIdentifiersAtEveryEntryPoint(): array
    {
        $unsafe = [
            'users; DROP TABLE users',
            "users' OR '1'='1",
            '1=1',
            '1 OR 1',
            'users--',
            'users --',
            'users/*x*/',
            'users`',
            '`users`',
            'users name',
            'users(name)',
            '(SELECT * FROM users)',
            'users,passwords',
            '0',
            'user$',
            'users%',
            "users\nDROP TABLE users",
            'user.',
            '.user',
            'users..name',
            '',
        ];

        $entryPoints = [
            'from' => static fn (string $id): Query => (new Query())->from($id),
            'select' => static fn (string $id): Query => (new Query())->from('users')->select($id),
            'where' => static fn (string $id): Query => (new Query())->from('users')->where($id, 1),
            'whereIn' => static fn (string $id): Query => (new Query())->from('users')->whereIn($id, [1]),
            'whereNull' => static fn (string $id): Query => (new Query())->from('users')->whereNull($id),
            'orderBy' => static fn (string $id): Query => (new Query())->from('users')->orderBy($id),
            'joinTable' => static fn (string $id): Query => (new Query())->from('users')->join($id, 'a', '=', 'b'),
            'joinFirst' => static fn (string $id): Query => (new Query())->from('users')->join('teams', $id, '=', 'b'),
            'joinSecond' => static fn (string $id): Query => (new Query())->from('users')->join('teams', 'a', '=', $id),
            'insertColumn' => static fn (string $id): Query => (new Query())->from('users')->insert([$id => 1]),
            'updateColumn' => static fn (string $id): Query => (new Query())->from('users')->update([$id => 1]),
        ];

        $cases = [];

        foreach ($entryPoints as $where => $build) {
            foreach ($unsafe as $identifier) {
                $cases[] = [$where, $build, $identifier];
            }
        }

        return $cases;
    }

    /**
     * @param list<string> $operators
     */
    #[DataProvider('knownOperators')]
    public function testItAcceptsKnownOperators(string $operator, string $expected): void
    {
        $query = (new Query())->get('users')->where('age', 36, $operator);

        $this->assertSame('SELECT * FROM `users` WHERE `age` ' . $expected . ' ?', $query->toSql());
    }

    /**
     * @return list<array{string, string}>
     */
    public static function knownOperators(): array
    {
        return [
            ['=', '='],
            ['!=', '!='],
            ['<>', '<>'],
            ['>', '>'],
            ['<', '<'],
            ['>=', '>='],
            ['<=', '<='],
            ['like', 'like'],
            ['not like', 'not like'],
            ['LIKE', 'like'],
            ['  >=  ', '>='],
        ];
    }

    /**
     * @param callable(string): Query $build
     */
    #[DataProvider('unsafeOperators')]
    public function testItRefusesUnknownOperators(callable $build, string $operator): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $build($operator);
    }

    /**
     * @return list<array{callable(string): Query, string}>
     */
    public static function unsafeOperators(): array
    {
        return [
            [static fn (string $op): Query => (new Query())->from('users')->where('age', 36, $op), '= 1 OR 1'],
            [static fn (string $op): Query => (new Query())->from('users')->where('age', 36, $op), '36'],
            [static fn (string $op): Query => (new Query())->from('users')->where('age', 36, $op), ''],
            [static fn (string $op): Query => (new Query())->from('users')->where('age', 36, $op), 'union'],
            [static fn (string $op): Query => (new Query())->from('users')->where('age', 36, $op), 'is'],
            [static fn (string $op): Query => (new Query())->from('users')->where('age', 36, $op), 'between'],
            [static fn (string $op): Query => (new Query())->from('users')->join('teams', 'a', $op, 'b'), '; DROP TABLE users'],
        ];
    }

    public function testItRefusesToRunAQueryWithNoConnection(): void
    {
        $this->expectException(\LogicException::class);

        (new Query())->get('users')->fetch();
    }

    // -------------------------------------------------------------- round trip

    public function testItRoundTripsRowsThroughARealDatabase(): void
    {
        $db = $this->db();

        $this->assertSame(1, $db->insert('users', ['name' => 'Ada', 'team_id' => 1, 'age' => 36]));
        $db->insert('users', ['name' => 'Grace', 'team_id' => 1, 'age' => 45]);
        $db->insert('users', ['name' => 'Alan', 'team_id' => 2, 'age' => 41]);

        $this->assertSame([
            ['id' => 1, 'name' => 'Ada', 'team_id' => 1, 'age' => 36],
            ['id' => 2, 'name' => 'Grace', 'team_id' => 1, 'age' => 45],
            ['id' => 3, 'name' => 'Alan', 'team_id' => 2, 'age' => 41],
        ], $db->select('users'));

        $this->assertSame(
            [['id' => 2, 'name' => 'Grace', 'team_id' => 1, 'age' => 45]],
            $db->select('users', static fn (Query $q) => $q->where('age', 40, '>')->where('team_id', 1))
        );

        $this->assertSame(
            ['id' => 1, 'name' => 'Ada', 'team_id' => 1, 'age' => 36],
            $db->first('users', static fn (Query $q) => $q->orderBy('age'))
        );

        $this->assertSame(1, $db->update('users', ['age' => 37], static fn (Query $q) => $q->where('name', 'Ada')));

        $this->assertSame(1, $db->delete('users', static fn (Query $q) => $q->where('name', 'Grace')));

        $this->assertSame(
            [
                ['id' => 1, 'name' => 'Ada', 'team_id' => 1, 'age' => 37],
                ['id' => 3, 'name' => 'Alan', 'team_id' => 2, 'age' => 41],
            ],
            $db->select('users')
        );
    }

    public function testItRoundTripsJoins(): void
    {
        $db = $this->db();

        $db->insert('teams', ['name' => 'Analytical']);
        $db->insert('users', ['name' => 'Ada', 'team_id' => 1, 'age' => 36]);

        $this->assertSame(
            [['id' => 1, 'name' => 'Ada', 'team_id' => 1, 'age' => 36]],
            $db->table('users')
                ->select('users.id', 'users.name', 'users.team_id', 'users.age')
                ->join('teams', 'users.team_id', '=', 'teams.id')
                ->where('teams.name', 'Analytical')
                ->fetch()
        );

        $this->assertSame(
            [],
            $db->table('users')
                ->join('teams', 'users.team_id', '=', 'teams.id')
                ->where('teams.name', 'Nonexistent')
                ->fetch()
        );
    }

    public function testItBindsItsLimitAndOffsetAgainstARealDatabase(): void
    {
        $db = $this->db();

        $db->insert('users', ['name' => 'Ada', 'age' => 36]);
        $db->insert('users', ['name' => 'Grace', 'age' => 45]);
        $db->insert('users', ['name' => 'Alan', 'age' => 41]);

        $this->assertSame(
            [['name' => 'Ada']],
            $db->select('users', static fn (Query $q) => $q->select('name')->orderBy('age')->limit(1))
        );

        $this->assertSame(
            [['name' => 'Grace']],
            $db->select('users', static fn (Query $q) => $q->select('name')->orderBy('age', 'desc')->limit(1))
        );

        $this->assertSame(
            [['name' => 'Alan'], ['name' => 'Grace']],
            $db->select('users', static fn (Query $q) => $q->select('name')->orderBy('age')->limit(null)->offset(1))
        );
    }

    public function testAnInjectedValueMatchesNothingInsteadOfEverything(): void
    {
        $db = $this->db();

        $db->insert('users', ['name' => 'Ada', 'age' => 36]);
        $db->insert('users', ['name' => 'Grace', 'age' => 45]);

        $this->assertSame(
            [],
            $db->select('users', static fn (Query $q) => $q->where('name', self::NASTY))
        );
    }

    public function testFirstReturnsNullWhenNothingMatches(): void
    {
        $db = $this->db();

        $db->insert('users', ['name' => 'Ada', 'age' => 36]);

        $this->assertNull($db->first('users', static fn (Query $q) => $q->where('name', 'Nobody')));
    }

    public function testRawStillBindsItsValues(): void
    {
        $db = $this->db();

        $db->insert('users', ['name' => self::NASTY, 'age' => 36]);

        $this->assertSame(
            [['n' => 1]],
            $db->raw('SELECT COUNT(*) AS n FROM users WHERE name = ?', [self::NASTY])
        );
    }

    public function testItReportsTheLastInsertId(): void
    {
        $db = $this->db();

        $db->insert('users', ['name' => 'Ada', 'age' => 36]);
        $db->insert('users', ['name' => 'Grace', 'age' => 45]);

        $this->assertSame('2', $db->connection()->lastInsertId());
    }

    public function testItRollsBackAFailedTransaction(): void
    {
        $connection = $this->connection();

        try {
            $connection->transaction(static function (Connection $c): void {
                $c->execute('INSERT INTO users (name) VALUES (?)', ['Ada']);

                throw new \RuntimeException('no');
            });

            $this->fail('The transaction should have rethrown.');
        } catch (\RuntimeException $e) {
            $this->assertSame('no', $e->getMessage());
        }

        $this->assertSame([], (new Db($connection))->select('users'));
    }

    public function testItRecordsQueriesWhenAskedTo(): void
    {
        $connection = Connection::sqlite(':memory:', self::SCHEMA);
        $quiet = new Db($connection);

        $quiet->insert('users', ['name' => 'Ada']);

        $this->assertSame([], $connection->queries());

        $loud = new Db(new Connection($connection->pdo(), true));
        $loud->insert('users', ['name' => 'Grace']);

        $this->assertCount(1, $loud->connection()->queries());
        $this->assertSame('INSERT INTO `users` (`name`) VALUES (?)', $loud->connection()->queries()[0]['sql']);
        $this->assertSame(['Grace'], $loud->connection()->queries()[0]['bindings']);
    }

    private function connection(): Connection
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('This test needs the pdo_sqlite driver.');
        }

        return Connection::sqlite(':memory:', self::SCHEMA);
    }

    private function db(): Db
    {
        return new Db($this->connection());
    }
}