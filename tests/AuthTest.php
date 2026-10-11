<?php

declare(strict_types=1);

namespace IamAdty\Empira\Tests;

use IamAdty\Empira\Auth\Auth;
use IamAdty\Empira\Auth\BearerGuard;
use IamAdty\Empira\Auth\DatabaseUserProvider;
use IamAdty\Empira\Auth\Guard;
use IamAdty\Empira\Auth\Middleware\RequireAuth;
use IamAdty\Empira\Auth\SessionGuard;
use IamAdty\Empira\Auth\User;
use IamAdty\Empira\Auth\UserProvider;
use IamAdty\Empira\Application;
use IamAdty\Empira\Data\Connection;
use IamAdty\Empira\Data\Db;
use IamAdty\Empira\Http\Format;
use IamAdty\Empira\Http\Middleware\VerifyCsrfToken;
use IamAdty\Empira\Http\Request;
use IamAdty\Empira\Http\Response;
use PHPUnit\Framework\TestCase;

/**
 * Who is asking, and what that changes about the request.
 */
final class AuthTest extends TestCase
{
    private const SECRET = 'a-secret-that-is-long-enough';

    protected function setUp(): void
    {
        $_SESSION = [];
    }

    private function app(): Application
    {
        return new Application(self::SECRET, new \IamAdty\Empira\State\NullState());
    }

    private function request(string $method = 'GET', string $path = '/', array $headers = []): Request
    {
        return new Request($method, $path, headers: $headers);
    }

    // ------------------------------------------------------------- guards

    public function testABearerGuardReadsTheAuthorizationHeader(): void
    {
        $ada = new User(1, ['name' => 'Ada']);
        $guard = new BearerGuard(fn(string $token): ?User => $token === 'secret' ? $ada : null);

        $this->assertSame($ada, $guard->identify($this->request(headers: ['authorization' => 'Bearer secret'])));
    }

    public function testTheBearerSchemeIsCaseInsensitive(): void
    {
        // RFC 7235 defines the scheme case-insensitively and clients send it
        // however they like.
        $guard = new BearerGuard(fn(string $t): ?User => $t === 'secret' ? new User(1) : null);

        $this->assertNotNull($guard->identify($this->request(headers: ['authorization' => 'bearer secret'])));
        $this->assertNotNull($guard->identify($this->request(headers: ['authorization' => 'BEARER secret'])));
    }

    public function testABearerGuardIgnoresAnythingThatIsNotItsScheme(): void
    {
        $guard = new BearerGuard(fn(string $t): ?User => new User(1));

        $this->assertNull($guard->identify($this->request(headers: ['authorization' => 'Basic c3RhbGU6'])));
        $this->assertNull($guard->identify($this->request(headers: ['authorization' => 'Bearer'])));
        $this->assertNull($guard->identify($this->request(headers: ['authorization' => 'Bearer    '])));
        $this->assertNull($guard->identify($this->request()));
    }

    public function testABearerGuardReturnsNullForATokenItDoesNotKnow(): void
    {
        // Returning a user for an unrecognised credential would be a way to
        // authenticate anyone.
        $guard = new BearerGuard(fn(string $t): ?User => null);

        $this->assertNull($guard->identify($this->request(headers: ['authorization' => 'Bearer nope'])));
    }

    public function testAnUnrecognisedTokenIsNotTheSameAsNoHeader(): void
    {
        $app = $this->app();
        $guard = new BearerGuard(fn(string $t): ?User => null);
        $auth = new Auth([$guard], $this->request(headers: ['authorization' => 'Bearer wrong']));

        $this->assertTrue($auth->guest(), 'a wrong token is not a signed-in user');
    }

    // ------------------------------------------------------------- Auth

    public function testAuthResolvesTheFirstGuardThatAnswers(): void
    {
        $ada = new User(1);
        $auth = new Auth([
            new BearerGuard(fn(string $t): ?User => $t === 'bearer' ? new User(2) : null),
            new BearerGuard(fn(string $t): ?User => $t === 'bearer' ? $ada : null),
        ], $this->request(headers: ['authorization' => 'Bearer bearer']));

        $this->assertSame(2, $auth->id());
    }

    public function testAuthAsksItsGuardsOnlyOnce(): void
    {
        // A page that asks in a layout and again in a controller should not run
        // the guards twice; some of them hash or hit a session file.
        $calls = 0;
        $guard = new class ($calls) implements Guard {
            public function __construct(private int &$calls) {}

            public function identify(Request $request): ?User
            {
                $this->calls++;

                return new User(1);
            }
        };

        $auth = new Auth([$guard], $this->request());
        $auth->user();
        $auth->user();
        $auth->check();

        $this->assertSame(1, $calls);
    }

    public function testAuthWithoutARequestFindsNobody(): void
    {
        // A console command has no request, and must not be handed a user.
        $auth = new Auth([new BearerGuard(fn(string $t): ?User => new User(1))]);

        $this->assertTrue($auth->guest());
    }

