<?php

declare(strict_types=1);

namespace Empira;

/**
 * A unit of functionality the framework does not assume it needs.
 *
 * The framework used to build authentication into its own spine: `Application`
 * imported the `Auth` class, constructed one on every request, and refused to
 * describe an ORM configuration without mapping its `User` entity first. That
 * made every application carry authentication whether or not it wanted any —
 * and made "make it a module" a rewrite rather than a move, because the
 * dependency ran inward, from core to feature.
 *
 * A module inverts that. It is handed the {@see Application} once, at boot, and
 * wires itself into whatever extension points core offers: guards, entity
 * paths, console commands, middleware. Core keeps only the contracts the
 * modules implement, so a feature can be extracted into its own Composer
 * package and the framework still boots without it.
 *
 *     $app->module(new AuthModule(new DoctrineUserProvider($app->entities())));
 *
 * Registration is boot-time and order-sensitive: a module that adds entity
 * paths needs `orm()` to have been called, and one that registers routes needs
 * to come before discovery. That is a real constraint rather than an
 * accident — the alternative, a dependency sorter, would be machinery to
 * describe a graph of three edges that reads fine in the order it is written.
 *
 * Everything here happens once per process, never per request. Anything a
 * request must see goes through the container, because the container is what
 * {@see Application::handle()} rebuilds between requests.
 */
interface Module
{
    /**
     * Wire this module into the application.
     *
     * Called once, during {@see Application::module()}, before any request is
     * handled. Throwing here is how a module refuses to be wired into an
     * application that cannot support it, and it is much better than failing
     * halfway through the first request.
     */
    public function register(Application $app): void;
}