<?php

declare(strict_types=1);

namespace Empira\Tests;

use Empira\Application;
use Empira\Application\Modules;
use Empira\Application\Overrides;
use Empira\Application\ProvidesLogger;
use Empira\Application\ProvidesSecret;
use Empira\Application\ProvidesStorage;
use Empira\ConfigurableModule;
use Empira\Module;
use Empira\ModuleOption;
use Empira\State\NullState;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * How an application describes itself, and the machinery that reads it.
 *
 * This is the code that used to be written twice — once in the skeleton's
 * `App.php`, once in the scratch application's, identically — and that drifted
 * twice before it moved here. A test written against the application's copy
 * would have passed against both and proved nothing about either.
 *
 * The fixtures are subclasses, because every method under test is `protected`.
 * A public test double would be testing a different class with the same body,
 * which is the failure mode {@see \Empira\Tests\ClassScannerTest} also guards.
 */
final class ApplicationSelfDescriptionTest extends TestCase
{

    // ------------------------------------------------------------- attribute()

    public function testAnAttributeIsReadOffTheSubclassRatherThanTheBase(): void
    {
        // The base has none, the subclass declares one, and reading through
        // `self::class` would find nothing — which is the bug a subclass of a
        // class that itself has attributes would hit.
        $app = new Described();

        self::assertInstanceOf(Modules::class, Described::readAttribute(Modules::class));
        self::assertInstanceOf(Overrides::class, Described::readAttribute(Overrides::class));
        self::assertSame([], $app->modules());
    }

    public function testAMissingRequiredAttributeStopsTheBootNamingIt(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('must declare #[Empira\Application\Modules]');

        Undescribed::readAttribute(Modules::class);
    }

    public function testAnOptionalAttributeMayBeAbsent(): void
    {
        self::assertNull(Undescribed::readAttribute(Modules::class, required: false));
    }

    // --------------------------------------------------------------- override()

    public function testAnOverrideThatSatisfiesItsContractIsBuilt(): void
    {
        $built = Described::resolveOverride(GoodSecret::class, ProvidesSecret::class);

        self::assertInstanceOf(GoodSecret::class, $built);
        self::assertSame('a-secret-long-enough-for-testing', $built->secret());
    }

    public function testAnOverrideThatMissesItsContractIsRefusedByName(): void
    {
        // The cost of naming a class in an attribute is that it is a string
        // until something checks it. This is the check, and it happens before
        // the application has built anything — so a typo stops the boot with a
        // message rather than a request with no logger.
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('#[Overrides] named ' . GoodSecret::class);

        Described::resolveOverride(GoodSecret::class, ProvidesStorage::class);
    }

    public function testAnOverrideNamingNoClassAtAllSaysSoRatherThanOnlyNamingIt(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('no such class');

        Described::resolveOverride('App\Nope\Gone', ProvidesSecret::class);
    }

    // --------------------------------------------------------------- basePath()

    public function testBasePathIsTheApplicationsOwnDirectoryNotTheFrameworks(): void
    {
        // The reason this method exists. A framework default computing a path
        // from `__DIR__` lands inside `vendor/`, which is green in a test run
        // and gone with the next install.
        $expected = dirname((new ReflectionClass(Described::class))->getFileName(), 2);

        self::assertSame($expected, Described::path());
        self::assertStringNotContainsString('vendor', Described::path());
    }

    public function testBasePathTakesARelativePathAndLeavesAnAbsoluteOneAlone(): void
    {
        self::assertStringEndsWith('/storage/app.sqlite', Described::path('storage/app.sqlite'));

        // An application supplying an absolute path gets exactly what it asked
        // for rather than this method's opinion about it.
        self::assertSame('/var/data/app.sqlite', (new Described())->absolutePath('/var/data/app.sqlite'));
    }

    // ---------------------------------------------------------- installModules()

    /**
     * An option declared as a {@see ModuleOption} reaches the module.
     *
     * The join, and the only test here that could not have been written before
     * the interface existed. It is the same shape as the DebugBar toolbar that
     * shipped pointing at a URL matching no route: a producer, a consumer, and
     * nothing asserting that the first's output is what the second reads.
     */
    public function testADeclaredOptionReachesTheModule(): void
    {
        $app = new WithOptions();

        $app->install([RecordingModule::class]);

        self::assertSame(
            ['greeting' => 'hello', 'colour' => 'blue'],
            $app->configuredWith(),
            'the module did not receive the options the application declared',
        );
    }

    public function testAModuleThatTakesNoOptionsIsInstalledWithoutComplaint(): void
    {
        $app = new WithOptions();

        $app->install([PlainModule::class]);

        self::assertCount(1, $app->modules());
    }

    public function testAnApplicationDeclaringNoOptionsInstallsModulesAllTheSame(): void
    {
        $app = new Described();

        $app->install([RecordingModule::class]);

        self::assertSame([], $app->configuredWith());
        self::assertCount(1, $app->modules());
    }