    public function testAuthRemembersWhichGuardAnswered(): void
    {
        $auth = new Auth([
            new BearerGuard(fn(string $t): ?User => new User(9)),
        ], $this->request(headers: ['authorization' => 'Bearer x']));

        $this->assertInstanceOf(BearerGuard::class, $auth->guard());
        $this->assertTrue($auth->authenticatedByBearer());
    }

    // ------------------------------------------------------ RequireAuth

    public function testRequireAuthRefusesAnAnonymousRequest(): void
    {
        $app = $this->app();
        $app->routes()->get('/secret', static fn(): string => 'shh', 'secret');
        $app->use(RequireAuth::class);

        $this->assertSame(401, $app->handle($this->request(path: '/secret'))->status());
    }

    public function testRequireAuthLetsAnIdentifiedRequestThrough(): void
    {
        $app = $this->app();
        $app->routes()->get('/secret', static fn(): string => 'shh', 'secret');
        $app->auth(new BearerGuard(fn(string $t): ?User => new User(1)));
        $app->use(RequireAuth::class);

        $response = $app->handle($this->request(headers: ['authorization' => 'Bearer x'], path: '/secret'));

        $this->assertSame(200, $response->status());
        $this->assertSame('shh', $response->body());
    }

    public function testRequireAuthRefusesInTheNegotiatedFormat(): void
    {
        // The point of throwing rather than returning: a JSON client gets the
        // same envelope every other failure uses, without this class knowing
        // what that envelope looks like.
        $app = $this->app();
        $app->routes()->get('/secret', static fn(): string => 'shh', 'secret');
        $app->use(RequireAuth::class);

        $response = $app->handle($this->request(headers: ['accept' => 'application/json']));

        $this->assertSame(401, $response->status());
        $this->assertStringContainsString('"error"', $response->body());
    }

    public function testRequireAuthWithoutAnyAuthConfiguredRefuses(): void
    {
        // Fail closed: a middleware with no guards must not wave requests through.
        $app = $this->app();
        $app->routes()->get('/secret', static fn(): string => 'shh', 'secret');
        $app->use(new RequireAuth());

        $this->assertSame(401, $app->handle($this->request(path: '/secret'))->status());
    }

    public function testAuthIsBoundFreshForEveryRequest(): void
    {
        // The reason Auth is per-request: an Application outlives a request, and
        // a user resolved for one must not be visible to the next.
        $app = $this->app();
        $app->routes()->get('/who', static function (Auth $auth): Response {
            return Response::html((string) ($auth->id() ?? 'nobody'));
        }, 'who');
        $app->auth(new BearerGuard(fn(string $t): ?User => $t === 'ada' ? new User(1) : null));

        $first = $app->handle($this->request(headers: ['authorization' => 'Bearer ada'], path: '/who'));
        $second = $app->handle($this->request(path: '/who'));

        $this->assertSame('1', $first->body());
        $this->assertSame('nobody', $second->body(), 'identity must not survive the request');
    }

    public function testASessionUserIsCarriedAcrossRequestsButNotBeyondItsOwn(): void
    {
        $app = $this->app();
        $app->auth(new SessionGuard($app->session(), $this->users()));
        $app->routes()->get('/who', static fn(Auth $auth): Response => Response::html((string) ($auth->id() ?? 'nobody')), 'who');

        $this->assertSame('nobody', $app->handle($this->request(path: '/who'))->body());

        // Auth is bound per request, so signing in means asking the instance
        // this request produced.
        $app->handle($this->request(path: '/who'));
        $app->container()->get(Auth::class)->login(new User(7, ['name' => 'Ada']));

        $this->assertSame('7', $app->handle($this->request(path: '/who'))->body());
    }

    public function testLogoutForgetsTheUser(): void
    {
        $app = $this->app();
        $app->auth(new SessionGuard($app->session(), $this->users()));
        $app->routes()->get('/who', static fn(Auth $auth): Response => Response::html((string) ($auth->id() ?? 'nobody')), 'who');

        $app->handle($this->request(path: '/who'));
        $app->container()->get(Auth::class)->login(new User(7));
        $this->assertSame('7', $app->handle($this->request(path: '/who'))->body());

        $app->container()->get(Auth::class)->logout();
        $this->assertSame('nobody', $app->handle($this->request(path: '/who'))->body());
    }

    public function testLoggingInRotatesTheSessionId(): void
    {
        // Session::regenerate() is a no-op under the CLI, so watching the id
        // change would prove nothing. This asserts the rotation is *asked for*,
        // which is the part under our control and the part that would otherwise
        // go untested.
        $rotator = new class implements \IamAdty\Empira\Http\SessionId {
            public int $rotated = 0;

            public function regenerate(): void
            {
                $this->rotated++;
            }
        };

        $session = new \IamAdty\Empira\Http\Session('auth-test', false);
        $guard = new SessionGuard($session, $this->users(), rotator: $rotator);

        $this->assertSame(0, $rotator->rotated);

        $guard->login(new User(7));

        $this->assertSame(1, $rotator->rotated, 'login must rotate, or the id is fixated');
        $this->assertSame(7, $session->get('user_id'));
    }

    public function testLoggingInWithoutASessionGuardSaysSo(): void
    {
        $auth = new Auth([new BearerGuard(fn(string $t): ?User => new User(1))]);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('No session guard');

        $auth->login(new User(1));
    }

