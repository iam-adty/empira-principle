<?php

declare(strict_types=1);

namespace Empira\Console\Commands;

use Empira\Console\Command;
use Empira\Console\Output;

/**
 * Writes a controller class into `src/controllers`.
 *
 * The stub answers with a real {@see \Empira\Http\Response} rather than a TODO,
 * because a controller that returns nothing is a controller that cannot be run.
 */
final class MakeController implements Command
{
    /**
     * The shape written to disk, indented so the closing marker strips it back
     * to column zero.
     */
    private const TEMPLATE = <<<'PHP'
    <?php

    declare(strict_types=1);

    namespace {{NAMESPACE}};

    use Empira\Http\Response;
    use Empira\Routing\Controller;

    /**
     * {{CLASS}} — handles the page traffic that is not a component action.
     *
     * Components answer their own `hx-*` requests, so a controller deals
     * with the full page, redirects and JSON. Point a route at it and the
     * container builds it, autowiring the constructor:
     *
     *     $routes->get('/{{SLUG}}', [{{CLASS}}::class, 'index'], '{{SLUG}}');
     */
    final class {{CLASS}} extends Controller
    {
        public function index(): Response
        {
            return Response::html('<h1>{{CLASS}}</h1>');
        }
    }

    PHP;

    public function name(): string
    {
        return 'make:controller';
    }

    public function description(): string
    {
        return 'Write a controller class to src/controllers.';
    }

    public function arguments(): array
    {
        return ['<Name>', '[--force]', '[--target=dir]', '[--namespace=App\\Controllers]'];
    }

    public function handle(array $options, Output $output): int
    {
        $name = self::className($options['arguments'][0] ?? null);

        if ($name === null) {
            $output->error('Usage: empira ' . $this->name() . ' <Name> [--force] [--target=dir]');

            return 1;
        }

        $namespace = trim((string) ($options['namespace'] ?? 'App\\Controllers'), '\\');
        $path = self::target($options) . '/src/controllers/' . $name . '.php';

        if (is_file($path) && !($options['force'] ?? false)) {
            $output->error(sprintf('%s already exists. Use --force to overwrite it.', $path));

            return 1;
        }

        $directory = dirname($path);

        if (!is_dir($directory) && !mkdir($directory, 0o777, true) && !is_dir($directory)) {
            $output->error(sprintf('Could not create %s.', $directory));

            return 1;
        }

        $source = strtr(self::TEMPLATE, [
            '{{NAMESPACE}}' => $namespace,
            '{{CLASS}}' => $name,
            '{{SLUG}}' => strtolower($name),
        ]);

        if (file_put_contents($path, $source) === false) {
            $output->error(sprintf('Could not write %s.', $path));

            return 1;
        }

        $output->success(sprintf('Created %s', $path));

        return 0;
    }

    /**
     * A class name, or null when the argument is missing or unusable.
     */
    private static function className(mixed $argument): ?string
    {
        if (!is_string($argument) || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $argument) !== 1) {
            return null;
        }

        return $argument;
    }

    /**
     * Where files are written: `--target`, or the working directory.
     *
     * @param array<string, mixed> $options
     */
    private static function target(array $options): string
    {
        $target = rtrim(trim((string) ($options['target'] ?? '')), '/');

        return $target === '' ? (getcwd() ?: '.') : $target;
    }
}
