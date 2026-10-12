<?php

declare(strict_types=1);

namespace Empira;

/**
 * A module that ships its own migrations.
 *
 * Separate from {@see Module} on purpose. Most modules have no schema to create
 * — a logging module, a rate limiter held in memory — and an interface every
 * module is forced to implement, only to return an empty array, is an interface
 * that teaches nothing. Opting in means there is a schema, which is the only
 * thing this one asks.
 *
 * Each directory needs its **own** namespace, not the project's. Doctrine maps
 * `namespace => directory` and looks migration classes up under that namespace,
 * so a module that reused `Empira\Migrations` while pointing at a different
 * directory would have its files required into a namespace whose other files
 * Doctrine never sees. Worse, the failure is not a missing class — it is a
 * class that already exists under the project's namespace from an unrelated
 * file, and whichever one `require` reached first is the one that runs.
 */
interface MigrationSource
{
    /**
     * This module's migration directories, keyed by the namespace their
     * `Version*.php` classes declare.
     *
     * The key is load-bearing: it must match the `namespace` statement inside
     * the files at that directory, exactly.
     *
     * @return array<string, string> namespace => absolute directory
     */
    public function migrations(): array;
}