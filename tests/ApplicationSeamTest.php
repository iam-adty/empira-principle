<?php

declare(strict_types=1);

namespace Empira\Tests;

use Empira\Application;
use Empira\Application\EnvSecret;
use Empira\Application\Modules;
use Empira\Application\Overrides;
use Empira\Application\ProvidesLogger;
use Empira\Application\ProvidesSecret;
use Empira\Application\ProvidesStorage;
use Empira\Application\SqliteStorage;
use Empira\Application\StderrLogger;
use Empira\Tests\Fixtures\SeamApp;
use PHPUnit\Framework\TestCase;
use ReflectionAttribute;
use ReflectionClass;

/**
 * The seam an application is built from.
 *
 * Every one of these classes used to live in `app/`, which is to say in the one
 * directory in the workspace that belongs to no package and is therefore tested
 * by nobody. They are here now, and that is what this file is for.
 *
 * The assertion that matters most is the shortest one: {@see SqliteStorage} must
 * not resolve a path against its own location. It shipped in the application
 * first, where `dirname(__DIR__)` happened to be right, and moved here — where
 * `dirname(__DIR__)` is `vendor/`. A test written before the move would have
 * passed against both versions and told nothing.
 */
final class ApplicationSeamTest extends TestCase
{
    /**
     * A framework default must not answer a path built from where it is loaded
     * from.
     *
     * The failure is invisible for exactly as long as nobody reinstalls the
     * package: a skeleton building against a stale vendor writes its database
     * into the vendor directory, and every suite in the workspace passes. It
     * returns with the next `composer install`, on the machine that deployed it.
     */
    public function testTheDefaultStorageAnswersARelativePath(): void
    {
        $path = (new SqliteStorage())->path();

        self::assertSame('storage/app.sqlite', $path);
        self::assertStringNotContainsString(
            'vendor',
            $path,
            'a framework default must not resolve against its own location',
        );
        self::assertFalse(
            str_starts_with($path, '/'),
            'an absolute path here would be an absolute path inside the framework package',
        );
    }

    /**
     * `Modules` says one thing: which modules load.
     *
     * Variadic, because a promoted variadic is illegal and an unpromoted one
     * needs an explicit `array_values` — which also normalises named arguments,
     * so `Modules(modules: …)` cannot leave string keys in the list.
     */
    public function testModulesTakesItsClassesAsAList(): void
    {
        $declared = new Modules(\Empira\Module::class, \Empira\ConfigurableModule::class);

        self::assertSame([\Empira\Module::class, \Empira\ConfigurableModule::class], $declared->modules);
    }

    public function testModulesAcceptsNoneAndThatIsNotAnError(): void
    {
        // An application with no modules is an application, not a mistake — the
        // framework runs without any of them.
        self::assertSame([], (new Modules())->modules);
    }

    /**
 * The attributes are read off a class, which is the whole reason they exist.
 *
     * A fixture rather than an anonymous class because an attribute has to sit on
     * a named declaration to be reflected back off `ReflectionClass`, and PHP
     * will not let an anonymous class be `final`.
     */
    public function testTheAttributesAreReadableFromAClassThatDeclaresThem(): void
    {
        $reflected = new ReflectionClass(SeamApp::class);

        self::assertCount(1, $reflected->getAttributes(Modules::class, ReflectionAttribute::IS_INSTANCEOF));
        self::assertCount(1, $reflected->getAttributes(Overrides::class, ReflectionAttribute::IS_INSTANCEOF));

        $declared = $reflected
            ->getAttributes(Modules::class, ReflectionAttribute::IS_INSTANCEOF)[0]
            ->newInstance();

        self::assertSame([Application::class], $declared->modules);
    }

    /**
     * The three defaults satisfy the contracts the attribute requires, which is
     * the check the application would otherwise do at boot with a fatal.
     */
    public function testTheDefaultsSatisfyTheContractsTheyAreNamedFor(): void
    {
        self::assertInstanceOf(ProvidesSecret::class, new EnvSecret());
        self::assertInstanceOf(ProvidesLogger::class, new StderrLogger());
        self::assertInstanceOf(ProvidesStorage::class, new SqliteStorage());
    }

    /**
     * The secret is refused rather than invented, and the refusal names `.env`
     * without saying where it is.
     *
     * It used to interpolate its own directory, which was true while this class
     * lived in the application and became actively wrong the moment it moved
     * into a package — it would have pointed at `vendor/…/principle/.env`. A
     * refusal naming a file in the wrong place is worse than one naming only the
     * file.
     */
    public function testAMissingSecretIsRefusedAndTheRefusalNamesNoPath(): void
    {
        $previous = $_ENV['APP_SECRET'] ?? null;
        unset($_ENV['APP_SECRET']);

        try {
            (new EnvSecret())->secret();

            self::fail('a missing secret produced one');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('APP_SECRET is not set', $e->getMessage());
            self::assertStringContainsString('.env', $e->getMessage());
            self::assertStringContainsString('random_bytes(32)', $e->getMessage());
            self::assertStringNotContainsString(
                DIRECTORY_SEPARATOR,
                $e->getMessage(),
                'the refusal names .env and must not claim to know where it is',
            );
        } finally {
            if ($previous !== null) {
                $_ENV['APP_SECRET'] = $previous;
            }
        }
    }

    public function testATooShortSecretSaysHowShort(): void
    {
        $previous = $_ENV['APP_SECRET'] ?? null;
        $_ENV['APP_SECRET'] = 'short';

        try {
            (new EnvSecret())->secret();

            self::fail('a five byte secret was accepted');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('too short (5 bytes, 16 needed)', $e->getMessage());
        } finally {
            if ($previous === null) {
                unset($_ENV['APP_SECRET']);
            } else {
                $_ENV['APP_SECRET'] = $previous;
            }
        }
    }
}
