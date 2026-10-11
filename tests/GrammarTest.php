<?php

declare(strict_types=1);

namespace IamAdty\Empira\Tests;

use IamAdty\Empira\Data\AbstractGrammar;
use IamAdty\Empira\Data\Connection;
use IamAdty\Empira\Data\Grammars\MySqlGrammar;
use IamAdty\Empira\Data\Grammars\PostgresGrammar;
use IamAdty\Empira\Data\Grammars\SqliteGrammar;
use IamAdty\Empira\Data\Grammar;
use IamAdty\Empira\Data\Query;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The driver seam.
 *
 * Almost all SQL is portable; how an identifier is quoted is not. These tests
 * pin the two halves of that: the quoting differs per dialect, and the
 * validation does not — every grammar has to reject the same things, because
 * that check is a security boundary rather than a convenience.
 */
final class GrammarTest extends TestCase
{
    /**
     * @return iterable<string, array{Grammar, string}>
     */
    public static function dialects(): iterable
    {
        yield 'sqlite' => [new SqliteGrammar(), '`'];
        yield 'mysql' => [new MySqlGrammar(), '`'];
        yield 'postgres' => [new PostgresGrammar(), '"'];
    }

    public function testEachDialectQuotesAnIdentifierWithItsOwnCharacter(): void
    {
        $this->assertSame('`users`', (new SqliteGrammar())->quoteIdentifier('users'));
        $this->assertSame('`users`', (new MySqlGrammar())->quoteIdentifier('users'));
        $this->assertSame('"users"', (new PostgresGrammar())->quoteIdentifier('users'));
    }

    public function testAQualifiedIdentifierIsQuotedSegmentBySegment(): void
    {
        // Not `users.name` as one string — a dot inside quotes is a syntax error
        // on every database, and a classic way to smuggle a column past a check.
        $this->assertSame('"users"."name"', (new PostgresGrammar())->quoteIdentifier('users.name'));
    }

    public function testASegmentPatternIsQuotedSegmentBySegment(): void
    {
        $this->assertSame('"u".*', (new PostgresGrammar())->quoteIdentifier('u.*'));
    }

    public function testAStarIsLeftAlone(): void
    {
        $this->assertSame('*', (new PostgresGrammar())->quoteIdentifier('*'));
    }

    public function testEachDialectKnowsItsDriver(): void
    {
        $this->assertSame('sqlite', (new SqliteGrammar())->driver());
        $this->assertSame('mysql', (new MySqlGrammar())->driver());
        $this->assertSame('pgsql', (new PostgresGrammar())->driver());
    }

    /**
     * The load-bearing test: every dialect refuses exactly the same things.
     *
     * A grammar that loosened the check would be an injection hole, and a
     * per-grammar assertion is the only way to notice one appearing.
     */
    #[DataProvider('unsafeIdentifiers')]
    public function testEveryDialectRefusesAnUnsafeIdentifier(string $identifier): void
    {
        foreach (self::dialects() as [$grammar, $_]) {
            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessage('Unsafe SQL identifier');

            $grammar->quoteIdentifier($identifier);
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unsafeIdentifiers(): iterable
    {
        yield 'semicolon' => ['users; DROP TABLE users'];
        yield 'space' => ['user name'];
        yield 'backtick break-out' => ['users` WHERE 1=1 --'];
        yield 'double-quote break-out' => ['users" WHERE 1=1 --'];
        yield 'comment marker' => ['users--'];
        yield 'leading digit' => ['1users'];
        yield 'empty' => [''];
        yield 'bare segment' => ['users.'];
        yield 'quote in the middle' => ['us"ers'];
    }

    public function testTheValidationIsDefinedOnceSoADialectCannotWeakenIt(): void
    {
        // The point of AbstractGrammar: the check is inherited, not copied, so
        // "Postgres forgot the second pass" is not a state this design allows.
        $this->assertTrue((new \ReflectionClass(PostgresGrammar::class))->isSubclassOf(AbstractGrammar::class));

        $method = new \ReflectionMethod(PostgresGrammar::class, 'quoteIdentifier');
        $this->assertSame(
            AbstractGrammar::class,
            $method->getDeclaringClass()->getName(),
            'a dialect must inherit the check rather than reimplement it'
        );
    }

    public function testAConnectionTakesItsGrammarFromItsDriver(): void
    {
        $connection = Connection::sqlite(':memory:');

        $this->assertInstanceOf(SqliteGrammar::class, $connection->grammar());
    }

    public function testAnUnknownDriverFallsBackToSqlite(): void
    {
        // An unconnected Query has to resolve to something, or every SQL-only
        // test would need a database to agree on a dialect.
        $this->assertInstanceOf(SqliteGrammar::class, Connection::grammarFor('something-else'));
    }

    public function testMariaDBIsSpokenForByTheMysqlDialect(): void
    {
        $this->assertInstanceOf(MySqlGrammar::class, Connection::grammarFor('mariadb'));
    }

    public function testAnUnconnectedBuilderBehavesAsSqlite(): void
    {
        // This is the guarantee that keeps the SQL-only tests honest.
        $sql = (new Query())->get('users')->toSql();

        $this->assertSame('SELECT * FROM `users`', $sql);
    }

    public function testABuilderUsesItsConnectionDialect(): void
    {
        $builder = new Query(null, new PostgresGrammar());

        $this->assertSame('SELECT * FROM "users"', $builder->get('users')->toSql());
    }

    public function testAnExplicitGrammarOverridesTheConnections(): void
    {
        $builder = new Query(Connection::sqlite(':memory:'), new PostgresGrammar());

        $this->assertSame('SELECT * FROM "users"', $builder->get('users')->toSql());
    }

    public function testTheBuilderRefusesAnUnsafeIdentifierInEveryDialect(): void
    {
        foreach ([new SqliteGrammar(), new MySqlGrammar(), new PostgresGrammar()] as $grammar) {
            $this->expectException(\InvalidArgumentException::class);

            (new Query(null, $grammar))->get('users')->where('name = 1 OR 1', 'x')->toSql();
        }
    }
}
