<?php

declare(strict_types=1);

namespace Empira\Tests;

use Empira\Container\Container;
use Empira\Container\ContainerException;
use Empira\Container\NotFound;
use Empira\Http\Request;
use Empira\Http\Psr17;
use Empira\Http\Response;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ServerRequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\UploadedFileFactoryInterface;
use Psr\Http\Message\UriFactoryInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UploadedFileInterface;
use Psr\Http\Message\UriInterface;

/**
 * The PSR interfaces this package claims, asserted rather than assumed.
 *
 * A framework that says it implements PSR-7 and does not is worse than one that
 * says nothing: a consumer writes against the interface, finds the methods
 * missing at runtime, and has to know which framework it landed in to find out
 * why. Every claim here is a claim a third party could check.
 *
 * Each test therefore asks the interface a question a third party would ask —
 * `instanceof`, a `withX()` that does not touch the original, an exception a
 * `catch` written against PSR can actually catch.
 */
final class PsrComplianceTest extends TestCase
{
    // ------------------------------------------------------------------ PSR-11

    public function testTheContainerIsAPsrContainer(): void
    {
        $this->assertInstanceOf(ContainerInterface::class, new Container());
    }

    public function testAnUnboundNameRaisesAPsrNotFoundException(): void
    {
        $container = new Container();

        try {
            $container->get('Nope\\Missing');
            $this->fail('nothing is bound under that name');
        } catch (NotFoundExceptionInterface $e) {
            // Caught by a `catch` written against PSR alone — the point of the
            // whole exercise.
            $this->assertInstanceOf(NotFound::class, $e);
            $this->assertStringContainsString('Nope\\Missing', $e->getMessage());
        }
    }

    public function testTheNotFoundExceptionIsStillTheBroadOneToExistingCode(): void
    {
        $container = new Container();

        try {
            $container->get('Nope\\Missing');
            $this->fail('nothing is bound under that name');
        } catch (ContainerException $e) {
            // An application that caught `ContainerException` before PSR-11 was
            // adopted must keep catching everything it caught before.
            $this->assertInstanceOf(ContainerExceptionInterface::class, $e);
        }
    }

    public function testAFailureThatIsNotAbsenceDoesNotClaimToBeAbsence(): void
    {
        $container = new Container();
        $container->bind('returns-a-string', static fn (): string => 'not an object');

        try {
            $container->get('returns-a-string');
            $this->fail('a factory that returns a scalar is not a not-found');
        } catch (NotFoundExceptionInterface $e) {
            $this->fail('a binding that exists but cannot be built is a failure, not an absence');
        } catch (ContainerException $e) {
            $this->assertStringContainsString('must return an object', $e->getMessage());
        }
    }

    // ------------------------------------------------------------------- PSR-7

    public function testTheRequestIsAPsrServerRequest(): void
    {
        $request = new Request(method: 'GET', uri: '/things');

        $this->assertInstanceOf(ServerRequestInterface::class, $request);
        $this->assertInstanceOf(RequestInterface::class, $request);
    }

    public function testTheResponseIsAPsrResponse(): void
    {
        $this->assertInstanceOf(ResponseInterface::class, Response::html('hi'));
    }

    public function testWithHeaderLeavesTheOriginalAlone(): void
    {
        // The change that matters most and is easiest to get wrong. A `withX()`
        // that mutates is a `withX()` that will corrupt a response another layer
        // still holds, and it will do so only in production.
        $original = Response::html('hi');
        $changed = $original->withHeader('X-Test', 'yes');

        $this->assertNotSame($original, $changed);
        $this->assertFalse($original->hasHeader('X-Test'), 'the original must not have gained the header');
        $this->assertTrue($changed->hasHeader('X-Test'));
        $this->assertSame('yes', $changed->getHeaderLine('X-Test'));
    }

    public function testWithStatusLeavesTheOriginalAlone(): void
    {
        $original = Response::html('hi');
        $changed = $original->withStatus(201);

        $this->assertNotSame($original, $changed);
        $this->assertSame(200, $original->getStatusCode());
        $this->assertSame(201, $changed->getStatusCode());
    }

    public function testWithMethodLeavesTheOriginalAlone(): void
    {
        $original = new Request(method: 'GET', uri: '/things');
        $changed = $original->withMethod('POST');

        $this->assertNotSame($original, $changed);
        $this->assertSame('GET', $original->getMethod());
        $this->assertSame('POST', $changed->getMethod());
    }

    public function testWithAttributeLeavesTheOriginalAlone(): void
    {
        $original = new Request(method: 'GET', uri: '/things');
        $changed = $original->withAttribute('user', 'ada');

        $this->assertNotSame($original, $changed);
        $this->assertNull($original->getAttribute('user'));
        $this->assertSame('ada', $changed->getAttribute('user'));
    }

    public function testTheBodyIsAPsrStream(): void
    {
        $response = Response::html('hello');

        $this->assertInstanceOf(StreamInterface::class, $response->getBody());
        $this->assertSame('hello', (string) $response->getBody());
    }

