<?php

declare(strict_types=1);

namespace Empira\Console;

use Empira\Console\Commands\MakeComponent;
use Empira\Console\Commands\Migrate;
use Empira\Console\Commands\MakeController;
use Empira\Console\Commands\RouteList;
use Empira\Console\Commands\Serve;
use Empira\Application as Framework;

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

    /**
     * Commands the project registered, kept apart from the framework's own.
     *
     * Separate so that `list` can say where a command came from — a name in the
     * left-hand column that somebody spent an afternoon on deserves to be
     * recognisable as theirs.
     *
     * @var array<string, Command>
     */
    private array $project = [];

    /**
     * The booted project, when there is one.
     *
     * Held because a command may need more than the commands a project
     * registered: `migrate` has to plan across the migrations *modules*
     * contribute, and only the booted application knows those. Handing it to
     * every command's `handle()` would be worse — a seeder has no use for it,
     * and a command that could see the whole application would eventually
     * depend on it.
     */
    private ?Framework $booted = null;

    /**
     * Give the console the booted project.
     *
     * Separate from {@see project()} on purpose: that adds a command, this
     * says what the project is. `bin/empira` needs both and they are different
     * facts — a project may register no commands and still ship modules.
     */
    public function booted(Framework $application): self
    {
        $this->booted = $application;

        return $this;
    }

    public function __construct()
    {
        $this->commands = [
            'make:component' => new MakeComponent(),
            'make:controller' => new MakeController(),
            'migrate' => new Migrate(),
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
        return $this->commands + $this->project;
    }

    /**
     * Add a command the project supplies itself.
     *
     * The framework cannot know what an application's seeders look like, so
     * `empira seed` is registered by the application that owns it. A project
     * command cannot take the name of a framework one: two commands called
     * `migrate`, where the winner depends on which was registered last, is
     * exactly the kind of surprise a console should not have.
     *
     * @throws \InvalidArgumentException when the name is already the framework's
     */
    public function project(string $name, Command $command): self
    {
        if (isset($this->commands[$name])) {
            throw new \InvalidArgumentException(sprintf(
                'The framework already provides "%s"; a project command cannot take that name.',
                $name
            ));
        }

        $this->project[$name] = $command;

        return $this;
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

        // commands(), not $this->commands: the project may have registered one
        // under this name, and a command that is listed but not dispatchable is
        // worse than one that is absent.
        $commands = $this->commands();

        if (!isset($commands[$command])) {
            $output->error(sprintf('Unknown command "%s".', $command));
            $this->writeListing($output);

            return 1;
        }

        if ($this->booted !== null && isset($this->commands['migrate'])) {
            $this->commands['migrate']->withModuleMigrations($this->booted->migrationSources());
        }

        // `route:list` needs the same application this console already booted, and
        // it must not go looking for a file to load it from: the conventional
        // `src/App.php` is a class file the autoloader has already loaded, so
        // requiring it redeclares the class and fatals. That is a bug this
        // command shipped with, on every application following the documented
        // layout.
        if ($this->booted !== null && isset($this->commands['route:list'])) {
            $this->commands['route:list']->withBootedApplication($this->booted);
        }

        return $commands[$command]->handle(self::options($arguments), $output);
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
        $all = $this->commands();

        $names = array_keys($all);
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
            $command = $all[$name];

            // Project commands are marked, so the distinction between "the
            // framework has this" and "this application wrote it" is visible
            // without having to go and look.
            $source = isset($this->project[$name]) ? '  (project)' : '';

            $output->line(sprintf('  %-' . $width . 's  %s%s', $name, $command->description(), $source));

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
