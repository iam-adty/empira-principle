<?php

declare(strict_types=1);

namespace Empira\Tests;

use Empira\Application;
use PHPUnit\Framework\TestCase;

/**
 * Where the framework thinks the application is.
 *
 * Two mechanisms, deliberately: `EMPIRA_APP_ROOT` when an entry point states a
 * fact, and reflection when nothing does. They fail in opposite ways — a
 * constant cannot be wrong but can be missing, reflection cannot be missing but
 * can be wrong — and that difference is what these tests are about.
 *
 * Every test is a subprocess. A constant cannot be defined and undefined inside
 * one process, so an in-process test of its *precedence* could only pass by
 * accident of ordering — which is to say it would pass, and prove nothing.
 *
 * The classes under test are written to temporary directories rather than
 * committed, because their whole property is *where they sit*. A fixture in
 * `tests/` would be under a root with a `src/` in it and could not exercise the
 * refusal at all.
 */
final class BasePathTest extends TestCase
{
    /** @var list<string> */
    private array $scratch = [];

    protected function tearDown(): void
    {
        foreach ($this->scratch as $path) {
            Temp::remove($path);
        }

        $this->scratch = [];
    }

    /**
     * A class whose file is not under `<root>/src/` is refused by name.
     *
     * The failure this prevents is silent and expensive: everything written
     * under `basePath()` — the SQLite database, the profiler's recordings —
     * goes to a directory nobody is looking, and no query fails. That is why it
     * is checked rather than assumed.
     */
    public function testARootWithoutASrcDirectoryIsRefused(): void
    {
        // `<scratch>/odd/nested/Placed.php` → root is `<scratch>/odd`, which has
        // no `src/` in it.
        $output = $this->inAProcess(<<<'PHP'
            <?php
            require $argv[1] . '/vendor/autoload.php';
            require $argv[2];

            echo \Oddly\Placed::where();
            PHP, [$this->classAt('Oddly\Placed', $this->scratch(), 'odd/nested/Placed.php')]);

        self::assertStringContainsString('does not look like an application root', $output);
        self::assertStringContainsString('EMPIRA_APP_ROOT', $output, 'the refusal must name both remedies');
        self::assertStringContainsString('<root>/src/', $output, 'and say where the class belongs');
    }

    /**
     * The same class under a root that *has* a `src/` resolves.
     *
     * Without this the guard could be an unconditional throw and the test above
     * would still be green.
     */
    public function testARootWithASrcDirectoryResolves(): void
    {
        $app = $this->scratch();

        $output = $this->inAProcess(<<<'PHP'
            <?php
            require $argv[1] . '/vendor/autoload.php';
            require $argv[2];

            echo \Well\Placed::where();
            PHP, [$this->classAt('Well\Placed', $app, 'src/Placed.php')]);

        self::assertSame($app, trim($output));
    }

    /**
     * A relative path is appended; an absolute one is not doubled up.
     */
    public function testARelativePathIsAppendedToTheRoot(): void
    {
        $app = $this->scratch();

        $output = $this->inAProcess(<<<'PHP'
            <?php
            require $argv[1] . '/vendor/autoload.php';
            require $argv[2];

            echo \Well\Placed::where('storage/app.sqlite');
            PHP, [$this->classAt('Well\Placed', $app, 'src/Placed.php')]);

        self::assertSame($app . '/storage/app.sqlite', trim($output));
    }

    /**
     * The constant wins over reflection.
     *
     * A deployment laid out differently from its source tree is exactly what
     * the constant is for, and it has to beat the convention — otherwise the one
     * case where an entry point was told something useful would be the one case
     * ignored. It also has to beat a root reflection would have *refused*, which
     * is why this reuses the class the previous test rejects.
     */
    public function testTheConstantWinsOverReflection(): void
    {
        $elsewhere = $this->scratch();

        $output = $this->inAProcess(<<<'PHP'
            <?php
            require $argv[1] . '/vendor/autoload.php';
            define('EMPIRA_APP_ROOT', $argv[2]);
            require $argv[3];

            echo \Oddly\Placed::where();
            PHP, [$elsewhere, $this->classAt('Oddly\Placed', $this->scratch(), 'odd/nested/Placed.php')]);

        self::assertSame($elsewhere, trim($output));
    }

    // ---------------------------------------------------------------- helpers

    private function scratch(): string
    {
        $path = Temp::dir('empira-base-path-');
        $this->scratch[] = $path;

        return $path;
    }

    /**
     * Write an `Application` subclass under `$root` and return its path.
     *
     * The caller chooses the depth, which is the whole point — `basePath()`
     * reads two levels up from this file, so where it sits *is* the value under
     * test.
     */
    private function classAt(string $class, string $root, string $relative): string
    {
        $path = $root . '/' . $relative;
        $namespace = substr($class, 0, (int) strrpos($class, '\\'));

        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0o777, true);
        }

        file_put_contents($path, sprintf(
            "<?php\n\ndeclare(strict_types=1);\n\nnamespace %s;\n\nuse Empira\\Application;\n\n"
            . "final class %s extends Application\n{\n"
            . "    public static function where(string \$relative = ''): string\n"
            . "    {\n        return static::basePath(\$relative);\n    }\n}\n",
            $namespace,
            substr($class, (int) strrpos($class, '\\') + 1),
        ));

        return $path;
    }

    /**
     * @param list<string> $extra paths after the package root
     */
    private function inAProcess(string $code, array $extra = []): string
    {
        $file = Temp::file('empira-base-path-probe-');
        file_put_contents($file, $code);

        $output = (string) shell_exec(sprintf(
            '%s %s %s %s 2>&1',
            escapeshellarg(PHP_BINARY),
            escapeshellarg($file),
            escapeshellarg(dirname(__DIR__)),
            implode(' ', array_map(escapeshellarg(...), $extra)),
        ));

        Temp::remove($file);

        return $output;
    }
}