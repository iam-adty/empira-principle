<?php

declare(strict_types=1);

namespace Empira\Tests;

use Empira\Container\Container;
use Empira\Container\ContainerException;
use PHPUnit\Framework\TestCase;

final class ContainerTest extends TestCase
{
    public function testBindsAnInterfaceToAConcreteClass(): void
    {
        $container = new Container();
        $container->bind(Engine::class, PetrolEngine::class);

        $this->assertInstanceOf(PetrolEngine::class, $container->get(Engine::class));
    }

    public function testFactoryReceivesTheContainer(): void
    {
        $container = new Container();
        $container->bind(Engine::class, fn (Container $c): Engine => new PetrolEngine($c->get(Filter::class)));

        $engine = $container->get(Engine::class);

        $this->assertInstanceOf(PetrolEngine::class, $engine);
        $this->assertInstanceOf(Filter::class, $engine->filter);
    }

    public function testSingletonReturnsTheSameInstanceEveryTime(): void
    {
        $container = new Container();
        $container->singleton(Engine::class, PetrolEngine::class);

        $this->assertSame($container->get(Engine::class), $container->get(Engine::class));
    }

    public function testBindReturnsAFreshInstanceEachTime(): void
    {
        $container = new Container();
        $container->bind(Engine::class, PetrolEngine::class);

        $this->assertNotSame($container->get(Engine::class), $container->get(Engine::class));
    }

    public function testFactoryReturningNullIsOnlyAllowedThroughMake(): void
    {
        $container = new Container();
        $container->bind('settings.path', fn (): string => '/etc/empira');

        $this->assertSame('/etc/empira', $container->make('settings.path'));
    }

    public function testGetRejectsAFactoryThatDoesNotReturnAnObject(): void
    {
        $container = new Container();
        $container->bind('settings.path', fn (): string => '/etc/empira');

        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage('must return an object, got string');

        $container->get('settings.path');
    }

    public function testInstanceReturnsTheGivenObject(): void
    {
        $engine = new PetrolEngine(new Filter());

        $container = new Container();
        $container->instance(Engine::class, $engine);

        $this->assertSame($engine, $container->get(Engine::class));
    }

    public function testAutowiresConstructorDependenciesRecursively(): void
    {
        $container = new Container();

        $car = $container->get(Car::class);

        $this->assertInstanceOf(Car::class, $car);
        $this->assertInstanceOf(PetrolEngine::class, $car->engine);
        $this->assertInstanceOf(Filter::class, $car->filter);
    }

    public function testAutowiringHonoursAnExistingBinding(): void
    {
        $filter = new Filter();

        $container = new Container();
        $container->instance(Filter::class, $filter);

        $this->assertSame($filter, $container->get(Car::class)->filter);
    }

    public function testUsesTheDefaultValueForScalarParameters(): void
    {
        $container = new Container();

        $this->assertSame(4, $container->get(Wheels::class)->count);
    }

    public function testAParameterWithoutATypeOrDefaultThrows(): void
    {
        $container = new Container();

        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage('Untyped');

        $container->get(Untyped::class);
    }

    public function testAnUnresolvableInterfaceThrows(): void
    {
        $container = new Container();

        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage('bind the interface');

        $container->get(Engine::class);
    }

    public function testCircularDependenciesAreReportedRatherThanOverflowing(): void
    {
        $container = new Container();

        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage('Circular dependency');

        $container->get(Ping::class);
    }

    public function testInjectsItselfWhenAContainerIsRequested(): void
    {
        $container = new Container();

        $this->assertSame($container, $container->get(NeedsContainer::class)->container);
    }

    public function testOptionalObjectParameterFallsBackToItsDefault(): void
    {
        $container = new Container();

        $this->assertNull($container->get(OptionalDependency::class)->filter);
    }

    public function testHasKnowsBindingsAndAutowirableClasses(): void
    {
        $container = new Container();
        $container->bind(Engine::class, PetrolEngine::class);

        $this->assertTrue($container->has(Engine::class));
        $this->assertTrue($container->has(Filter::class));
        $this->assertFalse($container->has('missing.thing'));
    }

    public function testBoundTellsBindingsApartFromAutowirableClasses(): void
    {
        $container = new Container();

        $this->assertFalse($container->bound(Filter::class));

        $container->bind(Engine::class, PetrolEngine::class);

        $this->assertTrue($container->bound(Engine::class));
    }

    public function testForgetRemovesABinding(): void
    {
        $container = new Container();
        $container->singleton(Engine::class, PetrolEngine::class);
        $container->forget(Engine::class);

        $this->assertFalse($container->bound(Engine::class));
    }

    public function testCallInjectsTypedParameters(): void
    {
        $container = new Container();

        $filter = new Filter();

        $result = $container->call(
            static fn (Filter $filter): string => $filter->clean('  hi  '),
            ['filter' => $filter]
        );

        $this->assertSame('hi', $result);
    }

    public function testCallResolvesUnboundConcreteDependencies(): void
    {
        $container = new Container();

        $result = $container->call(static fn (Car $car): string => $car->engine::class);

        $this->assertSame(PetrolEngine::class, $result);
    }

    public function testCallAcceptsAnInvokableObject(): void
    {
        $container = new Container();

        $this->assertSame('called', $container->call(new Invokable()));
    }

    public function testCallRejectsAnUnresolvableParameter(): void
    {
        $container = new Container();

        $this->expectException(ContainerException::class);

        $container->call(static fn (Engine $engine): string => 'unreachable');
    }
}

// ------------------------------------------------------------- fixtures

interface Engine
{
}

final class Filter
{
    public function clean(string $value): string
    {
        return trim($value);
    }
}

final class PetrolEngine implements Engine
{
    public function __construct(public readonly Filter $filter)
    {
    }
}

final class Car
{
    public function __construct(
        public readonly PetrolEngine $engine,
        public readonly Filter $filter,
    ) {
    }
}

final class Wheels
{
    public function __construct(public readonly int $count = 4)
    {
    }
}

final class Untyped
{
    public function __construct($anything)
    {
    }
}

final class Ping
{
    public function __construct(public readonly Pong $pong)
    {
    }
}

final class Pong
{
    public function __construct(public readonly Ping $ping)
    {
    }
}

final class NeedsContainer
{
    public function __construct(public readonly Container $container)
    {
    }
}

final class OptionalDependency
{
    public function __construct(public readonly ?Filter $filter = null)
    {
    }
}

final class Invokable
{
    public function __invoke(): string
    {
        return 'called';
    }
}