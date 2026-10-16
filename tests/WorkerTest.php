<?php

declare(strict_types=1);

namespace Empira\Tests;

use Closure;

use Empira\Application;
use Empira\Element;
use Empira\Http\Format;
use Empira\Http\Middleware;
use Empira\Http\Request;
use Empira\Http\Response;
use Empira\Http\Session;
use Empira\State\NullState;
use Empira\View\Layout;
use Empira\View\Region;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use PHPUnit\Framework\TestCase;

/**
 * One Application, many requests — the shape a long-running worker has.
 *
 * Under php-fpm every request got a fresh process, so nothing in the framework
 * had to be safe about being reused. Under a worker the Application is booted
 * once and handles thousands, and anything it holds between requests becomes
 * the next request's input. These tests drive two requests through one
 * instance, because that is the only way the bug is visible: a fresh process
 * per test would hide every one of them.
 *
 * The session is the sharp end. A session is process-wide state, so a worker
 * that does not close it between requests serves the next visitor the last
 * one's `$_SESSION` — which is a cross-user data leak, silent, with no error
 * and nothing in the log.
 */
final class WorkerTest extends TestCase
{
    private const SECRET = 'a-secret-that-is-long-enough';

    protected function setUp(): void
    {
        // Per test, not per request: `$_SESSION` survives between the requests a
        // test drives (see Session::detach()), so only a new test method should
        // find someone else's values in it.
        $_SESSION = [];
    }

    private function app(): Application
    {
        return new Application(self::SECRET, new NullState());
    }

    private function session(Application $app): Session
    {
        return $app->container()->get(Session::class);
    }

    // ------------------------------------------------------------- sessions

    public function testOneSessionServesEveryRequestAndIsResetBetweenThem(): void
    {
        $app = $this->app();

        $app->handle(new Request('GET', '/'));
        $first = $this->session($app);

        $app->handle(new Request('GET', '/'));
        $second = $this->session($app);

        // Deliberately the *same* object. `$app->auth(new SessionGuard($app->session(), …))`
        // captures the Session at boot, so a fresh one per request would leave
        // every guard reading a session nothing ever writes to. The instance is
        // shared; what has to be reset is its state, and `beginRequest()` is what
        // does that.
        self::assertSame($first, $second);
    }

    public function testAValueWrittenInOneRequestIsReadableInTheNext(): void
    {
        $app = $this->app();

        $app->handle(new Request('GET', '/'));
        $this->session($app)->put('user_id', 7);

        $app->handle(new Request('GET', '/'));

        // Under the CLI there is no session store to restore from, so `$_SESSION`
        // itself carries across. Two requests in one process must still see one
        // session — otherwise no test could exercise a login and its aftermath.
        self::assertSame(7, $this->session($app)->get('user_id'));
    }

    public function testBeginRequestIsANoOpUnderTheCli(): void
    {
        $_SESSION = ['empira' => ['user_id' => 7]];

        (new Session())->beginRequest();

        // The CLI has no session to close. Detaching there would throw away the
        // very state that stands in for one.
        self::assertSame(7, $_SESSION['empira']['user_id']);
    }

    // ---------------------------------------------------------------- flash

    public function testAFlashWrittenInOneRequestIsReadableInTheNext(): void
    {
        $app = $this->app();

        $app->handle(new Request('GET', '/'));
        $this->session($app)->flash('notice', 'Saved.');

        $app->handle(new Request('GET', '/'));

        self::assertSame(['notice' => 'Saved.'], $this->session($app)->flashed());
    }

    public function testAFlashIsReadableOnceAndThenAgesOut(): void
    {
        $app = $this->app();

        $app->handle(new Request('GET', '/'));
        $this->session($app)->flash('notice', 'Saved.');

        $app->handle(new Request('GET', '/'));
        self::assertSame(['notice' => 'Saved.'], $this->session($app)->flashed());

        // A message that survived a third request would sit in the banner until
        // the browser was closed. This is the half of rotation that only shows
        // up once something is already wrong.
        $app->handle(new Request('GET', '/'));
        self::assertSame([], $this->session($app)->flashed());
    }

