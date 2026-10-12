<?php

declare(strict_types=1);

namespace Empira\Console\Commands;

use Empira\Console\Command;
use Empira\Console\Output;

/**
 * Writes a component class into `src/components`.
 *
 * The generated file is a working counter, not a stub: a `#[Prop]`, a `#[Post]`
 * action and a `render()` that reaches for `$this->hx`. A token cannot reach an
 * unregistered class, so the command also prints the one line needed to
 * register it.
 */
final class MakeComponent implements Command
{
    /**
     * The shape written to disk. Nowdoc rather than a sprintf so the braces and
     * the `#[Prop]` syntax are copied through untouched.
     */
    private const TEMPLATE = <<<'PHP'
    <?php

    declare(strict_types=1);

    namespace {{NAMESPACE}};

    use Empira\Attributes\Post;
    use Empira\Attributes\Prop;
    use Empira\Component;
    use Empira\Element;

    /**
     * {{CLASS}} — a component with one piece of state and one action.
     *
     * No `hx-*` attribute is written by hand: `action()` asks the component
     * for the endpoint and the component supplies the target and the swap
     * strategy. `#[Prop]` state travels inside the signed token, so a client
     * cannot change `$count` by editing a URL.
     *
     * A token cannot name a class the registry does not know, so register the
     * component before the first request arrives:
     *
     *     $app->registry()->alias('{{ALIAS}}', {{CLASS}}::class);
     */
    final class {{CLASS}} extends Component
    {
        #[Prop]
        public int $count = 0;

        #[Post]
        public function increment(): void
        {
            $this->count++;
        }

        protected function render(): Element
        {
            return $this->hx->div()
                ->class('counter')
                ->append($this->hx->strong()->text((string) $this->count))
                ->append($this->hx->button('+1')->action('increment'));
        }
    }

    PHP;

    public function name(): string
    {
        return 'make:component';
    }

    public function description(): string
    {
        return 'Write a component class to src/components.';
    }

    public function arguments(): array
    {
        return ['<Name>', '[--force]', '[--target=dir]', '[--namespace=App\\Components]'];
    }

    public function handle(array $options, Output $output): int
    {
        $name = self::className($options['arguments'][0] ?? null);

        if ($name === null) {
            $output->error('Usage: empira ' . $this->name() . ' <Name> [--force] [--target=dir]');

            return 1;
        }

        $namespace = self::namespace((string) ($options['namespace'] ?? 'App\\Components'));
        $path = self::target($options) . '/src/components/' . $name . '.php';

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
            '{{ALIAS}}' => self::alias($name),
        ]);

        if (file_put_contents($path, $source) === false) {
            $output->error(sprintf('Could not write %s.', $path));

            return 1;
        }

        $output->success(sprintf('Created %s', $path));
        $output->line(sprintf(
            'Register it with: $app->registry()->alias(\'%s\', %s::class);',
            self::alias($name),
            $namespace . '\\' . $name
        ));

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
     * A namespace without its leading or trailing separator.
     */
    private static function namespace(string $namespace): string
    {
        return trim($namespace, '\\');
    }

    /**
     * The short registry alias for a class name: `TodoList` becomes `todo-list`.
     */
    private static function alias(string $name): string
    {
        return strtolower((string) preg_replace('/(?<!^)[A-Z]/', '-$0', $name));
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