    /**
     * Two *different* options contributing the same name: the last wins.
     *
     * Not the same attribute twice — PHP refuses to repeat one that is not
     * `IS_REPEATABLE`, so a collision can only come from two attribute classes
     * agreeing on a key. Documented on {@see ModuleOption} rather than refused,
     * because an application declaring two options for one module has made a
     * choice and honouring it is less surprising than an exception about it.
     */
    public function testTwoOptionsContributingOneNameResolveToTheLast(): void
    {
        $app = new WithCompetingOptions();

        $app->install([RecordingModule::class]);

        self::assertSame(['shared' => 'second'], $app->configuredWith());
    }

    /**
     * A module resolved by name rather than constructed, so it can use the
     * session and entity manager the framework has already bound.
     */
    public function testAModuleIsResolvedThroughTheContainerNotConstructed(): void
    {
        $app = new WithOptions();

        $app->install([NeedsTheContainer::class]);

        self::assertNotNull($app->container()->get(NeedsTheContainer::class));
    }
}

// --------------------------------------------------------------------- fixtures

/** Opens the protected surface so a subclass can be tested as one. */
abstract class Exposed extends Application
{
    private const SECRET = 'a-secret-long-enough-for-testing';

    public function __construct()
    {
        parent::__construct(self::SECRET, new NullState());
    }

    public static function readAttribute(string $name, bool $required = true): ?object
    {
        return static::attribute($name, $required);
    }

    public static function resolveOverride(string $class, string $contract): object
    {
        return static::override($class, $contract);
    }

    public static function path(string $relative = ''): string
    {
        return static::basePath($relative);
    }

    public static function absolutePath(string $absolute): string
    {
        return str_starts_with($absolute, '/') ? $absolute : static::basePath($absolute);
    }

    public function install(array $classes): void
    {
        $this->installModules($classes);
    }

    public function configuredWith(): array
    {
        foreach ($this->modules() as $module) {
            if ($module instanceof RecordingModule) {
                return $module->received;
            }
        }

        return [];
    }
}

/** Declares the required attributes and nothing else. */
#[Modules(Module::class)]
#[Overrides(secret: GoodSecret::class, logger: GoodLogger::class, storage: GoodStorage::class)]
final class Described extends Exposed
{
}

/** Declares neither — the shape {@see testAnOptionalAttributeMayBeAbsent()} covers. */
final class Undescribed extends Exposed
{
}

/** Declares a module option, which is the join under test. */
#[Modules(RecordingModule::class)]
#[Overrides(secret: GoodSecret::class, logger: GoodLogger::class, storage: GoodStorage::class)]
#[Greeting('hello')]
#[Colour('blue')]
final class WithOptions extends Exposed
{
}

/** Two attributes contributing the same key. */
#[Modules(RecordingModule::class)]
#[Overrides(secret: GoodSecret::class, logger: GoodLogger::class, storage: GoodStorage::class)]
#[Alpha('first')]
#[Beta('second')]
final class WithCompetingOptions extends Exposed
{
}

#[\Attribute(\Attribute::TARGET_CLASS)]
final class Greeting implements ModuleOption
{
    public function __construct(private readonly string $text)
    {
    }

    public function options(): array
    {
        return ['greeting' => $this->text];
    }
}

#[\Attribute(\Attribute::TARGET_CLASS)]
final class Alpha implements ModuleOption
{
    public function __construct(private readonly string $text)
    {
    }

    public function options(): array
    {
        return ['shared' => $this->text];
    }
}

#[\Attribute(\Attribute::TARGET_CLASS)]
final class Beta implements ModuleOption
{
    public function __construct(private readonly string $text)
    {
    }

    public function options(): array
    {
        return ['shared' => $this->text];
    }
}

#[\Attribute(\Attribute::TARGET_CLASS)]
final class Colour implements ModuleOption
{
    public function __construct(private readonly string $name)
    {
    }

    public function options(): array
    {
        return ['colour' => $this->name];
    }
}

/** Records what it was configured with, so the assertion can read it back. */
final class RecordingModule implements ConfigurableModule
{
    public array $received = [];

    public function register(Application $app): void
    {
        $app->routes()->get('/recorded', fn (): string => 'recorded');
    }

    public function configure(array $options): void
    {
        $this->received = $options;
    }
}

/** No `configure()`, so `installModules()` must not assume one. */
final class PlainModule implements Module
{
    public function register(Application $app): void
    {
        $app->routes()->get('/plain', fn (): string => 'plain');
    }
}

/** Autowirable from the container, which is the point of naming modules. */
final class NeedsTheContainer implements Module
{
    public function __construct(private readonly Application $app)
    {
    }

    public function register(Application $app): void
    {
        $app->routes()->get('/needs', fn (): string => 'needs');
    }
}

final class GoodSecret implements ProvidesSecret
{
    public function secret(): string
    {
        return 'a-secret-long-enough-for-testing';
    }
}

final class GoodLogger implements ProvidesLogger
{
    public function logger(): \Psr\Log\LoggerInterface
    {
        return new \Psr\Log\NullLogger();
    }
}

final class GoodStorage implements ProvidesStorage
{
    public function path(): string
    {
        return 'storage/app.sqlite';
    }
}