<?php

declare(strict_types=1);

namespace IamAdty\Empira\Tests;

use IamAdty\Empira\Application;
use IamAdty\Empira\Http\Request;
use IamAdty\Empira\Http\Response;
use IamAdty\Empira\Tests\Fixtures\MemoryStore;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Psr\Log\NullLogger;

/**
 * Logging: PSR-3 all the way down.
 *
 * The framework used to own a `Logger` interface, a `LogLevel` enum and a
 * `FileLogger`. It owns none of that now — it depends on PSR-3 instead, so the
 * logger an application already has is the logger the framework writes to.
 *
 * What is left to test is the wiring: that an application can hand one over,
 * that components can ask for it by type, and — the part that was actually
 * missing — that an exception ending a request is recorded rather than thrown
 * into a void.
 */
final class LoggingTest extends TestCase
{
    private const SECRET = 'test-secret-that-is-long-enough-for-hmac!!';

    // -------------------------------------------------------- the logger itself

    public function testTheLoggerIsPsr3AndNotTheFrameworksOwnInterface(): void
    {
        // The whole point of the dependency: anything implementing PSR-3 works,
        // with no adapter and no base class to extend.
        $logger = new class extends AbstractLogger {
            /** @var list<array{level: mixed, message: string, context: array}> */
            public array $entries = [];

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->entries[] = ['level' => $level, 'message' => $message, 'context' => $context];
            }
        };

        self::assertInstanceOf(LoggerInterface::class, $logger);

        $app = new Application(self::SECRET, new MemoryStore());
        $app->log($logger);

        $app->logger()->info('hello', ['a' => 1]);

        self::assertCount(1, $logger->entries);
        self::assertSame('hello', $logger->entries[0]['message']);
        self::assertSame(['a' => 1], $logger->entries[0]['context']);
    }

    public function testLogIsChainableAndLoggerReturnsWhatWasGiven(): void
    {
        $logger = new NullLogger();
        $app = new Application(self::SECRET, new MemoryStore());

        self::assertSame($app, $app->log($logger));
        self::assertSame($logger, $app->logger());
    }

    public function testNothingIsLoggedUntilTheApplicationSuppliesALogger(): void
    {
        // A framework that logs by surprise is a framework nobody trusts in
        // production, so the default has to discard rather than reach for a
        // file nobody configured.
        $app = new Application(self::SECRET, new MemoryStore());

        self::assertInstanceOf(NullLogger::class, $app->logger());
    }

    public function testTheLoggerIsBoundInTheContainerForComponentsToAskForByType(): void
    {
        $app = new Application(self::SECRET, new MemoryStore());

        // Before the application says anything: still answerable, and answering
        // matters — a component asking for `LoggerInterface` must not blow up.
        self::assertInstanceOf(LoggerInterface::class, $app->container()->get(LoggerInterface::class));

        $logger = new NullLogger();
        $app->log($logger);

        self::assertSame($logger, $app->container()->get(LoggerInterface::class));
    }

    // ------------------------------------------- exceptions are recorded, not lost

    public function testAGenuineBugIsLoggedBeforeItIsRethrown(): void
    {
        $app = new Application(self::SECRET, new MemoryStore());
        $logger = self::recordingLogger();
        $app->log($logger);

        // A bug is re-thrown by design — it should fail loudly rather than be
        // dressed up as a tidy 500. The point is that being loud now means a
        // log line, because the re-throw's own output is off by default.
        $app->fallback(function (): string {
            throw new \LogicException('a real bug');
        });

        try {
            $app->handle(new Request('GET', '/boom'));
            self::fail('the exception should have been re-thrown');
        } catch (\LogicException) {
            // expected
        }

        self::assertCount(1, $logger->entries);

        [$entry] = $logger->entries;

        self::assertSame(LogLevel::ERROR, $entry['level']);
        self::assertSame('a real bug', $entry['message']);
        self::assertSame('GET', $entry['context']['method']);
        self::assertSame('/boom', $entry['context']['path']);
        self::assertInstanceOf(\LogicException::class, $entry['context']['exception']);
    }

    public function testAnExceptionAnApplicationRendersItselfIsStillLogged(): void
    {
        $app = new Application(self::SECRET, new MemoryStore());
        $logger = self::recordingLogger();
        $app->log($logger);

        // Rendering an exception is a response, not a diagnosis. Without this
        // the one branch that swallows the throwback would also swallow the
        // only record of it.
        $app->onException(static fn(\Throwable $e): Response => Response::html('sorry', 500));

        $app->fallback(function (): string {
            throw new \LogicException('handled, but worth knowing');
        });

        $response = $app->handle(new Request('GET', '/oops'));

        self::assertSame(500, $response->status());
        self::assertCount(1, $logger->entries);
        self::assertSame('handled, but worth knowing', $logger->entries[0]['message']);
    }

    public function testAClientErrorIsNotLoggedAsAFailure(): void
    {
        $app = new Application(self::SECRET, new MemoryStore());
        $logger = self::recordingLogger();
        $app->log($logger);

        // A 404 is the client's doing and is not an incident. Logging every one
        // of them buries the 500s in routine noise.
        $app->fallback(function (): string {
            throw new \IamAdty\Empira\Routing\HttpException(404, 'not here');
        });

        $response = $app->handle(new Request('GET', '/missing'));

        self::assertSame(404, $response->status());
        self::assertSame([], $logger->entries);
    }

    public function testALoggerThatThrowsDoesNotTakeTheRequestDownWithIt(): void
    {
        $app = new Application(self::SECRET, new MemoryStore());

        // A logger failing must not replace a 500 with a different 500 and
        // lose the original — the whole reason `logFailure` swallows.
        $app->log(new class extends AbstractLogger {
            public function log($level, string|\Stringable $message, array $context = []): void
            {
                throw new \RuntimeException('the disk is full');
            }
        });

        $app->fallback(function (): string {
            throw new \LogicException('a real bug');
        });

        try {
            $app->handle(new Request('GET', '/boom'));
            self::fail('the original exception should still have been re-thrown');
        } catch (\LogicException $e) {
            self::assertSame('a real bug', $e->getMessage());
        }
    }

    /**
     * Records what it was asked to write, so a test can assert on it.
     */
    private static function recordingLogger(): AbstractLogger
    {
        return new class extends AbstractLogger {
            /** @var list<array{level: mixed, message: string, context: array<string, mixed>}> */
            public array $entries = [];

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->entries[] = ['level' => $level, 'message' => $message, 'context' => $context];
            }
        };
    }
}