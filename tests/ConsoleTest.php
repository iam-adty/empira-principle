<?php

declare(strict_types=1);

namespace IamAdty\Empira\Tests;

use IamAdty\Empira\Console\Application;
use IamAdty\Empira\Console\Output;
use PHPUnit\Framework\TestCase;

final class ConsoleTest extends TestCase
{
    /**
     * @var list<string> directories created by a test, removed on the way out
     */
    private array $temporary = [];

    protected function tearDown(): void
    {
        foreach ($this->temporary as $directory) {
            self::remove($directory);
        }

        $this->temporary = [];
    }

    public function testItListsEveryRegisteredCommand(): void
    {
        $result = $this->console(['empira', 'list']);

        $this->assertSame(0, $result['code']);

        foreach (['list', 'make:component', 'make:controller', 'route:list', 'serve'] as $command) {
            $this->assertStringContainsString($command, $result['output']);
        }

        $this->assertStringContainsString('Write a component class to src/components.', $result['output']);
    }

    public function testNoArgumentsAndHelpBothListTheCommands(): void
    {
        $this->assertStringContainsString('route:list', $this->console([])['output']);
        $this->assertStringContainsString('route:list', $this->console(['empira', '--help'])['output']);
        $this->assertStringContainsString('route:list', $this->console(['empira', '-h'])['output']);

        $this->assertSame(0, $this->console([])['code']);
        $this->assertSame(0, $this->console(['empira', '--help'])['code']);
    }

    public function testAnUnknownCommandFailsAndSuggestsWhatIsAvailable(): void
    {
        $result = $this->console(['empira', 'make:widget']);

        $this->assertSame(1, $result['code']);
        $this->assertStringContainsString('Unknown command "make:widget".', $result['output']);
        $this->assertStringContainsString('make:component', $result['output']);
        $this->assertStringContainsString('serve', $result['output']);
    }

    public function testMakeComponentWritesValidPhp(): void
    {
        $target = $this->tempdir();
        $path = $target . '/src/components/Counter.php';

        $result = $this->console(['empira', 'make:component', 'Counter', '--target=' . $target]);

        $this->assertSame(0, $result['code']);
        $this->assertFileExists($path);

        $contents = file_get_contents($path);
        $this->assertIsString($contents);

        $this->assertSame(0, self::lint($path), 'generated component must be valid PHP');

        $this->assertStringContainsString('declare(strict_types=1);', $contents);
        $this->assertStringContainsString('namespace App\Components;', $contents);
        $this->assertStringContainsString('use IamAdty\Empira\Component;', $contents);
        $this->assertStringContainsString('final class Counter extends Component', $contents);
        $this->assertStringContainsString('use IamAdty\Empira\Attributes\Prop;', $contents);
        $this->assertStringContainsString("#[Prop]\n    public int \$count = 0;", $contents);
        $this->assertStringContainsString("#[Post]\n    public function increment(): void", $contents);
        $this->assertStringContainsString("protected function render(): Element", $contents);
        $this->assertStringContainsString("\$this->hx->button('+1')->action('increment')", $contents);
    }

    public function testMakeComponentWillNotOverwriteWithoutForce(): void
    {
        $target = $this->tempdir();
        $path = $target . '/src/components/Counter.php';

        $this->assertSame(0, $this->console(['empira', 'make:component', 'Counter', '--target=' . $target])['code']);

        $first = file_get_contents($path);

        $second = $this->console(['empira', 'make:component', 'Counter', '--target=' . $target]);

        $this->assertSame(1, $second['code']);
        $this->assertStringContainsString('already exists', $second['output']);
        $this->assertStringContainsString('--force', $second['output']);
        $this->assertSame($first, file_get_contents($path));
    }

    public function testMakeComponentOverwritesWithForce(): void
    {
        $target = $this->tempdir();
        $path = $target . '/src/components/Counter.php';

        mkdir($target . '/src/components', 0o777, true);
        file_put_contents($path, "<?php\n\n// hand-written\n");

        $result = $this->console(['empira', 'make:component', 'Counter', '--force', '--target=' . $target]);

        $this->assertSame(0, $result['code']);
        $this->assertSame(0, self::lint($path));

        $contents = file_get_contents($path);
        $this->assertIsString($contents);
        $this->assertStringNotContainsString('hand-written', $contents);
        $this->assertStringContainsString('final class Counter extends Component', $contents);
    }

    public function testMakeComponentRefusesAnUnusableClassName(): void
    {
        $target = $this->tempdir();

        $result = $this->console(['empira', 'make:component', '../Evil', '--target=' . $target]);

        $this->assertSame(1, $result['code']);
        $this->assertStringContainsString('Usage: empira make:component <Name>', $result['output']);
        $this->assertFileDoesNotExist($target . '/src/components/../Evil.php');
    }