    public function testTheRequestUriIsAPsrUri(): void
    {
        $request = new Request(method: 'GET', uri: '/things?q=1');

        $this->assertInstanceOf(UriInterface::class, $request->getUri());
        $this->assertSame('/things', $request->getUri()->getPath());
        $this->assertSame('q=1', $request->getUri()->getQuery());
    }

    public function testGetHeadersReturnsListsAndHeadersReturnsStrings(): void
    {
        // PSR's `getHeaders()` is `array<string, string[]>` because a header may
        // legitimately repeat. This package's own `headers()` is
        // `array<string, string>` because that is what htmx and the emitter want.
        // Both exist, and they are not interchangeable.
        $response = Response::html('hi')->withHeader('X-Test', 'yes');

        $this->assertSame(['X-Test' => ['yes']], $response->getHeaders());
        $this->assertSame(['X-Test' => 'yes'], $response->headers());
    }

    public function testUploadedFilesArePsrUploadedFiles(): void
    {
        $request = new Request(
            method: 'POST',
            uri: '/upload',
            files: ['doc' => [
                'name' => 'notes.txt',
                'type' => 'text/plain',
                'tmp_name' => tempnam(sys_get_temp_dir(), 'empira'),
                'error' => UPLOAD_ERR_OK,
                'size' => 12,
            ]],
        );

        $uploaded = $request->getUploadedFiles();

        $this->assertInstanceOf(UploadedFileInterface::class, $uploaded['doc'] ?? null);
        $this->assertSame('notes.txt', $uploaded['doc']->getClientFilename());
        $this->assertSame('text/plain', $uploaded['doc']->getClientMediaType());
    }

    // ------------------------------------------------------------------ PSR-15

    public function testMiddlewareIsAPsrMiddleware(): void
    {
        $middleware = new class implements \Empira\Http\Middleware {
            public function process(
                ServerRequestInterface $request,
                RequestHandlerInterface $handler,
            ): ResponseInterface {
                return $handler->handle($request);
            }
        };

        $this->assertInstanceOf(MiddlewareInterface::class, $middleware);
    }

    public function testAThirdPartyPsr15MiddlewareRunsUnadapted(): void
    {
        // Written the way anybody outside this framework would write it — PSR
        // types only, no Empira class named — and dropped into an application.
        $app = new \Empira\Application('a-secret-that-is-long-enough');

        $app->routes()->get('/things', fn (): Response => Response::html('things'));

        $app->use(new class implements MiddlewareInterface {
            public function process(
                ServerRequestInterface $request,
                RequestHandlerInterface $handler,
            ): ResponseInterface {
                return $handler->handle($request)->withHeader('X-Psr-15', 'ran');
            }
        });

        $response = $app->handle(new Request(method: 'GET', uri: '/things'));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('ran', $response->getHeaderLine('X-Psr-15'));
        $this->assertSame('things', $response->body());
    }

    public function testAThirdPartyMiddlewareCanShortCircuit(): void
    {
        $app = new \Empira\Application('a-secret-that-is-long-enough');

        $app->routes()->get('/things', fn (): Response => Response::html('things'));

        $app->use(new class implements MiddlewareInterface {
            public function process(
                ServerRequestInterface $request,
                RequestHandlerInterface $handler,
            ): ResponseInterface {
                return Response::html('refused', 403);
            }
        });

        $this->assertSame(403, $app->handle(new Request(method: 'GET', uri: '/things'))->getStatusCode());
    }

    public function testAnEmpiraApplicationAnswersAPsrRequestDirectly(): void
    {
        $app = new \Empira\Application('a-secret-that-is-long-enough');

        $app->routes()->get('/things', fn (): Response => Response::html('things'));

        $request = (new Psr17())->createServerRequest('GET', '/things');

        $this->assertInstanceOf(ResponseInterface::class, $app->handle($request));
    }

    // ------------------------------------------------------------------ PSR-17

    public function testTheFactoryIsEveryPsr17Factory(): void
    {
        $factory = new Psr17();

        $this->assertInstanceOf(RequestFactoryInterface::class, $factory);
        $this->assertInstanceOf(ResponseFactoryInterface::class, $factory);
        $this->assertInstanceOf(ServerRequestFactoryInterface::class, $factory);
        $this->assertInstanceOf(StreamFactoryInterface::class, $factory);
        $this->assertInstanceOf(UriFactoryInterface::class, $factory);
        $this->assertInstanceOf(UploadedFileFactoryInterface::class, $factory);
    }

    public function testTheFactoryBuildsPsrMessages(): void
    {
        $factory = new Psr17();

        $this->assertInstanceOf(RequestInterface::class, $factory->createRequest('GET', '/things'));
        $this->assertSame(201, $factory->createResponse(201)->getStatusCode());
        $this->assertSame('/things', $factory->createUri('/things')->getPath());
        $this->assertSame('hello', (string) $factory->createStream('hello'));
    }

    public function testAFactoryRequestStillCarriesTheHtmxConveniences(): void
    {
        $request = (new Psr17())->createRequest('GET', '/things');

        // Both, not one or the other: a message built by the factory satisfies the
        // standard *and* keeps what this framework put on its own.
        $this->assertInstanceOf(ServerRequestInterface::class, $request);
        $this->assertInstanceOf(\Empira\Http\Request::class, $request);
        $this->assertSame('/things', $request->path());
    }
}