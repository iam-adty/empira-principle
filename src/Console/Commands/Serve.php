<?php

declare(strict_types=1);

namespace Empira\Console\Commands;

use Empira\Console\Command;
use Empira\Console\Output;

/**
 * Runs the PHP built-in web server against an application's `public/`.
 *
 * There is no custom server here, and there should not be one. `php -S` already
 * does the only two jobs this needs: serve the static files under `public/`, and
 * hand every other path to the front controller as a router script. All this
 * command adds is the right arguments, printed so the user can see — and
 * re-run — exactly what was started.
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

        $this->announce($target, $host, $port, $output);
        $output->line($command);

        return $this->start($target, $command);
    }

    /**
     * Say where the server is, in terms someone can act on.
     *
     * `0.0.0.0` is a bind address, not a destination. Announcing
     * "Serving on http://0.0.0.0:8000" is technically true and practically
     * useless: pasting it into a browser works on some systems and not others,
     * and on the ones where it works you have learned nothing about the address
     * you would actually hand to somebody else. So a wildcard bind says what it
     * did — every interface — and gives the two addresses that do work.
     */
    private function announce(string $target, string $host, string $port, Output $output): void
    {
        if ($host !== '0.0.0.0' && $host !== '::') {
            $output->info(sprintf('Serving %s on http://%s:%s', $target, $host, $port));

            return;
        }

        $output->info(sprintf('Serving %s on every interface, port %s', $target, $port));
        $output->line(sprintf('  local     http://localhost:%s', $port));
        $output->line(sprintf('  network   http://<this machine\'s address>:%s — the bind is not a firewall', $port));
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
