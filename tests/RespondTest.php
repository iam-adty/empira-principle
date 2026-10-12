<?php

declare(strict_types=1);

namespace Empira\Tests;

use Empira\Application;
use Empira\Http\Request;
use Empira\Tests\Fixtures\MemoryStore;
use PHPUnit\Framework\TestCase;

/**
 * The two runtime entry points.
 *
 * `send()` and `respond()` differ in exactly one thing — whether the script
 * ends — and that difference is the whole reason there are two. A classic
 * runtime has nothing to come back to and should exit. A worker must not: an
 * `exit` takes the process down, FrankenPHP restarts it, and it serves exactly
 * one request, which is correct and entirely without the point.
 *
 * ## What a failure here looks like, which is not what you would guess
 *
 * Putting an `exit` back into `respond()` does not fail a test. It *ends* the
 * run: PHPUnit stops mid-suite, prints no summary, and **exits 0** — measured,
 * not assumed. So a gate watching the exit status would report success having
 * run three of the seven tests in this file.
 *
 * That is a property of `exit` and PHPUnit rather than of this code, and it
 * applies to any exit anywhere in the suite. The mitigation for now is
 * documented in AGENTS.md under known traps: read the summary line, not the
 * exit code. Making `tools/test` enforce it is worth doing and is not done.
 */
final class RespondTest extends TestCase
{
    private const SECRET = 'a-secret-that-is-long-enough';

    private function app(): Application
    {
        $app = new Application(self::SECRET, new MemoryStore());
        $app->fallback(fn(Request $request): string => 'page for ' . $request->path());

        return $app;
    }

    // ------------------------------------------------------------- respond()

    public function testRespondEmitsTheResponseBody(): void
    {
        $app = $this->app();

        ob_start();
        $app->respond(new Request('GET', '/about'));
        $body = ob_get_clean();

        self::assertSame('page for /about', $body);
    }

    public function testRespondReturnsInsteadOfExiting(): void
    {
        $app = $this->app();

        ob_start();
        $app->respond(new Request('GET', '/about'));
        echo 'REACHED';
        $body = ob_get_clean();

        // The `REACHED` is the assertion. A `respond()` that exited would never
        // emit it, and a test that only checked the response body would pass
        // either way — which is how an `exit` here would survive the suite and
        // only show up as a worker restarting once per request.
        self::assertSame('page for /aboutREACHED', $body);
    }

    public function testTheTwoEntryPointsDifferOnlyInWhetherTheyEndTheScript(): void
    {
        $send = (new \ReflectionMethod(Application::class, 'send'))->getReturnType();
        $respond = (new \ReflectionMethod(Application::class, 'respond'))->getReturnType();

        self::assertNotNull($send);
        self::assertNotNull($respond);

        // `never` is the compiler's statement that nothing follows, so `send()`
        // may exit and `respond()` must not claim it does.
        self::assertSame('never', (string) $send);
        self::assertSame('void', (string) $respond);
    }

    public function testRespondAcceptsAnExplicitRequest(): void
    {
        // Which is what keeps it drivable: no superglobals, no running server.
        $app = $this->app();

        ob_start();
        $app->respond(new Request('GET', '/somewhere'));
        $body = ob_get_clean();

        self::assertSame('page for /somewhere', $body);
    }

    public function testRespondHandlesTheRequestBeforeEmittingIt(): void
    {
        $app = $this->app();

        ob_start();
        $app->respond(new Request('GET', '/one'));
        $first = ob_get_clean();

        ob_start();
        $app->respond(new Request('GET', '/two'));
        $second = ob_get_clean();

        self::assertSame('page for /one', $first);
        self::assertSame('page for /two', $second);
    }

    // ----------------------------------------------------- error policy

    public function testAThrowableFromHandlingPropagatesOutOfRespond(): void
    {
        $app = new Application(self::SECRET, new MemoryStore());

        // A handler that throws something the framework does not recognise. It is
        // re-thrown by design — a bug should fail loudly rather than be dressed
        // up as a tidy 500 — and a worker has to catch it itself, because
        // `set_exception_handler` only fires when the script ends, and in a
        // worker it never does. Swallowing it here would hide exactly the thing
        // the worker handler exists to report.
        $app->fallback(function (): string {
            throw new \LogicException('a real bug');
        });

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('a real bug');

        ob_start();
        try {
            $app->respond(new Request('GET', '/boom'));
        } finally {
            ob_end_clean();
        }
    }

    public function testAFallbackReturningAResponseIsEmittedNotSent(): void
    {
        $app = new Application(self::SECRET, new MemoryStore());
        $app->fallback(fn(): \Empira\Http\Response => \Empira\Http\Response::html('from a response', 201));

        ob_start();
        $app->respond(new Request('GET', '/created'));
        $body = ob_get_clean();

        self::assertSame('from a response', $body);
    }
}