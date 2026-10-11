<?php

declare(strict_types=1);

namespace IamAdty\Empira\Tests;

use IamAdty\Empira\Application;
use IamAdty\Empira\Console\Application as ConsoleApplication;
use IamAdty\Empira\Console\Bootstrap;
use IamAdty\Empira\Console\Command;
use IamAdty\Empira\Console\Output;
use IamAdty\Empira\Tests\Fixtures\MemoryStore;
use PHPUnit\Framework\TestCase;

/**
 * Commands the project supplies itself.
 *
 * `empira seed` exists because the application registered it. The framework has
 * no opinion about what anyone's seeders contain, and guessing would be worse
 * than not having the command at all.
 */
final class ProjectCommandTest extends TestCase
{
    /** @var list<string> */
    private array $temporary = [];

    protected function tearDown(): void
    {
        foreach ($this->temporary as $path) {
            // Recursive, because these directories hold a `bootstrap/` subtree
            // and a plain rmdir() on a non-empty one warns on every run.
            Temp::remove($path);
        }

        $this->temporary = [];
    }

    public function testAProjectCommandIsListedMarkedAsTheProjects(): void
    {
        $result = $this->console(['empira', 'list'], $this->withCommand('ping'));

        self::assertStringContainsString('ping', $result['output']);

        // Marked, because a name somebody spent an afternoon on should be
        // recognisable as theirs without going and looking.
        self::assertMatchesRegularExpression('/ping.*\(project\)/', $result['output']);

        // …and a framework command is not.
        self::assertDoesNotMatchRegularExpression('/make:component.*\(project\)/', $result['output']);
    }

    public function testAProjectCommandRunsWhenItIsNamed(): void
    {
        $result = $this->console(['empira', 'ping', '--loud'], $this->withCommand('ping'));

        self::assertSame(0, $result['code'], $result['output']);
        self::assertStringContainsString('pong', $result['output']);

        // Options reach it, which is the reason it is a Command and not a hook.
        self::assertStringContainsString('loud', $result['output']);
    }

    public function testAProjectCommandCannotTakeTheNameOfAFrameworkOne(): void
    {
        $console = new ConsoleApplication();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('already provides "migrate"');

        $console->project('migrate', $this->ping());
    }

    public function testAnApplicationRefusesToRegisterTheSameNameTwice(): void
    {
        $app = new Application('test-secret-that-is-long-enough-for-hmac!!', new MemoryStore());

        $app->command('ping', $this->ping());

        $this->expectException(\InvalidArgumentException::class);

        $app->command('ping', $this->ping());
    }

    public function testADirectoryWithNoBootstrapSimplyHasNoProjectCommands(): void
    {
        // `make:component` has to work in a checkout that has no application at
        // all, so "no bootstrap" is not an error.
        self::assertSame([], Bootstrap::commandsFor($this->emptyDirectory()));
    }

    public function testABootstrapThatDoesNotReturnAnApplicationIsRefused(): void
    {
        // Returning the wrong thing is a mistake worth naming, not something to
        // discover as a type error three lines later.
        $directory = $this->emptyDirectory();
        file_put_contents($directory . '/' . Bootstrap::DEFAULT_PATH, "<?php\n\nreturn 'not an application';\n");

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('must return the booted');

        Bootstrap::commandsFor($directory);
    }

    public function testBootstrapPointingAtNothingIsRefusedByName(): void
    {
        $previous = $_ENV['EMPIRA_BOOTSTRAP'] ?? null;
        $_ENV['EMPIRA_BOOTSTRAP'] = '/nowhere/at/all/app.php';

        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('EMPIRA_BOOTSTRAP points at');

            Bootstrap::commandsFor($this->emptyDirectory());
        } finally {
            if ($previous === null) {
                unset($_ENV['EMPIRA_BOOTSTRAP']);
            } else {
                $_ENV['EMPIRA_BOOTSTRAP'] = $previous;
            }
        }
    }

    // ------------------------------------------------------------- helpers

    private function withCommand(string $name): ConsoleApplication
    {
        $console = new ConsoleApplication();
        $console->project($name, $this->ping());

        return $console;
    }

    private function ping(): Command
    {
        return new class implements Command {
            public function name(): string
            {
                return 'ping';
            }

            public function description(): string
            {
                return 'Check the app boots.';
            }

            public function arguments(): array
            {
                return ['[--loud]'];
            }

            public function handle(array $options, Output $output): int
            {
                $output->line('pong' . (isset($options['loud']) ? ' (loud)' : ''));

                return 0;
            }
        };
    }

    private function console(array $argv, ConsoleApplication $console): array
    {
        $stream = fopen('php://memory', 'w+');

        $code = $console->run($argv, new Output($stream));

        rewind($stream);
        $output = (string) stream_get_contents($stream);
        fclose($stream);

        return ['code' => $code, 'output' => $output];
    }

    private function directory(): string
    {
        $directory = Temp::dir('empira-bootstrap-');
        $this->temporary[] = $directory;

        return $directory;
    }

    /** A directory with a `bootstrap/` in it, but no bootstrap file. */
    private function emptyDirectory(): string
    {
        $directory = $this->directory();
        mkdir($directory . '/bootstrap');

        return $directory;
    }
}