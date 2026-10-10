<?php

declare(strict_types=1);

namespace IamAdty\Empira\Console\Commands;

use Closure;
use IamAdty\Empira\Application;
use IamAdty\Empira\Console\Command;
use IamAdty\Empira\Console\Output;
use IamAdty\Empira\Routing\Route;
use IamAdty\Empira\Routing\RouteCollection;

/**
 * Prints the route table of an application.
 *
 * Routes only exist once an application has been built, so this command loads
 * one from a file rather than guessing. That file may return the `Application`
 * itself, a `RouteCollection`, or a closure that fills one — which is exactly
 * the shape `routes.php` has, so the default path needs no extra wiring:
 *
 *     $routes = $app->routes();
 *     (require __DIR__ . '/routes.php')($routes);
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
            $routes = self::routes(require $path);
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
     * The bootstrap file: `--app`, else `<target>/routes.php`.
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

        return ($target === '' ? (getcwd() ?: '.') : $target) . '/routes.php';
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
