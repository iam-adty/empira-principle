<?php

declare(strict_types=1);

namespace Empira\Tests;

use Empira\Application;
use PHPUnit\Framework\TestCase;

/**
 * Reading `.env`, and refusing to guess where the application is.
 *
 * The guarantee under test is an *ordering* one: the application's own keys are
 * stripped before the file is read, so an exported environment variable cannot
 * win. Getting it backwards makes `.env` silently override the deployment it was
 * meant to be overridden by — no error, no log, one answer on a developer's
 * machine and another on the server.
 */
final class LoadEnvironmentTest extends TestCase
{
    private string $root;

    /** @var list<string> */
    private array $keys = [];

    private bool $definedRoot = false;

    protected function setUp(): void
    {
        $this->root = Temp::dir('empira-env-');
        $this->keys = [];
        $this->definedRoot = false;
    }

    protected function tearDown(): void
    {
        Temp::remove($this->root);

        foreach (array_keys($_ENV) as $key) {
            if (str_starts_with((string) $key, 'EMPIRA_TEST_')) {
                unset($_ENV[$key]);
            }
        }
    }

    private function envFile(string $contents): string
    {
        file_put_contents($this->root . '/.env', $contents);

        return $this->root;
    }

    // ------------------------------------------------------------- the file

    public function testTheFileIsRead(): void
    {
        $this->envFile("EMPIRA_TEST_ONE=from-file\n");

        Application::loadEnvironment([], $this->root);

        self::assertSame('from-file', $_ENV['EMPIRA_TEST_ONE'] ?? null);
    }

    public function testAMissingFileIsNotAnError(): void
    {
        // A fresh clone has no `.env` until someone copies one, and the first
        // thing anybody runs is usually the thing that tells them to.
        Application::loadEnvironment([], $this->root);

        self::assertArrayNotHasKey('EMPIRA_TEST_ONE', $_ENV);
    }

    // --------------------------------------------------------- the ordering

    /**
     * The guarantee, and the reason for the whole method.
     *
     * A value exported by the shell is stripped first, so `.env` wins. The
     * reverse — merging the file and letting inherited values overwrite — is the
     * dotenv convention, and it means `export APP_ENV=production` in one terminal
     * silently changes what a development machine serves.
     */
    public function testTheFileWinsOverAnInheritedValue(): void
    {
        $this->envFile("EMPIRA_TEST_MODE=from-file\n");
        $_ENV['EMPIRA_TEST_MODE'] = 'inherited';

        Application::loadEnvironment(['EMPIRA_TEST_MODE'], $this->root);

        self::assertSame('from-file', $_ENV['EMPIRA_TEST_MODE'] ?? null);
    }

    /**
     * And a key the file does not mention still falls through to this class's
     * own default rather than to something inherited from outside.
     *
     * That is the part a plain merge gets wrong: `array_merge` would leave the
     * exported value in place, so a stale export outlives the line that set it.
     */
    public function testAKeyTheFileDoesNotMentionFallsThroughToTheDefault(): void
    {
        $this->envFile("EMPIRA_TEST_OTHER=present\n");
        $_ENV['EMPIRA_TEST_ABSENT'] = 'stale export';

        Application::loadEnvironment(['EMPIRA_TEST_ABSENT'], $this->root);

        self::assertArrayNotHasKey('EMPIRA_TEST_ABSENT', $_ENV, 'a stale export outlived the line that set it');
        self::assertSame('present', $_ENV['EMPIRA_TEST_OTHER'] ?? null);
    }

    /**
     * Only the keys the application claims are touched.
     *
     * The framework is handed a list rather than reading the file's keys,
     * because it cannot know that `LOG_LEVEL` is configuration and
     * `NOT_A_REAL_KEY` is not. An application that passes nothing has nothing
     * stripped, and everything the file says is simply added.
     */
    public function testOnlyTheKeysTheApplicationClaimsAreStripped(): void
    {
        $this->envFile("EMPIRA_TEST_UNCLAIMED=from-file\n");
        $_ENV['EMPIRA_TEST_UNCLAIMED'] = 'inherited';

        Application::loadEnvironment(['EMPIRA_TEST_SOMETHING_ELSE'], $this->root);

        self::assertSame('from-file', $_ENV['EMPIRA_TEST_UNCLAIMED'] ?? null);
    }

    // ------------------------------------------------------------- the root

    /**
     * With neither a path nor the constant, it refuses.
     *
     * `getcwd()` is the tempting fallback and the wrong one: it is whatever the
     * process manager, the scheduler or the operator started the process in.
     * Reading a `.env` from there is a configuration file the application did
     * not write, and the failure is a secret that is not the secret.
     */
    public function testItRefusesRatherThanGuessingTheWorkingDirectory(): void
    {
        $this->envFile("EMPIRA_TEST_ONE=from-file\n");

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Define __ROOT_DIR__');

        Application::loadEnvironment([]);
    }

    /**
     * The entry point's answer is used when it gives one.
     *
     * The constant cannot be defined and undefined inside one process, so this
     * runs in a separate one where it is set — which is also the only honest way
     * to test it, since the alternative is a test that passes only because of
     * the order tests happen to run in.
     */
    public function testTheRootsConstantIsUsedWhenDefined(): void
    {
        $root = $this->envFile("EMPIRA_TEST_ONE=from-constant\n");

        // Two arguments on purpose: the framework to load from, and the root the
        // constant names. They are different places — writing a `.env` into this
        // package to satisfy a test would be the bug, not the fix.
        $script = <<<'PHP'
            <?php
            require $argv[1] . '/vendor/autoload.php';
            define('__ROOT_DIR__', $argv[2]);
            Empira\Application::loadEnvironment(['EMPIRA_TEST_ONE']);
            echo $_ENV['EMPIRA_TEST_ONE'] ?? '(unset)';
            PHP;

        $file = Temp::file('empira-env-probe-');
        file_put_contents($file, $script);

        $output = (string) shell_exec(sprintf(
            '%s %s %s %s 2>&1',
            escapeshellarg(PHP_BINARY),
            escapeshellarg($file),
            escapeshellarg(dirname(__DIR__)),
            escapeshellarg($root),
        ));

        Temp::remove($file);

        self::assertSame('from-constant', trim($output));
    }
}