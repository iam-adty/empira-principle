<?php

declare(strict_types=1);

namespace Empira\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * A layer the request passes through on its way to a route handler.
 *
 * Middleware wraps the rest of the pipeline: call `$handler->handle($request)` to
 * carry on, or return a response of your own to short-circuit.
 *
 *     final class RequireAuth extends Middleware
 *     {
 *         public function process(
 *             ServerRequestInterface $request,
 *             RequestHandlerInterface $handler,
 *         ): ResponseInterface {
 *             return $this->session->has('user_id')
 *                 ? $handler->handle($request)
 *                 : Response::html('Sign in', 401);
 *         }
 *     }
 *
 * The constructor is autowired, so anything a middleware needs — the session,
 * the logger, a repository — can simply be typed as a parameter.
 *
 * ## This is PSR-15's `MiddlewareInterface`
 *
 * Every PSR-15 consumer's middleware can be dropped into an Empira application
 * without an adapter, and Empira's own middleware can be handed to one.
 *
 * The parameter is typed `ServerRequestInterface` because the interface
 * requires it, and PHP forbids narrowing a parameter. That costs the htmx
 * conveniences — `$request->isHtmx()`, `$request->input()` — which live on
 * {@see Request} and are therefore out of scope inside `process()`.
 *
 * {@see Request::of()} narrows in one line when you need them back:
 *
 *     $empira = Request::of($request);   // throws if it is not ours
 *
 * The alternative would have been an abstract base class writing `process()` in
 * terms of a closure-based `handle()`, which keeps the conveniences and the
 * closure spelling. That was not chosen, so this is the shape: the standard's
 * shape, in exchange for a little ceremony where a layer wants this framework's
 * own vocabulary.
 */
interface Middleware extends MiddlewareInterface
{
    /**
     * @param RequestHandlerInterface $handler the rest of the pipeline
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface;
}