<?php

declare(strict_types=1);

namespace Empira\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * A request handler backed by a closure.
 *
 * PSR-15 is built around handlers, and this framework's route table is built
 * around closures — `fn(Request $request): Response`. Rather than make every
 * route a class, the destination is wrapped in one of these and the two live
 * side by side.
 *
 * The type juggling in {@see handle()} is the price, and it is contained here on
 * purpose. A handler written against PSR-15 accepts a `ServerRequestInterface`
 * and returns a `ResponseInterface`; what actually flows through an Empira
 * pipeline is always an {@see Request} in and a {@see Response} out, because
 * that is what the router and every middleware here produce. So the narrowings
 * below are assertions about the framework's own consistency rather than
 * conversions — and they throw rather than silently accepting a mismatch, since a
 * `ResponseInterface` from somewhere else would fail later and further away.
 */
final class ClosureHandler implements RequestHandlerInterface
{
    /** @param \Closure(Request): Response $destination */
    public function __construct(
        private readonly \Closure $destination,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        if (!$request instanceof Request) {
            throw new \InvalidArgumentException(sprintf(
                'An Empira pipeline handles %s, got %s.',
                Request::class,
                $request::class,
            ));
        }

        $response = ($this->destination)($request);

        if (!$response instanceof Response) {
            throw new \InvalidArgumentException(sprintf(
                'An Empira handler must return %s, got %s.',
                Response::class,
                get_debug_type($response),
            ));
        }

        return $response;
    }
}