    public function testAnUnrelatedReadDoesNotAgeAFlashBeforeItIsShown(): void
    {
        $app = $this->app();

        $app->handle(new Request('GET', '/'));
        $this->session($app)->flash('notice', 'Saved.');

        $app->handle(new Request('GET', '/'));

        // Any accessor goes through `begin()`. Without the once-per-instance latch
        // this would rotate the buckets a second time and throw away a message
        // that had been promoted but not yet read — which is exactly what a layout
        // that reads the session before rendering the banner would do.
        $this->session($app)->get('unrelated');

        self::assertSame(['notice' => 'Saved.'], $this->session($app)->flashed());
    }

    public function testReadingTheFlashBagConsumesIt(): void
    {
        $app = $this->app();

        $app->handle(new Request('GET', '/'));
        $this->session($app)->flash('notice', 'Saved.');

        $app->handle(new Request('GET', '/'));

        // Destructive on purpose: a banner that survived its own render would sit
        // there until the tab was closed.
        self::assertSame(['notice' => 'Saved.'], $this->session($app)->flashed());
        self::assertSame([], $this->session($app)->flashed());
    }

    // -------------------------------------------------------------- emitting

    public function testEmitWritesTheBodyWithoutEndingTheScript(): void
    {
        $response = Response::html('<p>hello</p>');

        ob_start();
        $response->emit();
        $body = ob_get_clean();

        // Reaching this line is the assertion. `send()` exits, so an exiting
        // emit would have taken the worker down and the loop with it.
        self::assertSame('<p>hello</p>', $body);
        self::assertSame(200, $response->status());
    }

    // --------------------------------------------------------- session close

    public function testARequestLeavesNoSessionOpen(): void
    {
        $app = $this->app();

        // Under the CLI the framework never opens a session, so asserting on
        // `session_status()` alone would pass whether or not anything closed it
        // — a test that proves nothing. Open one by hand instead: the save path
        // is the workspace `tmp/`, never the system temp directory.
        $savePath = ini_get('session.save_path');
        ini_set('session.save_path', Temp::root());

        try {
            session_start();
            $id = session_id();

            self::assertSame(PHP_SESSION_ACTIVE, session_status(), 'precondition');

            $app->handle(new Request('GET', '/'));

            // The whole point. Left open, the session file is not updated until
            // whichever request this worker serves *next* calls beginRequest() —
            // and at 32 workers there may not be a next one on this worker for a
            // while.
            self::assertSame(PHP_SESSION_NONE, session_status());
        } finally {
            // Closing writes the file; it does not remove it, and this suite
            // shares a process with everything else.
            if (is_string($id) && $id !== '') {
                @unlink(Temp::root() . '/sess_' . $id);
            }

            ini_set('session.save_path', $savePath === false ? '' : $savePath);
        }
    }

    public function testClosingAnUnopenedSessionIsHarmless(): void
    {
        $app = $this->app();

        // The common case: most requests never touch the session at all, so this
        // has to be free and, more importantly, not an error.
        $response = $app->handle(new Request('GET', '/'));

        self::assertSame(404, $response->status());
        self::assertSame(PHP_SESSION_NONE, session_status());
    }

    // ------------------------------------------------------------ connection

