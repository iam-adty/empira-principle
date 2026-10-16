<?php

declare(strict_types=1);

namespace Empira\Tests;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Empira\Application;
use Empira\Config\Config;
use Empira\Http\Request;
use Empira\State\NullState;
use Empira\Tests\Fixtures\Person;
use PHPUnit\Framework\TestCase;

/**
 * The EntityManager's life under a long-running worker.
 *
 * This is the property that matters most in the whole ORM change, and the one
 * nothing about the code's *shape* will tell you is right. Under php-fpm the
 * manager is created and destroyed with the request, so every mistake here is
 * invisible in development and appears in production at whatever rate two
 * requests happen to land on the same worker.
 *
 * So it is tested by doing exactly what a worker does: one Application, many
 * requests, with the database changed in between.
 */
final class OrmLifecycleTest extends TestCase
{
    private const SECRET = 'test-secret-that-is-long-enough-for-hmac!!';

    private EntityManagerInterface $entities;

    /** A real file, because `:memory:` gives each connection its own database. */
    private string $database = '';

    protected function setUp(): void
    {
        // A file rather than `:memory:` — and that is not tidiness. SQLite gives
        // every connection to `:memory:` a *separate* database, so the worker
        // below would be querying a different one from this fixture and finding
        // no `users` table at all. The point of the test is one process serving
        // many requests against one database, which needs a database that more
        // than one connection can reach.
        $this->database = Temp::file('empira-orm-lifecycle-');

        $this->entities = \Empira\Persistence\EntityManagerFactory::make(
            new Config(['driver' => 'sqlite', 'database' => $this->database]),
            [__DIR__ . '/Fixtures'],
        );

        (new SchemaTool($this->entities))->createSchema(
            [$this->entities->getClassMetadata(Person::class)]
        );

        $this->entities->persist(new Person('ada@example.com', 'Ada'));
        $this->entities->flush();
        $this->entities->clear();
    }

    protected function tearDown(): void
    {
        if ($this->database !== '') {
            Temp::remove($this->database);
        }
    }

    // ------------------------------------------------------------- wiring

    /**
     * `orm()` with no configuration reads `DB_DATABASE` from the environment.
     *
     * It used to return `[]`, because nothing in the framework binds a `Config`
     * to read — so `DatabaseConfig` supplied its defaults and the application
     * silently opened `:memory:`. Every query answered and the connection
     * worked, so the failure surfaced as a table that had been migrated into a
     * real file not being there.
     *
     * Asserted through `empira migrate`'s own reader rather than by checking the
     * connection parameters, because the property that matters is that the two
     * agree: an application and its migrations talking about different files is
     * the whole bug.
     */
    public function testOrmWithNoConfigurationReadsTheEnvironment(): void
    {
        $database = Temp::file('empira-orm-from-env-');

        $previous = $_ENV['DB_DATABASE'] ?? null;
        $_ENV['DB_DATABASE'] = $database;

        try {
            $app = new Application(self::SECRET, new NullState());
            $app->orm();

            $fromEnvironment = $app->entities()->getConnection()->getParams();

            $migrate = \Empira\Config\DatabaseConfig::fromEnvironment()->all();
        } finally {
            if ($previous === null) {
                unset($_ENV['DB_DATABASE']);
            } else {
                $_ENV['DB_DATABASE'] = $previous;
            }

            Temp::remove($database);
        }

        self::assertSame($database, $migrate['database']);
        self::assertSame(
            $migrate['database'],
            $fromEnvironment['path'] ?? $fromEnvironment['database'] ?? null,
            'orm() and empira migrate disagree about which database this is'
        );
    }

    /**
     * A `Config` with a `db` section still wins over the environment, which is
     * the case the old code was reaching for when it looked for one.
     */
    public function testABoundConfigWithADatabaseSectionStillWins(): void
    {
        $database = Temp::file('empira-orm-bound-config-');

        $previous = $_ENV['DB_DATABASE'] ?? null;
        $_ENV['DB_DATABASE'] = '/from/the/environment.sqlite';

        try {
            $app = new Application(self::SECRET, new NullState());
            $app->container()->instance(Config::class, new Config([
                'db' => ['driver' => 'sqlite', 'database' => $database],
            ]));
            $app->orm();

            $params = $app->entities()->getConnection()->getParams();
        } finally {
            if ($previous === null) {
                unset($_ENV['DB_DATABASE']);
            } else {
                $_ENV['DB_DATABASE'] = $previous;
            }

            Temp::remove($database);
        }

        self::assertSame($database, $params['path'] ?? $params['database'] ?? null);
    }

