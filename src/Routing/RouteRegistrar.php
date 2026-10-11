<?php

declare(strict_types=1);

namespace IamAdty\Empira\Routing;

/**
 * A class that declares routes explicitly.
 *
 * Attributes cover the common case, but a table has to say some things an
 * attribute cannot say well: a group of routes under a shared prefix, a stack
 * of middleware, or a deliberate ordering that depends on nothing but the code.
 *
 *     final class AppRoutes implements RouteRegistrar
 *     {
 *         public function register(RouteCollection $routes): void
 *         {
 *             $routes->group('/admin', middleware: [RequireAuth::class])
 *                     ->get('/stats', [StatsController::class, 'show']);
 *         }
 *     }
 *
 * Register one with `$app->registrar(new AppRoutes())`.
 *
 * @see RouteRegistry for attribute-based discovery
 */
interface RouteRegistrar
{
    public function register(RouteCollection $routes): void;
}