    public function testMakeControllerWritesAValidController(): void
    {
        $target = $this->tempdir();
        $path = $target . '/src/controllers/PostsController.php';

        $result = $this->console(['empira', 'make:controller', 'PostsController', '--target=' . $target]);

        $this->assertSame(0, $result['code']);
        $this->assertSame(0, self::lint($path));

        $contents = file_get_contents($path);
        $this->assertIsString($contents);

        $this->assertStringContainsString('namespace App\Controllers;', $contents);
        $this->assertStringContainsString('use IamAdty\Empira\Routing\Controller;', $contents);
        $this->assertStringContainsString('use IamAdty\Empira\Http\Response;', $contents);
        $this->assertStringContainsString('final class PostsController extends Controller', $contents);
        $this->assertStringContainsString('public function index(): Response', $contents);
    }

    public function testMakeControllerHonoursTheNamespaceOption(): void
    {
        $target = $this->tempdir();
        $path = $target . '/src/controllers/Posts.php';

        $result = $this->console([
            'empira',
            'make:controller',
            'Posts',
            '--namespace=Blog\Http',
            '--target=' . $target,
        ]);

        $this->assertSame(0, $result['code']);
        $this->assertSame(0, self::lint($path));

        $contents = file_get_contents($path);
        $this->assertIsString($contents);
        $this->assertStringContainsString('namespace Blog\Http;', $contents);
    }

    public function testMigrateRunsAMigrationsDirectory(): void
    {
        $target = $this->tempdir();
        mkdir($target . '/migrations', 0o777, true);

        file_put_contents($target . '/migrations/2026_10_11_000001_widgets.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            use IamAdty\Empira\Data\Connection;
            use IamAdty\Empira\Data\Migration;

            return new class implements Migration
            {
                public function up(Connection $c): void
                {
                    $c->execute('CREATE TABLE widgets (id INTEGER PRIMARY KEY)');
                }

                public function down(Connection $c): void
                {
                    $c->execute('DROP TABLE widgets');
                }
            };
            PHP);

        $result = $this->console(['empira', 'migrate', '--target=' . $target]);

        $this->assertSame(0, $result['code']);
        $this->assertStringContainsString('2026_10_11_000001_widgets', $result['output']);
    }

    public function testMigrateSaysSoWhenThereIsNoMigrationsDirectory(): void
    {
        $result = $this->console(['empira', 'migrate', '--target=' . $this->tempdir()]);

        $this->assertSame(1, $result['code']);
        $this->assertStringContainsString('No migrations directory', $result['output']);
    }

    public function testRouteListPrintsTheRoutesAnApplicationRegisters(): void
    {
        $target = $this->tempdir();

        mkdir($target . '/app', 0o777, true);
        file_put_contents($target . '/routes.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            use IamAdty\Empira\Routing\RouteCollection;

            return static function (RouteCollection $routes): void {
                $routes->get('/', static fn(): string => 'home', 'home');
                $routes->post('/posts', [\App\Controllers\PostsController::class, 'store'], 'posts.store');
            };
            PHP);

        $result = $this->console(['empira', 'route:list', '--app=' . $target . '/routes.php']);

        $this->assertSame(0, $result['code']);
        $this->assertStringContainsString('METHOD', $result['output']);
        $this->assertStringContainsString('GET', $result['output']);
        $this->assertStringContainsString('/posts', $result['output']);
        $this->assertStringContainsString('posts.store', $result['output']);
        $this->assertStringContainsString('App\Controllers\PostsController@store', $result['output']);
    }

    public function testRouteListFindsTheApplicationInTheTargetDirectory(): void
    {
        $target = $this->tempdir();

        mkdir($target . '/app', 0o777, true);
        file_put_contents($target . '/routes.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            return static function (\IamAdty\Empira\Routing\RouteCollection $routes): void {
                $routes->get('/', static fn(): string => 'home', 'home');
            };
            PHP);

        $result = $this->console(['empira', 'route:list', '--target=' . $target]);

