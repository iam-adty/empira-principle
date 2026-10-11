<?php

declare(strict_types=1);

namespace IamAdty\Empira\Tests;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use IamAdty\Empira\Application;
use IamAdty\Empira\Auth\DoctrineUserProvider;
use IamAdty\Empira\Auth\User;
use IamAdty\Empira\Config\Config;
use IamAdty\Empira\Http\Request;
use IamAdty\Empira\State\NullState;
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

    private const HASH = '$2y$10$Ua89QG/6trEgtY0K1UKd2Ot6.giI.2..iM8F1dZqqW8K9/c/IDEma';

    private EntityManagerInterface $entities;

    private DoctrineUserProvider $users;

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

        $this->entities = \IamAdty\Empira\Persistence\EntityManagerFactory::make(
            new Config(['driver' => 'sqlite', 'database' => $this->database]),
            [__DIR__ . '/../src/Auth'],
        );

        (new SchemaTool($this->entities))->createSchema(
            [$this->entities->getClassMetadata(User::class)]
        );

        $this->users = new DoctrineUserProvider($this->entities);

        $this->entities->persist(new User('ada@example.com', 'Ada', self::HASH));
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
            "UPDATE users SET name = ? WHERE email = ?",
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
            'DELETE FROM users WHERE email = ?',
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
        $app->auth(new class($app->entities()) implements \IamAdty\Empira\Auth\Guard {
            public function __construct(private readonly EntityManagerInterface $entities)
            {
            }

            public function identify(Request $request): ?User
            {
                return $this->entities->getRepository(User::class)->findOneBy([]);
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

        return $app->container()->get(\IamAdty\Empira\Auth\Auth::class)->user()?->name();
    }
}