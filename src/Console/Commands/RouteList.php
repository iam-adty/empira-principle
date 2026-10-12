<?php

declare(strict_types=1);

namespace Empira\Console\Commands;

use Closure;
use Empira\Application;
use Empira\Console\Command;
use Empira\Console\Output;
use Empira\Routing\Route;
use Empira\Routing\RouteCollection;

/**
 * Prints the route table of an application.
 *
 * Routes only exist once an application has been built, so this command loads
 * one from a file rather than guessing. That file may return the `Application`
 * itself, a `RouteCollection`, or a closure that fills one — or declare a class
 * with a static `boot()`, which is how a real application is written:
 *
 *     $app = App::boot();
 *
 * So `route:list` with no arguments reads `src/App.php` in the target
 * directory and boots it, which means the table it prints is the table the
 * application actually serves — discovered attributes and all.
 *
 * Anything that goes wrong on the way — a missing file, a fatal error in the
 * bootstrap, a file that returns something unexpected — is reported as a
 * message and a non-zero exit code. A missing route table is a mistake worth
 * one clear sentence, not a stack trace.
 */
final class RouteList implements Command
{
    public function name(): string
    {
        return 'route:list';
    }

    public function description(): string
    {
        return 'Print the routes an application registers.';
    }

    public function arguments(): array
    {
        return ['[--app=path]', '[--target=dir]'];
    }

    public function handle(array $options, Output $output): int
    {
        $path = self::app($options);

        if (!is_file($path)) {
            $output->error(sprintf(
                'No application bootstrap at %s. Pass --app=path/to/app.php — a file returning an Application, a RouteCollection, or a closure that registers routes.',
                $path
            ));

            return 1;
        }

        try {
            self::loadProjectAutoloader($path);

            $before = get_declared_classes();
            $loaded = require $path;
            $routes = self::routes($loaded) ?? self::routesFromBootable(array_diff(get_declared_classes(), $before));
        } catch (\Throwable $e) {
            $output->error(sprintf('Could not load %s: %s', $path, $e->getMessage()));

            return 1;
        }

        if ($routes === null) {
            $output->error(sprintf(
                '%s did not return an Application, a RouteCollection, or a closure that registers routes.',
                $path
            ));

            return 1;
        }

        $registered = $routes->all();

        if ($registered === []) {
            $output->warn('No routes registered.');

            return 0;
        }

        foreach (self::table($registered) as $row) {
            $output->line($row);
        }

        return 0;
    }

    /**
 * Load the project's own autoloader, if it has one.
 *
 * This command runs from the framework's binary, so the project's classes are
 * not autoloadable yet — and a route table that lives in the project's own
 * `src/` cannot even be loaded without them. Walking up from the bootstrap file
 * finds `vendor/autoload.php` the same way the front controller does.
 */
private static function loadProjectAutoloader(string $path): void
    {
        $directory = \dirname((string) realpath($path));
        $included = get_included_files();

        for ($depth = 0; $depth < 4; $depth++) {
            $autoloader = $directory . '/vendor/autoload.php';

            if (is_file($autoloader) && !in_array($autoloader, $included, true)) {
                require_once $autoloader;

                return;
            }

            $parent = \dirname($directory);

            if ($parent === $directory) {
                return;
            }

            $directory = $parent;
        }
    }

    /**
     * The bootstrap file: `--app`, else `<target>/src/App.php`.
     *
     * The application is the conventional place for routes to be declared, so
     * that is the default; a project keeping its routes in `routes.php` still
     * works by passing `--app=` or `--target=`.
     *
     * @param array<string, mixed> $options
     */
    private static function app(array $options): string
    {
        $app = trim((string) ($options['app'] ?? ''));

        if ($app !== '') {
            return $app;
        }

        $target = rtrim(trim((string) ($options['target'] ?? '')), '/');
        $target = $target === '' ? (getcwd() ?: '.') : $target;

        $conventional = $target . '/src/App.php';

        return is_file($conventional) ? $conventional : $target . '/routes.php';
    }

    /**
     * The routes an application file declares through a static `boot()`.
     *
     * `src/App.php` normally defines a class and returns nothing, because the
     * front controller calls `App::boot()` itself. Requiring the file tells us
     * which classes it declared; whichever one boots an application is the one
     * whose routes we want.
     *
     * @param list<class-string> $declared
     */
    private static function routesFromBootable(array $declared): ?RouteCollection
    {
        foreach ($declared as $class) {
            if (!method_exists($class, 'boot')) {
                continue;
            }

            $reflection = new \ReflectionMethod($class, 'boot');

            if (!$reflection->isPublic() || !$reflection->isStatic()) {
                continue;
            }

            try {
                $routes = self::routes($reflection->invoke(null));
            } catch (\Throwable) {
                // Booting for real needs a session or a secret. A route table is
                // not worth failing to print over, so try the next candidate.
                continue;
            }

            if ($routes !== null) {
                return $routes;
            }
        }

        return null;
    }

    /**
     * The route collection a bootstrap file produced, or null when it produced
     * something else.
     */
    private static function routes(mixed $loaded): ?RouteCollection
    {
        if ($loaded instanceof Application) {
            return $loaded->routes();
        }

        if ($loaded instanceof RouteCollection) {
            return $loaded;
        }

        if ($loaded instanceof Closure) {
            $routes = new RouteCollection();
            $returned = $loaded($routes);

            return $returned instanceof RouteCollection ? $returned : $routes;
        }

        return null;
    }

    /**
     * The route table as aligned lines: method, path, name, handler.
     *
     * @param list<Route> $routes
     *
     * @return list<string>
     */
    private static function table(array $routes): array
    {
        $rows = [[
            'method' => 'METHOD',
            'path' => 'PATH',
            'name' => 'NAME',
            'handler' => 'HANDLER',
        ]];

        foreach ($routes as $route) {
            $rows[] = [
                'method' => $route->method,
                'path' => $route->pattern,
                'name' => $route->name ?? '',
                'handler' => self::handler($route),
            ];
        }

        $widths = ['method' => 0, 'path' => 0, 'name' => 0];

        foreach ($rows as $row) {
            foreach ($widths as $column => $width) {
                $widths[$column] = max($width, strlen($row[$column]));
            }
        }

        return array_map(
            static fn(array $row): string => sprintf(
                '%-' . $widths['method'] . 's  %-' . $widths['path'] . 's  %-' . $widths['name'] . 's  %s',
                $row['method'],
                $row['path'],
                $row['name'],
                $row['handler']
            ),
            $rows
        );
    }

    /**
     * A handler in the form it was written: `Controller@method`, a callable
     * class name, or the type of anything else.
     */
    private static function handler(Route $route): string
    {
        $handler = $route->handler;

        if (is_array($handler) && count($handler) === 2 && is_string($handler[0])) {
            return $handler[0] . '@' . (is_string($handler[1]) ? $handler[1] : '...');
        }

        return is_string($handler) ? $handler : get_debug_type($handler);
    }
}