        $this->assertSame(0, $result['code']);
        $this->assertStringContainsString('home', $result['output']);
    }

    public function testRouteListExplainsAMissingApplication(): void
    {
        $result = $this->console(['empira', 'route:list', '--target=' . $this->tempdir()]);

        $this->assertSame(1, $result['code']);
        $this->assertStringContainsString('No application bootstrap at', $result['output']);
        $this->assertStringContainsString('--app=path/to/app.php', $result['output']);
    }

    public function testRouteListExplainsABrokenApplication(): void
    {
        $target = $this->tempdir();
        $broken = $target . '/app.php';

        file_put_contents($broken, "<?php\n\nthrow new \\RuntimeException('no routes for you');\n");

        $result = $this->console(['empira', 'route:list', '--app=' . $broken]);

        $this->assertSame(1, $result['code']);
        $this->assertStringContainsString('Could not load', $result['output']);
        $this->assertStringContainsString('no routes for you', $result['output']);
    }

    public function testRouteListExplainsABootstrapThatReturnsSomethingElse(): void
    {
        $target = $this->tempdir();
        $wrong = $target . '/app.php';

        file_put_contents($wrong, "<?php\n\nreturn 'not an application';\n");

        $result = $this->console(['empira', 'route:list', '--app=' . $wrong]);

        $this->assertSame(1, $result['code']);
        $this->assertStringContainsString('did not return an Application', $result['output']);
    }

    public function testRouteListBootsAnApplicationInsteadOfRequiringARouteFile(): void
    {
        $target = $this->tempdir();

        mkdir($target . '/src', 0o777, true);

        file_put_contents($target . '/src/App.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace Demo;

            use IamAdty\Empira\Application;
            use IamAdty\Empira\Routing\RouteCollection;
            use IamAdty\Empira\State\NullState;

            final class App
            {
                public static function boot(): Application
                {
                    $app = new Application('a-secret-long-enough-for-this-test', new NullState());

                    $routes = $app->routes();
                    $routes->get('/health', static fn(): string => 'ok', 'health');

                    return $app;
                }
            }
            PHP);

        $result = $this->console(['empira', 'route:list', '--target=' . $target]);

        $this->assertSame(0, $result['code']);
        $this->assertStringContainsString('/health', $result['output']);
        $this->assertStringContainsString('health', $result['output']);
    }

    public function testRouteListWarnsWhenNothingIsRegistered(): void
    {
        $target = $this->tempdir();
        $empty = $target . '/routes.php';

        file_put_contents($empty, "<?php\n\nreturn static fn(\\IamAdty\Empira\\Routing\\RouteCollection \$routes): null => null;\n");

        $result = $this->console(['empira', 'route:list', '--app=' . $empty]);

        $this->assertSame(0, $result['code']);
        $this->assertStringContainsString('No routes registered.', $result['output']);
    }

    public function testServeRefusesADirectoryWithoutAFrontController(): void
    {
        $result = $this->console(['empira', 'serve', '--target=' . $this->tempdir()]);

        $this->assertSame(1, $result['code']);
        $this->assertStringContainsString('No front controller at', $result['output']);
    }

    public function testServeRejectsAPortThatIsNotANumber(): void
    {
        $result = $this->console(['empira', 'serve', '--port=banana']);

        $this->assertSame(1, $result['code']);
        $this->assertStringContainsString('is not a valid port number', $result['output']);
    }

    public function testOutputWritesNoAnsiCodesWhenTheStreamIsNotATerminal(): void
    {
        $stream = fopen('php://memory', 'w+');

        $this->assertIsResource($stream);

        $output = new Output($stream);

        $output->line('plain');
        $output->success('done');
        $output->error('broken');
        $output->info('fyi');
        $output->warn('careful');

        rewind($stream);
        $written = stream_get_contents($stream);

        $this->assertIsString($written);
        $this->assertFalse($output->isColoured());
        $this->assertStringNotContainsString("\033", $written);
        $this->assertStringContainsString('done', $written);
        $this->assertStringContainsString('broken', $written);
        $this->assertStringContainsString('fyi', $written);
        $this->assertStringContainsString('careful', $written);

        fclose($stream);
    }

    public function testOutputColoursOnlyWhenAskedTo(): void
    {
        $stream = fopen('php://memory', 'w+');

        $this->assertIsResource($stream);

        $output = new Output($stream, true);

        $output->success('done');

        rewind($stream);
        $written = stream_get_contents($stream);

        $this->assertIsString($written);
        $this->assertTrue($output->isColoured());
        $this->assertStringContainsString("\033[32m", $written);

        fclose($stream);
    }

    public function testEveryLineEndsWithNewline(): void
    {
        $stream = fopen('php://memory', 'w+');

        $this->assertIsResource($stream);

        (new Output($stream))->line('one');
        (new Output($stream))->line('two');

        rewind($stream);

        $this->assertSame('one' . PHP_EOL . 'two' . PHP_EOL, stream_get_contents($stream));

        fclose($stream);
    }

    public function testEveryRegisteredCommandDeclaresItself(): void
    {
        $commands = (new Application())->commands();

        $this->assertSame(['make:component', 'make:controller', 'migrate', 'route:list', 'serve'], array_keys($commands));

        foreach ($commands as $name => $command) {
            $this->assertSame($name, $command->name());
            $this->assertNotSame('', $command->description());
        }
    }

    /**
     * Run the console against an in-memory stream.
     *
     * @param list<string> $argv
     *
     * @return array{code: int, output: string}
     */
    private function console(array $argv): array
    {
        $stream = fopen('php://memory', 'w+');

        $this->assertIsResource($stream);

        $code = (new Application())->run($argv, new Output($stream));

        rewind($stream);
        $output = stream_get_contents($stream);

        fclose($stream);

        $this->assertIsString($output);

        return ['code' => $code, 'output' => $output];
    }

    /**
     * A fresh temporary directory, removed when the test finishes.
     */
    private function tempdir(): string
    {
        $directory = sys_get_temp_dir() . '/empira-console-' . bin2hex(random_bytes(6));

        mkdir($directory, 0o777, true);

        $this->temporary[] = $directory;

        return $directory;
    }

    /**
     * `php -l` on a file, as an exit code.
     */
    private static function lint(string $path): int
    {
        $status = 0;
        exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($path) . ' 2>&1', $ignored, $status);

        return $status;
    }

    /**
     * Delete a directory and everything under it.
     */
    private static function remove(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $entries = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($entries as $entry) {
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }

        rmdir($directory);
    }
}
