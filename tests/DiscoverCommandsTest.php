<?php

declare(strict_types=1);

namespace Empira\Tests;

use Empira\Application;
use Empira\Console;
use Empira\State\NullState;
use Empira\Tests\Temp;
use PHPUnit\Framework\TestCase;

/**
 * Console commands found by existing rather than by being registered.
 *
 * The fixtures are written to a temporary directory and loaded through a
 * registered autoloader, exactly as `RouteDiscoveryTest` does it for routes —
 * discovery has to be proved against a real path, because the path *is* the
 * mechanism. A command built in an anonymous class would test the container and
 * nothing about the scanner.
 */
final class DiscoverCommandsTest extends TestCase
{
    private const SECRET = 'a-secret-long-enough-for-testing';

    /** @var array<class-string, string> */
    private static array $fixturePaths = [];

    private static bool $loaderRegistered = false;

    private string $directory;

    public static function setUpBeforeClass(): void
    {
        self::registerFixtureLoader();
    }

    protected function setUp(): void
    {
        $this->directory = Temp::dir('empira-commands-');
    }

    protected function tearDown(): void
    {
        Temp::remove($this->directory);
    }

    /**
     * @param class-string $class
     */
    private function write(string $relative, string $class, string $body): void
    {
        $path = $this->directory . '/' . $relative;

        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0o777, true);
        }

        file_put_contents($path, sprintf(
            "<?php\n\ndeclare(strict_types=1);\n\nnamespace %s;\n\nuse Empira\\Console\\Command;\nuse Empira\\Console\\Output;\n\n%s\n",
            'Empira\\Fixtures\\Console',
            $body
        ));

        self::$fixturePaths[$class] = $path;
    }

    private function app(): Application
    {
        return new Application(self::SECRET, new NullState());
    }

    private static function registerFixtureLoader(): void
    {
        if (self::$loaderRegistered) {
            return;
        }

        self::$loaderRegistered = true;

        spl_autoload_register(static function (string $class): void {
            $path = self::$fixturePaths[$class] ?? null;

            if ($path !== null && is_file($path)) {
                require_once $path;
            }
        });
    }

    // -------------------------------------------------------------- discovery

    public function testACommandInTheDirectoryIsRegisteredUnderItsOwnName(): void
    {
        $this->write('Purge.php', 'Empira\Fixtures\Console\Purge', <<<'PHP'
            final class Purge implements Command
            {
                public function name(): string
                {
                    return 'purge';
                }

                public function description(): string
                {
                    return 'Purge everything.';
                }

                public function arguments(): array
                {
                    return [];
                }

                public function handle(array $options, Output $output): int
                {
                    return 0;
                }
            }
            PHP);

        $app = $this->app()->discoverCommands($this->directory, 'Empira\Fixtures\Console');

        self::assertArrayHasKey('purge', $app->commands());
    }

    /**
     * The name comes from the command, not the file.
     *
     * A class called `Vacuum` is invoked as `db:vacuum` here. Deriving the name
     * from the basename would make the file name silently load-bearing —
     * renaming a class would break the command name, and nothing would say so.
     *
     * The class is named differently from the one above on purpose: a fixture is
     * `require_once`'d and never unloaded, so two tests declaring the same class
     * name would share the first one's body and this would quietly pass against
     * the wrong code.
     */
    public function testTheNameComesFromTheCommandRatherThanTheFilename(): void
    {
        $this->write('Vacuum.php', 'Empira\Fixtures\Console\Vacuum', <<<'PHP'
            final class Vacuum implements Command
            {
                public function name(): string
                {
                    return 'db:vacuum';
                }

                public function description(): string
                {
                    return 'Vacuum the database.';
                }

                public function arguments(): array
                {
                    return [];
                }

                public function handle(array $options, Output $output): int
                {
                    return 0;
                }
            }
            PHP);

        $app = $this->app()->discoverCommands($this->directory, 'Empira\Fixtures\Console');

        self::assertArrayHasKey('db:vacuum', $app->commands());
        self::assertArrayNotHasKey('Vacuum', $app->commands());
    }

    /**
     * Commands are built by the container, so one can ask for what it needs.
     *
     * Without this, a command that touches the database would have to be built
     * by the caller — which is the registration this method exists to remove.
     */
    public function testACommandIsBuiltWithItsDependencies(): void
    {
        $this->write('NeedsApp.php', 'Empira\Fixtures\Console\NeedsApp', <<<'PHP'
            use Empira\Application;

            final class NeedsApp implements Command
            {
                public function __construct(public readonly Application $app) {}

                public function name(): string
                {
                    return 'needs:app';
                }

                public function description(): string
                {
                    return 'Wants the application.';
                }

                public function arguments(): array
                {
                    return [];
                }

                public function handle(array $options, Output $output): int
                {
                    return 0;
                }
            }
            PHP);

        $app = $this->app();
        $app->discoverCommands($this->directory, 'Empira\Fixtures\Console');

        $command = $app->commands()['needs:app'];

        self::assertInstanceOf(\Empira\Fixtures\Console\NeedsApp::class, $command);
        self::assertSame($app, $command->app);
    }

    /**
     * A directory that holds helpers is not an error.
     *
     * `app/Console/` is a place people will put things that are not commands, and
     * refusing to boot because one of them does not implement the interface would
     * make the convention a straitjacket rather than a convention.
     */
    public function testAClassThatIsNotACommandIsSkipped(): void
    {
        $this->write('Helper.php', 'Empira\Fixtures\Console\Helper', <<<'PHP'
            final class Helper
            {
                public function help(): string
                {
                    return 'not a command';
                }
            }
            PHP);

        $app = $this->app()->discoverCommands($this->directory, 'Empira\Fixtures\Console');

        self::assertSame([], $app->commands());
    }

    /**
     * An application with no commands has no console directory, and asking about
     * a path nobody created should answer with none rather than throw.
     */
    public function testAMissingDirectoryIsNotAnError(): void
    {
        $app = $this->app()->discoverCommands($this->directory . '/does-not-exist', 'Empira\Fixtures\Console');

        self::assertSame([], $app->commands());
    }

    /**
     * Two commands answering to one name is refused by name.
     *
     * Which one won would otherwise depend on directory iteration order, and the
     * symptom would be an operator's command silently not being the one that
     * runs.
     */
    public function testTwoCommandsWithOneNameIsRefused(): void
    {
        $body = <<<'PHP'
            final class %s implements Command
            {
                public function name(): string
                {
                    return 'duplicate';
                }

                public function description(): string
                {
                    return 'One of two.';
                }

                public function arguments(): array
                {
                    return [];
                }

                public function handle(array $options, Output $output): int
                {
                    return 0;
                }
            }
            PHP;

        $this->write('First.php', 'Empira\Fixtures\Console\First', sprintf($body, 'First'));
        $this->write('Second.php', 'Empira\Fixtures\Console\Second', sprintf($body, 'Second'));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('A command called "duplicate" is already registered.');

        $this->app()->discoverCommands($this->directory, 'Empira\Fixtures\Console');
    }

    public function testDiscoveryIsChainable(): void
    {
        $app = $this->app();

        self::assertSame($app, $app->discoverCommands($this->directory, 'Empira\Fixtures\Console'));
    }
}