<?php

declare(strict_types=1);

namespace Empira\Tests;

use Empira\Application;
use Empira\Console;
use Empira\Console\CommandProvider;
use Empira\Module;
use PHPUnit\Framework\TestCase;

/**
 * A module shipping console commands.
 *
 * The interface is opt-in, so the first thing worth proving is that opting out
 * changes nothing: most modules have no commands, and an interface every module
 * had to implement to return an empty array would be one that teaches nothing.
 */
final class CommandProviderTest extends TestCase
{
    private function application(): Application
    {
        return new Application(secret: 'a-secret-long-enough-for-testing');
    }

    public function testAModuleContributesItsCommands(): void
    {
        $app = $this->application();

        $app->module(new class implements Module, CommandProvider {
            public function register(Application $app): void {}

            public function commands(): array
            {
                return ['backup:run' => new class implements Console\Command {
                    public function name(): string
                    {
                        return 'backup:run';
                    }

                    public function description(): string
                    {
                        return 'Take a backup.';
                    }

                    public function arguments(): array
                    {
                        return [];
                    }

                    public function handle(array $options, Console\Output $output): int
                    {
                        return 0;
                    }
                }];
            }
        });

        self::assertArrayHasKey('backup:run', $app->commands());
    }

    /**
     * The opt-in half, stated as a test rather than left to the type system: a
     * module that says nothing about commands registers none, and nothing in
     * core has to care.
     */
    public function testAModuleThatSaysNothingContributesNothing(): void
    {
        $app = $this->application();

        $app->module(new class implements Module {
            public function register(Application $app): void {}
        });

        self::assertSame([], $app->commands());
    }

    public function testTwoModulesOfferingOneNameIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('A command called "backup:run" is already registered.');

        $app = $this->application();

        $app->module($this->provider('backup:run'));
        $app->module($this->provider('backup:run'));
    }

    /**
     * A module cannot take a name the framework already owns — but the check
     * happens when the console is built, not when the module registers.
     *
     * `Application` cannot know which names `empira` ships; it would have to hold
     * a copy of the console's list, and drift from it. So the refusal belongs to
     * the one class that has the list, and it names the command rather than
     * leaving a collision to be discovered by whichever registration ran last.
     */
    public function testAModuleCannotTakeTheNameOfAFrameworkCommand(): void
    {
        $app = $this->application();

        $app->module($this->provider('migrate'));

        $console = new Console\Application();

        $this->expectException(\InvalidArgumentException::class);

        foreach ($app->commands() as $name => $command) {
            $console->project($name, $command);
        }
    }

    /**
     * Commands are collected after `register()` runs, so a module whose commands
     * are built from something it wired up itself still works.
     */
    public function testCommandsAreCollectedAfterTheModuleIsRegistered(): void
    {
        $module = new class implements Module, CommandProvider {
            /** @var list<string> */
            public array $observed = [];

            public function register(Application $app): void
            {
                $this->observed[] = 'registered';
            }

            public function commands(): array
            {
                $this->observed[] = 'commands';

                return [];
            }
        };

        $this->application()->module($module);

        self::assertSame(['registered', 'commands'], $module->observed);
    }

    private function provider(string $name): CommandProvider
    {
        return new class($name) implements Module, CommandProvider {
            public function __construct(private readonly string $name) {}

            public function register(Application $app): void {}

            public function commands(): array
            {
                return [$this->name => new class($this->name) implements Console\Command {
                    public function __construct(private readonly string $name) {}

                    public function name(): string
                    {
                        return $this->name;
                    }

                    public function description(): string
                    {
                        return 'Test command.';
                    }

                    public function arguments(): array
                    {
                        return [];
                    }

                    public function handle(array $options, Console\Output $output): int
                    {
                        return 0;
                    }
                }];
            }
        };
    }
}