<?php

declare(strict_types=1);

namespace Empira\Console;

use Empira\Application;

/**
 * Finds the commands a project has for itself.
 *
 * The framework cannot know what an application's seeders or importers look
 * like, so the application registers them at boot and something has to go and
 * collect them. That something is here rather than in `bin/empira`, because the
 * test suite needs to run `empira migrate` and `empira seed` through exactly the
 * same door — otherwise a test can pass against a console the operator never
 * runs.
 *
 * The bootstrap file is optional. `make:component` and `route:list` must work
 * in a checkout that has no application at all.
 */
final class Bootstrap
{
    /** The path looked for when nothing else says otherwise. */
    public const DEFAULT_PATH = 'bootstrap/app.php';

    /**
     * Boot the project in `$directory` and return what it registered.
     *
     * @return array<string, Command> name => command, empty if there is no project
     *
     * @throws \RuntimeException when `EMPIRA_BOOTSTRAP` points at nothing, or
     *                           the file returns something that is not an Application
     */
    public static function commandsFor(string $directory): array
    {
        return self::boot($directory)?->commands() ?? [];
    }

    /**
     * Boot the project and return the application itself, or null when this
     * directory has none.
     *
     * The commands are one thing a caller wants; the *modules* are another, and
     * `empira migrate` needs the second — a module that ships migrations
     * registers them on the application, and a command that never saw the
     * application would plan a schema missing every table it is responsible
     * for. So the boot is exposed rather than only its commands.
     *
     * @throws \RuntimeException when the bootstrap returns something that is
     *                           not an Application
     */
    public static function boot(string $directory): ?Application
    {
        $file = self::locate($directory);

        if ($file === null) {
            return null;
        }

        $booted = require $file;

        if (!$booted instanceof Application) {
            throw new \RuntimeException(sprintf(
                '%s must return the booted %s, or nothing at all.',
                $file,
                Application::class
            ));
        }

        return $booted;
    }

    /**
     * The bootstrap file to run, or null when this directory has no project.
     */
    private static function locate(string $directory): ?string
    {
        $explicit = $_ENV['EMPIRA_BOOTSTRAP'] ?? null;

        if (is_string($explicit) && $explicit !== '') {
            if (!is_file($explicit)) {
                throw new \RuntimeException(sprintf(
                    'EMPIRA_BOOTSTRAP points at %s, which does not exist.',
                    $explicit
                ));
            }

            return $explicit;
        }

        $default = rtrim($directory, '/') . '/' . self::DEFAULT_PATH;

        return is_file($default) ? $default : null;
    }
}