    // ---------------------------------------------------------------- CSRF

    public function testABearerAuthenticatedPostIsExemptFromCsrf(): void
    {
        $app = $this->guardedApp(new BearerGuard(fn(string $t): ?User => new User(1)));

        $response = $app->handle($this->request('POST', '/api', ['authorization' => 'Bearer x']));

        $this->assertSame(200, $response->status(), 'no CSRF token, but not forgeable either');
    }

    public function testASessionAuthenticatedPostWithoutATokenIsStillRefused(): void
    {
        // This is the attack. "There is a user" is not the question — an
        // ambient cookie is exactly what CSRF exists to defend, so a
        // session-authenticated POST with no token has to stay refused.
        $app = $this->app();
        $app->csrf(new \IamAdty\Empira\Security\Csrf());
        $app->routes()->post('/api', static fn(): string => 'ok', 'api');
        $app->auth(new SessionGuard($app->session(), $this->users()));
        $app->use(VerifyCsrfToken::class);

        // Sign in on one request…
        $app->handle($this->request());
        $app->container()->get(Auth::class)->login(new User(7));

        // …then post with no token on the next.
        $this->assertSame(419, $app->handle($this->request('POST', '/api'))->status());
    }

    public function testARouteCanOptOutOfCsrfEntirely(): void
    {
        $app = $this->app();
        $app->routes()->post('/webhook', static fn(): string => 'ok', 'webhook', csrf: false);
        $app->use(VerifyCsrfToken::class);

        $this->assertSame(200, $app->handle($this->request('POST', '/webhook'))->status());
    }

    public function testARouteThatDidNotOptOutIsStillChecked(): void
    {
        $app = $this->app();
        $app->routes()->post('/ordinary', static fn(): string => 'ok', 'ordinary');
        $app->use(VerifyCsrfToken::class);

        $this->assertSame(419, $app->handle($this->request('POST', '/ordinary'))->status());
    }

    // -------------------------------------------------- database provider

    public function testTheDatabaseProviderFindsUsersByIdAndCredential(): void
    {
        $users = $this->databaseUsers();

        $this->assertSame('Ada', $users->byId(1)?->attribute('name'));
        $this->assertSame('Ada', $users->byCredential('ada@example.com')?->attribute('name'));
        $this->assertNull($users->byId(99));
        $this->assertNull($users->byCredential('nobody@example.com'));
    }

    public function testTheDatabaseProviderNeverHandsOutAPasswordHash(): void
    {
        $user = $this->databaseUsers()->byId(1);

        $this->assertNotNull($user);
        $this->assertArrayNotHasKey('password_hash', $user->attributes);
        $this->assertSame('ada@example.com', $user->attribute('email'));
    }

    public function testTheDatabaseProviderVerifiesPasswords(): void
    {
        $users = $this->databaseUsers();
        $ada = $users->byId(1);

        $this->assertNotNull($ada);
        $this->assertTrue($users->verifyPassword($ada, 'correct horse'));
        $this->assertFalse($users->verifyPassword($ada, 'wrong horse'));
        $this->assertFalse($users->verifyPassword($ada, ''));
    }

    public function testAUserWithNoStoredHashCannotBeAuthenticated(): void
    {
        $users = $this->databaseUsers();
        $ghost = $users->byId(2);

        $this->assertNotNull($ghost);
        $this->assertFalse($users->verifyPassword($ghost, 'anything'), 'a missing hash is not a wildcard');
    }

    // ----------------------------------------------------------- helpers

    private function users(): UserProvider
    {
        return new class implements UserProvider {
            public function byId(int|string $id): ?User
            {
                return (string) $id === '7' ? new User(7, ['name' => 'Ada']) : null;
            }

            public function byCredential(string $credential): ?User
            {
                return null;
            }

            public function verifyPassword(User $user, string $password): bool
            {
                return false;
            }
        };
    }

    private function databaseUsers(): DatabaseUserProvider
    {
        $connection = Connection::sqlite(':memory:');
        $connection->execute('CREATE TABLE users (
            id INTEGER PRIMARY KEY,
            email TEXT NOT NULL,
            name TEXT NOT NULL,
            password_hash TEXT
        )');

        $db = new Db($connection);
        $users = new DatabaseUserProvider($db);

        // Db::insert() rather than Query::insert(): the latter only records the
        // write and waits for a terminal call, which is a confusing way to seed
        // a fixture.
        $db->insert('users', [
            'id' => 1,
            'email' => 'ada@example.com',
            'name' => 'Ada',
            'password_hash' => $users->hash('correct horse'),
        ]);

        // Deliberately has no hash at all.
        $db->insert('users', ['id' => 2, 'email' => 'ghost@example.com', 'name' => 'Ghost']);

        return $users;
    }

    private function guardedApp(Guard $guard): Application
    {
        $app = $this->app();
        $app->csrf(new \IamAdty\Empira\Security\Csrf());
        $app->routes()->post('/api', static fn(): string => 'ok', 'api');
        $app->auth($guard);
        $app->use(VerifyCsrfToken::class);

        return $app;
    }
}
