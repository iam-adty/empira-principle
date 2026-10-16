<?php

declare(strict_types=1);

namespace Empira\Application;

/**
 * Where an application keeps its database.
 *
 * One method, so replacing it is one method.
 *
 * @see SqliteStorage the framework's own
 */
interface ProvidesStorage
{
    /**
     * Where the database is.
     *
     * Absolute, or relative to the application root — whichever the application
     * finds easier, because the one thing this must not do is resolve against
     * wherever the class happens to be loaded from. A default shipped inside a
     * package would otherwise put the database in that package's directory, in
     * `vendor/`, which survives a test run and dies with the next install.
     */
    public function path(): string;
}