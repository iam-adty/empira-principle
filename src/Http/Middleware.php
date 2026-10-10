<?php

declare(strict_types=1);

namespace IamAdty\Empira\Http;

use Closure;

/**
 * A layer the request passes through on its way to a route handler.
 *
 * Middleware wraps the rest of the pipeline: call `$next($request)` to carry on,
 * or return a response of your own to short-circuit.
 *
 *     final class RequireAuth implements Middleware
 *     {
 *         public function handle(Request $request, Closure $next): Response
 *         {
 *             return $this->session->has('user_id')
 *                 ? $next($request)
 *                 : Response::html('Sign in')->setStatus(401);
 *         }
 *     }
 *
 * The constructor is autowired, so anything a middleware needs — the session,
 * the logger, a repository — can simply be typed as a parameter.
 */
interface Middleware
{
    /**
     * @param Closure(Request): Response $next
     */
    public function handle(Request $request, Closure $next): Response;
}