    public function testNothingPerRequestSurvivesOnTheWorkerBetweenRequests(): void
    {
        // This used to be about the statement log on a long-lived connection —
        // an array that grew for the life of the process unless something reset
        // it, which is the one leak that only shows up after enough requests to
        // matter. That log is gone with the hand-written data layer.
        //
        // The property did not go with it, though: a worker holds state that
        // would be reset each request under php-fpm and never reset here. The
        // ORM's is its identity map, and this is the same test pointed at it.
        $database = Temp::file('empira-worker-');

        try {
            $app = new Application(self::SECRET, new NullState());
            $app->orm(['driver' => 'sqlite', 'database' => $database]);

            $connection = $app->entities()->getConnection();

            (new \Doctrine\ORM\Tools\SchemaTool($app->entities()))->createSchema(
                [$app->entities()->getClassMetadata(\Empira\Tests\Fixtures\Person::class)]
            );

            $app->entities()->persist(new \Empira\Tests\Fixtures\Person('ada@example.com', 'Ada'));
            $app->entities()->flush();

            // Warm the worker, so it has genuinely held a user once.
            $app->handle(new Request('GET', '/'));
            $app->entities()->getRepository(\Empira\Tests\Fixtures\Person::class)->find(1);

            // Changed on another connection — a second process, an admin tool.
            $connection->executeStatement(
                "UPDATE people SET name = ? WHERE email = ?",
                ['Ada Lovelace', 'ada@example.com']
            );

            $app->handle(new Request('GET', '/'));

            self::assertSame(
                'Ada Lovelace',
                $app->entities()->getRepository(\Empira\Tests\Fixtures\Person::class)->find(1)?->name(),
                'the worker served this request from the last one\'s identity map'
            );
        } finally {
            Temp::remove($database);
        }
    }

    // ------------------------------------------------------------- middleware

    public function testClassStringMiddlewareIsRebuiltForEveryRequest(): void
    {
        NoteMiddleware::$ids = [];

        $app = $this->app();
        $app->use(NoteMiddleware::class);

        $app->handle(new Request('GET', '/'));
        $app->handle(new Request('GET', '/'));

        // The guarantee `use()` documents: a class-string goes back through the
        // container each request, so it cannot be holding request one's state.
        self::assertCount(2, NoteMiddleware::$ids);
        self::assertNotSame(NoteMiddleware::$ids[0], NoteMiddleware::$ids[1]);
    }

    public function testRebuiltMiddlewareSeesThisRequestsFormat(): void
    {
        NoteMiddleware::$formats = [];

        $app = $this->app();
        $app->use(NoteMiddleware::class);

        $app->handle(new Request('GET', '/', headers: ['Accept' => 'application/json']));

        // Proof that it was rebuilt *for this request* and not merely rebuilt
        // twice: only a fresh instance could have negotiated JSON.
        self::assertSame([Format::Json], NoteMiddleware::$formats);
    }

    public function testAContextDoesNotOutliveItsRequest(): void
    {
        $app = $this->app();

        $dispatched = $app->handle(new Request('GET', '/nothing-here'));
        $body = $dispatched->body();

        // Called between requests, as a console command or a queue worker would.
        // With the previous request's context still attached, `view()` hands back
        // that request's Response object and overwrites its body — a response that
        // has already been sent to a browser.
        $rendered = $app->view(BareLayout::class);

        self::assertNotSame($dispatched, $rendered);
        self::assertSame($body, $dispatched->body());
        self::assertSame(200, $rendered->status());
    }

    public function testAWorkerCanServeManyRequestsFromOneApplication(): void
    {
        $app = $this->app();

        for ($i = 0; $i < 25; $i++) {
            $response = $app->handle(new Request('GET', '/nothing-here'));

            self::assertSame(404, $response->status(), 'request ' . $i);
        }
    }
}

final class BareLayout extends Layout
{
    protected function regions(): array
    {
        return [
            'main' => fn(Region $r): Element => $r->element('div')->text('bare'),
        ];
    }
}

final class NoteMiddleware implements Middleware
{
    /** @var list<int> */
    public static array $ids = [];

    /** @var list<Format> */
    public static array $formats = [];

    public function __construct(private readonly Format $format)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        self::$ids[] = spl_object_id($this);
        self::$formats[] = $this->format;

        return $handler->handle($request);
    }
}