    /**
     * `orm($paths)` is a mistake the signature invites, and it used to be silent.
     *
     * Entity paths in the configuration argument means no `driver` and no
     * `database`, so `DatabaseConfig` supplies its defaults and the application
     * opens `:memory:` — connecting fine, answering every query, holding nothing.
     *
     * The one that shipped this: rewriting the skeleton's `App` to drop its
     * hand-built config array and leaving `$this->orm([__DIR__, $entityPath])`
     * behind. Everything booted. Every request worked. The `users` table was
     * simply not there.
     */
    public function testEntityPathsInTheConfigurationArgumentAreRefused(): void
    {
        $app = new Application(self::SECRET, new NullState());

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('takes the database configuration first');

        $app->orm([__DIR__ . '/Fixtures']);
    }

    /**
     * An empty array is a caller saying "no overrides", not a caller who got the
     * arguments the wrong way round — so it is not what the guard above refuses.
     */
    public function testAnEmptyConfigurationIsNotMistakenForMisplacedPaths(): void
    {
        $app = new Application(self::SECRET, new NullState());
        $app->orm([], [__DIR__ . '/Fixtures']);

        self::assertInstanceOf(EntityManagerInterface::class, $app->entities());
    }

    public function testOrmMakesTheEntityManagerAutowirable(): void
    {
        $app = new Application(self::SECRET, new NullState());
        $app->orm(['driver' => 'sqlite', 'database' => ':memory:']);

        self::assertInstanceOf(
            EntityManagerInterface::class,
            $app->container()->get(EntityManagerInterface::class)
        );

        // …and the accessor hands back the very same one, not a second manager.
        self::assertSame($app->container()->get(EntityManagerInterface::class), $app->entities());
    }

    public function testAskingForTheEntitiesWithoutOrmSaysWhatToDo(): void
    {
        $app = new Application(self::SECRET, new NullState());

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Call $app->orm() during boot');

        $app->entities();
    }

    public function testAnApplicationWithoutAnOrmStillHandlesRequests(): void
    {
        // The reset is conditional on the manager existing. An application that
        // never asked for a database must not start failing at the first
        // request because the framework decided it needed one.
        $app = new Application(self::SECRET, new NullState());
        $app->routes()->get('/', static fn(): string => 'ok');

        self::assertSame(200, $app->handle(new Request('GET', '/'))->status());
    }

    // --------------------------------------------------------- the reset

    public function testOneApplicationServingManyRequestsReadsFreshEachTime(): void
    {
        // Built over the *same* database this test wrote its fixture into, and
        // the manager is the one the framework hands to the provider — so the
        // provider is not re-created either. That is the real arrangement.
        $app = $this->worker();

        // Request one sees Ada.
        self::assertSame('Ada', $this->seenName($app));

        // Changed behind the worker's back, between the two requests.
        $this->entities->getConnection()->executeStatement(
            "UPDATE people SET name = ? WHERE email = ?",
            ['Ada Lovelace', 'ada@example.com']
        );

        // Request two, same process, must see the new value.
        self::assertSame(
            'Ada Lovelace',
            $this->seenName($app),
            'the second request was served from the first request\'s identity map'
        );
    }

    public function testADeletedUserStopsAuthenticatingOnTheNextRequest(): void
    {
        $app = $this->worker();

        self::assertSame('Ada', $this->seenName($app));

        $this->entities->getConnection()->executeStatement(
            'DELETE FROM people WHERE email = ?',
            ['ada@example.com']
        );

        // A stale identity map would still hand back the deleted user, which is
        // the worst shape this bug can take: the account is gone from the
        // database and the application still says hello.
        self::assertNull($this->seenName($app), 'a deleted user was still authenticated');
    }

    // ------------------------------------------------------------ helpers

    /**
     * An Application with a guard that reports the name of whoever is signed in.
     */
    private function worker(): Application
    {
        $app = new Application(self::SECRET, new NullState());

        $app->orm(['driver' => 'sqlite', 'database' => $this->database]);
        $app->auth(new class($app->entities()) implements \Empira\Security\Guard {
            public function __construct(private readonly EntityManagerInterface $entities)
            {
            }

            public function identify(Request $request): ?Person
            {
                return $this->entities->getRepository(Person::class)->findOneBy([]);
            }
        });

        return $app;
    }

    private function seenName(Application $app): ?string
    {
        $request = new Request('GET', '/');

        // Drive one full request, so the reset inside `handle()` is what is
        // being measured rather than a direct call to `clear()`.
        $app->handle($request);

        return $app->container()->get(\Empira\Security\RequestUser::class)->user()?->name();
    }
}