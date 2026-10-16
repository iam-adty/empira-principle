<?php

declare(strict_types=1);

namespace Empira\Http;

use Nyholm\Psr7\Stream;
use Nyholm\Psr7\UploadedFile;
use Nyholm\Psr7\Uri;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestFactoryInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UploadedFileFactoryInterface;
use Psr\Http\Message\UploadedFileInterface;
use Psr\Http\Message\UriFactoryInterface;
use Psr\Http\Message\UriInterface;

/**
 * PSR-17: the factories that build PSR-7 messages.
 *
 * Everything a PSR consumer needs in order to *create* a request or a response,
 * in one object, because a framework that implements the message interfaces but
 * not the factories still forces every consumer to pick a concrete PSR-7
 * implementation and hope it agrees about how a request was built.
 *
 * The created messages are this package's own {@see Request} and {@see Response},
 * so a message from here carries the htmx conveniences as well as satisfying the
 * standard — a consumer that knows what it is talking to gets both, and one that
 * does not is unaffected.
 *
 *     $factory = new Psr17();
 *     $response = $factory->createResponse(404)->withHeader('X', 'y');
 */
final class Psr17 implements
    RequestFactoryInterface,
    ResponseFactoryInterface,
    ServerRequestFactoryInterface,
    StreamFactoryInterface,
    UriFactoryInterface,
    UploadedFileFactoryInterface
{
    public function createRequest(string $method, $uri): RequestInterface
    {
        return new Request(method: strtoupper($method), uri: (string) $uri);
    }

    public function createResponse(int $code = 200, string $reasonPhrase = ''): ResponseInterface
    {
        return new Response(status: $code, reasonPhrase: $reasonPhrase);
    }

    public function createServerRequest(string $method, $uri, array $serverParams = []): ServerRequestInterface
    {
        return new Request(
            method: strtoupper($method),
            uri: (string) $uri,
            serverParams: $serverParams,
        );
    }

    public function createStream(string $content = ''): StreamInterface
    {
        return Stream::create($content);
    }

    public function createStreamFromFile(string $filename, string $mode = 'r'): StreamInterface
    {
        return Stream::create(fopen($filename, $mode) ?: throw new \InvalidArgumentException(
            sprintf('Cannot open "%s" in mode "%s".', $filename, $mode),
        ));
    }

    public function createStreamFromResource($resource): StreamInterface
    {
        return Stream::create($resource);
    }

    public function createUri(string $uri = ''): UriInterface
    {
        return new Uri($uri);
    }

    public function createUploadedFile(
        StreamInterface|string $streamOrFile,
        ?int $size = null,
        int $error = UPLOAD_ERR_OK,
        ?string $clientFilename = null,
        ?string $clientMediaType = null,
    ): UploadedFileInterface {
        return new UploadedFile(
            $streamOrFile,
            $size,
            $error,
            $clientFilename,
            $clientMediaType,
        );
    }
}