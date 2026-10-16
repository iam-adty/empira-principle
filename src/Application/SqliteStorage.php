<?php

declare(strict_types=1);

namespace Empira\Application;

/**
 * The default database: a SQLite file under `storage/`.
 *
 * SQLite so `composer serve` needs nothing installed and nothing running.
 *
 * ## The path is relative, and that is load-bearing
 *
 * This class ships inside `principle`, so `dirname(__DIR__)` would resolve to the
 * *framework's* directory — and a skeleton's database would end up at
 * `vendor/iam-adty/empira-principle/storage/app.sqlite`. Green in a test run,
 * because nothing reinstalls the package mid-suite, and gone with the next
 * `composer install`.
 *
 * So it answers `storage/app.sqlite` and the application resolves that against
 * its own root, which it knows and this does not. {@see ProvidesStorage} allows
 * either form, so an application supplying an absolute path still gets exactly
 * what it asked for.
 *
 * ## Where it is published
 *
 * `Application::orm()` reads the database from the environment, so the value is
 * written to `$_ENV` before anything reads it. Two readers once had two
 * defaults — the runtime wanted this file and the migration command asked the
 * framework and got `:memory:` — and a migration against `:memory:` is a
 * database that vanishes on exit, followed by an application querying a real one
 * with no `users` table in it.
 *
 * **Boot does not migrate.** Migrating is a deploy step — `empira migrate` —
 * because boot is per worker thread, and a boot that migrates is a boot that has
 * eleven threads racing each other through the same migration.
 */
final class SqliteStorage implements ProvidesStorage
{
    public function path(): string
    {
        return 'storage/app.sqlite';
    }
}