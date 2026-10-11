<?php

declare(strict_types=1);

namespace IamAdty\Empira\Tests;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use IamAdty\Empira\Auth\DoctrineUserProvider;
use IamAdty\Empira\Auth\User;
use IamAdty\Empira\Config\Config;
use IamAdty\Empira\Persistence\EntityManagerFactory;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * The persistence layer against a real PostgreSQL server.
 *
 * These used to hand raw SQL from the framework's own query builder to
 * PostgreSQL, because the grammar's claim — that every identifier the framework
 * emits is quoted the way the server wants — is only worth making if a server
 * agrees with it. That layer is gone; the claim is not.
 *
 * What replaced it is a different and larger claim: that Doctrine's schema,
 * its queries and its migrations work against this server, and that a `User`
 * written on SQLite is the same object on PostgreSQL. SQLite forgives a great
 * deal, so a suite that only ever runs on it is a suite that has not been
 * tested.
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
    private const HASH = '$2y$10$Ua89QG/6trEgtY0K1UKd2Ot6.giI.2..iM8F1dZqqW8K9/c/IDEma';

    private ?EntityManagerInterface $entities = null;

    protected function setUp(): void
    {
        $dsn = getenv('EMPIRA_TEST_PGSQL_DSN');

        if (!is_string($dsn) || $dsn === '') {
            $this->markTestSkipped('No PostgreSQL configured; set EMPIRA_TEST_PGSQL_DSN to run the driver tests.');
        }

        $this->entities = EntityManagerFactory::make(
            new Config(self::parseDsn($dsn) + [
                'username' => (string) getenv('EMPIRA_TEST_PGSQL_USER'),
                'password' => (string) getenv('EMPIRA_TEST_PGSQL_PASSWORD'),
            ]),
            [__DIR__ . '/../src/Auth'],
        );
    }

    /**
     * `pgsql:host=…;port=…;dbname=…` into the flat config shape.
     *
     * Both of the obvious alternatives are wrong. `DsnParser` returns
     * `dbname => 'host=…;port=…;dbname=…'` without splitting the parameters, and
     * DBAL 4 dropped `DriverManager`'s `url` key entirely — so this is the
     * remaining job, and doing it here keeps the framework's own reader free of
     * a DSN format only this test's environment uses.
     *
     * @return array<string, mixed>
     */
    private static function parseDsn(string $dsn): array
    {
        $scheme = 'pgsql';

        if (str_contains($dsn, ':')) {
            [$scheme, $rest] = explode(':', $dsn, 2);
        } else {
            $rest = $dsn;
        }

        $config = ['driver' => $scheme];

        foreach (array_filter(explode(';', $rest), static fn(string $part): bool => $part !== '') as $pair) {
            [$key, $value] = array_pad(explode('=', $pair, 2), 2, '');

            $config[match ($key) {
                'host' => 'host',
                'port' => 'port',
                'dbname' => 'database',
                default => $key,
            }] = $key === 'port' ? (int) $value : $value;
        }

        return $config;
    }

    /**
     * A schema built from the mapping, dropped and rebuilt per test.
     */
    private function schema(): EntityManagerInterface
    {
        $entities = $this->entities;
        self::assertNotNull($entities);

        $tool = new SchemaTool($entities);
        $metadata = [$entities->getClassMetadata(User::class)];

        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);

        return $entities;
    }

    public function testTheSchemaDoctrineGeneratesIsValidPostgres(): void
    {
        // The claim the deleted grammar tests used to make, made against the
        // server: hand it what the mapping produces and it must accept it.
        $entities = $this->schema();

        $user = new User('ada@example.com', 'Ada', self::HASH);
        $entities->persist($user);
        $entities->flush();

        self::assertNotNull($user->id(), 'a generated key came back');
    }

    public function testAnIdentityColumnComesBackAsAnInteger(): void
    {
        $entities = $this->schema();
        $users = new DoctrineUserProvider($entities);

        $entities->persist(new User('ada@example.com', 'Ada', self::HASH));
        $entities->flush();
        $entities->clear();

        // `find()` takes an int here but the interface allows int|string, and a
        // driver handing back "1" rather than 1 is the kind of difference that
        // only a real server shows.
        self::assertSame('Ada', $users->byId('1')?->name());
    }

    public function testTheJsonColumnRoundTrips(): void
    {
        // SQLite has no JSON type and will happily store anything, so a bag that
        // comes back wrong here means the column type is wrong on one of them.
        $entities = $this->schema();

        $entities->persist(new User('ada@example.com', 'Ada', self::HASH, ['role' => 'admin', 'seen' => 3]));
        $entities->flush();
        $entities->clear();

        $found = $entities->find(User::class, 1);

        self::assertNotNull($found);
        self::assertSame('admin', $found->attribute('role'));
        self::assertSame(3, $found->attribute('seen'), 'a JSON integer should not come back a string');
    }

    public function testTheUniqueIndexIsEnforcedByTheServerNotByTheApplication(): void
    {
        $entities = $this->schema();

        $entities->persist(new User('ada@example.com', 'Ada', self::HASH));
        $entities->flush();

        $entities->persist(new User('ada@example.com', 'Someone Else', self::HASH));

        // This is the whole argument for writing through the ORM rather than
        // checking first: two concurrent registrations both pass a check-then-
        // write, and only the database stops the second one.
        //
        // DBAL's exception, not the ORM's: ORM 3 has no
        // `EntityUniqueConstraintViolationException`, and a `catch` naming a
        // class that does not exist never matches — so this would have read as
        // "no violation happened" and the caller would have seen a 500.
        $this->expectException(UniqueConstraintViolationException::class);

        $entities->flush();
    }

    public function testClearingTheManagerReadsThroughToTheServer(): void
    {
        $entities = $this->schema();
        $users = new DoctrineUserProvider($entities);

        $entities->persist(new User('ada@example.com', 'Ada', self::HASH));
        $entities->flush();

        $entities->getConnection()->executeStatement(
            "UPDATE users SET name = ? WHERE email = ?",
            ['Ada Lovelace', 'ada@example.com']
        );

        self::assertSame('Ada', $users->byId(1)?->name(), 'the identity map serves the cached entity');

        $entities->clear();

        self::assertSame(
            'Ada Lovelace',
            $users->byId(1)?->name(),
            'a cleared manager must read through to the server'
        );
    }
}