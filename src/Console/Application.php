<?php

declare(strict_types=1);

namespace IamAdty\Empira\Console;

use IamAdty\Empira\Console\Commands\MakeComponent;
use IamAdty\Empira\Console\Commands\MakeController;
use IamAdty\Empira\Console\Commands\RouteList;
use IamAdty\Empira\Console\Commands\Serve;

/**
 * The console kernel: a registry of commands and the dispatcher that runs one.
 *
 *     $exit = (new Application())->run($argv, new Output());
 *
 * Commands are named the way people type them (`make:component`, not
 * `component:make`) and argv is parsed before dispatch, so no command has to
 * care about `--force` versus `--force=true`. `list` is built in rather than
 * registered: it has to work even when the rest of the registry is empty, and
 * an unknown command is answered with the list rather than a bare "no such
 * command", because the useful thing to print is what *is* available.
 */
final class Application
{
    /** @var array<string, string> name => description for the commands the kernel handles itself */
    private const BUILTIN = [
        'list' => 'List every registered command.',
        '--help' => 'Show this list.',
        '-h' => 'Show this list.',
    ];

    /** @var array<string, Command> */
    private array $commands;

    public function __construct()
    {
        $this->commands = [
            'make:component' => new MakeComponent(),
            'make:controller' => new MakeController(),
            'route:list' => new RouteList(),
            'serve' => new Serve(),
        ];
    }

    /**
     * Every registered command, keyed by the name it is invoked with.
     *
     * @return array<string, Command>
     */
    public function commands(): array
    {
        return $this->commands;
    }

    /**
     * Parse argv, dispatch, and return the process exit code.
     *
     * `$argv[0]` is the script name and is ignored. With nothing else to run,
     * `list` runs — a console that answers a bare invocation with its own
     * listing is one fewer thing to memorise.
     *
     * @param list<string> $argv
     */
    public function run(array $argv, Output $output): int
    {
        $arguments = array_values(array_slice($argv, 1));
        $command = $arguments[0] ?? 'list';

        if ($command === 'list' || $command === '--help' || $command === '-h') {
            $this->writeListing($output);

            return 0;
        }

        if (!isset($this->commands[$command])) {
            $output->error(sprintf('Unknown command "%s".', $command));
            $this->writeListing($output);

            return 1;
        }

        return $this->commands[$command]->handle(self::options($arguments), $output);
    }

    /**
     * Turn argv into the options array a command receives.
     *
     * Positional arguments collect under `arguments`, `--force` becomes
     * `force => true` and `--target=dir` becomes `target => 'dir'`. A bare
     * `--name value` pair is left unparsed: guessing where a value stops means
     * guessing wrong half the time, and the `=` is one keystroke.
     *
     * @param list<string> $arguments argv without the script name
     *
     * @return array{arguments: list<string>, <string, mixed>}
     */
    private static function options(array $arguments): array
    {
        $options = ['arguments' => []];

        foreach (array_slice($arguments, 1) as $argument) {
            if (!str_starts_with($argument, '--')) {
                $options['arguments'][] = $argument;
                continue;
            }

            $option = substr($argument, 2);

            if (str_contains($option, '=')) {
                [$name, $value] = explode('=', $option, 2);
                $options[$name] = $value;

                continue;
            }

            $options[$option] = true;
        }

        return $options;
    }

    /**
     * The command listing, shown for `list`, for `--help`, and as the answer to
     * a command that does not exist.
     */
    private function writeListing(Output $output): void
    {
        $names = array_keys($this->commands);
        sort($names);

        $width = 0;

        foreach (array_merge(array_keys(self::BUILTIN), $names) as $name) {
            $width = max($width, strlen($name));
        }

        $output->line('Empira console');
        $output->line();
        $output->line('Usage:');
        $output->line('  empira <command> [options]');
        $output->line();

        foreach (self::BUILTIN as $name => $description) {
            $output->line(sprintf('  %-' . $width . 's  %s', $name, $description));
        }

        $output->line();
        $output->line('Commands:');

        foreach ($names as $name) {
            $command = $this->commands[$name];

            $output->line(sprintf('  %-' . $width . 's  %s', $name, $command->description()));

            if ($command->arguments() !== []) {
                $output->line(sprintf(
                    '  %-' . $width . 's  empira %s',
                    '',
                    implode(' ', array_merge([$name], $command->arguments()))
                ));
            }
        }

        $output->line();
        $output->line('Options are written --name for a flag and --name=value for a value.');
    }
}
