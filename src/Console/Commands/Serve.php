<?php

declare(strict_types=1);

namespace Empira\Console\Commands;

use Empira\Console\Command;
use Empira\Console\Output;

/**
 * Runs the PHP built-in web server against an application's `public/`.
 *
 * There is no custom server here, and there should not be one. `php -S` does the
 * one job this needs: hand every request to the front controller as a router
 * script, and serve the static files under `public/` itself.
 *
 * The second half is **the front controller's job, not `php -S`'s**, and this
 * command's own docblock used to say otherwise. A router script runs at the
 * start of *every* request; falling through to the requested file is something
 * it has to ask for explicitly, by returning `false`. An application that does
 * not will answer `public/`'s own assets itself, find no route for them, and
 * 404 — a failure that is invisible under nginx, where the web server serves
 * those files before PHP is involved at all.
 *
 * So an application front controller needs, above its autoloader:
 *
 *     if (PHP_SAPI === 'cli-server') {
 *         $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
 *         if ($path !== null && $path !== '/' && is_file(__DIR__ . $path)) {
 *             return false;
 *         }
 *     }
 *
 * All this command adds is the right arguments, printed so the user can see —
 * and re-run — exactly what was started.
 */
final class Serve implements Command
{
    public function name(): string
    {
        return 'serve';
    }

    public function description(): string
    {
        return 'Serve the application with the PHP built-in web server.';
    }

    public function arguments(): array
    {
        return ['[--host=127.0.0.1]', '[--port=8000]', '[--target=dir]'];
    }

    public function handle(array $options, Output $output): int
    {
        $host = (string) ($options['host'] ?? '127.0.0.1');
        $port = (string) ($options['port'] ?? '8000');

        if (filter_var($port, FILTER_VALIDATE_INT) === false || (int) $port < 1 || (int) $port > 65535) {
            $output->error(sprintf('"%s" is not a valid port number.', $port));

            return 1;
        }

        $target = self::target($options);
        $front = $target . '/public/index.php';

        if (!is_file($front)) {
            $output->error(sprintf(
                'No front controller at %s. Point --target at an application directory, or run this from its root.',
                $front
            ));

            return 1;
        }

        $command = sprintf('php -S %s:%s -t public public/index.php', $host, $port);

        // The headline keeps `info()` and so keeps its colour; the addresses below
        // it are plain output. That distinction is the only reason this is not a
        // loop over `line()`, and `announcement()` returns strings so it can be
        // tested without a server.
        $lines = self::announcement($target, $host, $port);
        $output->info((string) array_shift($lines));

        foreach ($lines as $line) {
            $output->line($line);
        }

        $output->line($command);

        return $this->start($target, $command);
    }

    /**
     * Where the server is, in terms someone can act on.
     *
     * `0.0.0.0` is a bind address, not a destination. Announcing
     * "Serving on http://0.0.0.0:8000" is technically true and practically
     * useless: pasting it into a browser works on some systems and not others,
     * and on the ones where it works you have learned nothing about the address
     * you would actually hand to somebody else. So a wildcard bind says what it
     * did — every interface — and gives the two addresses that do work.
     *
     * **Static and pure, rather than printing as it goes.** The only other way to
     * reach this text is through `handle()`, which hands the terminal to `php -S`
     * and does not come back — so testing it that way means testing it by
     * starting a server, or not testing it. A message that is the only thing
     * standing between someone and pasting an unusable URL should not be the one
     * line here with no test.
     *
     * @return list<string>
     */
    public static function announcement(string $target, string $host, string $port): array
    {
        if ($host !== '0.0.0.0' && $host !== '::') {
            return [sprintf('Serving %s on http://%s:%s', $target, $host, $port)];
        }

        return [
            sprintf('Serving %s on every interface, port %s', $target, $port),
            sprintf('  local     http://localhost:%s', $port),
            sprintf('  network   http://<this machine\'s address>:%s — the bind is not a firewall', $port),
        ];
    }

    /**
     * Hand the terminal to `php -S` until it exits.
     *
     * The command is run from the application directory because that is where
     * the printed `-t public public/index.php` paths are relative to; copying
     * the line above into a shell from anywhere else will not work.
     */
    private function start(string $target, string $command): int
    {
        passthru('cd ' . escapeshellarg($target) . ' && ' . $command, $status);

        return match (true) {
            $status === 0 => 0,
            $status > 0 => $status,
            default => 1,
        };
    }

    /**
     * The application directory: `--target`, or the working directory.
     *
     * @param array<string, mixed> $options
     */
    private static function target(array $options): string
    {
        $target = rtrim(trim((string) ($options['target'] ?? '')), '/');

        return $target === '' ? (getcwd() ?: '.') : $target;
    }
}
