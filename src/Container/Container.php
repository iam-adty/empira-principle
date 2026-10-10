<?php

declare(strict_types=1);

namespace IamAdty\Empira\Container;

use ReflectionFunction;
use ReflectionIntersectionType;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionUnionType;

/**
 * A small service container that can wire a graph of classes for you.
 *
 * Bindings are explicit where they should be — an interface to a concrete class
 * is a decision the developer makes, not one the container guesses. Everything
 * else is autowired: a constructor parameter typed as a concrete class is
 * resolved recursively, and a parameter with no resolvable type falls back to
 * its default. Anything it cannot work out raises a {@see ContainerException}
 * naming the class and parameter, rather than silently passing null.
 *
 *     $container = new Container();
 *     $container->singleton(TokenCodec::class, new TokenCodec($secret));
 *
 *     $codec = $container->get(TokenCodec::class);
 *
 * The container is deliberately synchronous and unaware of HTTP: it is a
 * wiring tool, not a service locator the application reaches into everywhere.
 */
final class Container
{
    /** @var array<string, array{concrete: callable|string|null, shared: bool}> */
    private array $bindings = [];

    /** @var array<string, object> */
    private array $instances = [];

    /** @var array<string, true> classes currently being built, used to break cycles */
    private array $resolving = [];

    /**
     * Bind an abstract name to a concrete class or factory.
     *
     * @param class-string|callable(Container):object|null $concrete null means `$abstract` itself
     */
    public function bind(string $abstract, callable|string|null $concrete = null): self
    {
        $this->bindings[$abstract] = ['concrete' => $concrete, 'shared' => false];
        unset($this->instances[$abstract]);

        return $this;
    }

    /**
     * Bind once, then reuse the same object for every later resolution.
     *
     * @param class-string|callable(Container):object|null $concrete
     */
    public function singleton(string $abstract, callable|string|null $concrete = null): self
    {
        $this->bindings[$abstract] = ['concrete' => $concrete, 'shared' => true];
        unset($this->instances[$abstract]);

        return $this;
    }

    /**
     * Register an already-built object.
     */
    public function instance(string $abstract, object $instance): self
    {
        $this->instances[$abstract] = $instance;

        return $this;
    }

    /**
     * Does this name have a binding, or can it be autowired?
     */
    public function has(string $abstract): bool
    {
        return isset($this->bindings[$abstract])
            || isset($this->instances[$abstract])
            || (class_exists($abstract) && (new \ReflectionClass($abstract))->isInstantiable());
    }

    /**
     * Is there an explicit binding or instance for this name?
     */
    public function bound(string $abstract): bool
    {
        return isset($this->bindings[$abstract]) || isset($this->instances[$abstract]);
    }

    public function forget(string $abstract): self
    {
        unset($this->bindings[$abstract], $this->instances[$abstract]);

        return $this;
    }

    /**
     * Resolve a name to an object.
     *
     * @throws ContainerException
     */
    public function get(string $abstract): object
    {
        $resolved = $this->make($abstract);

        if (!is_object($resolved)) {
            throw new ContainerException(sprintf(
                'The factory for "%s" must return an object, got %s.',
                $abstract,
                get_debug_type($resolved)
            ));
        }

        return $resolved;
    }

    /**
     * Resolve a name, allowing a factory to return any value.
     *
     * @throws ContainerException
     */
    public function make(string $abstract): mixed
    {
        if (isset($this->instances[$abstract])) {
            return $this->instances[$abstract];
        }

        if (isset($this->bindings[$abstract])) {
            $binding = $this->bindings[$abstract];
            $concrete = $binding['concrete'] ?? $abstract;

            $resolved = is_string($concrete)
                ? $this->build($concrete, $abstract)
                : $this->invokeFactory($concrete, $abstract);

            if ($binding['shared']) {
                $this->instances[$abstract] = $resolved;
            }

            return $resolved;
        }

        return $this->build($abstract, $abstract);
    }

    /**
     * Call a callable, injecting any parameters it types as objects.
     *
     * This is what route handlers and middleware go through: a handler can ask
     * for `Request` by type and let the container supply it.
     *
     * @param array<string, mixed> $arguments extra arguments by parameter name
     *
     * @throws ContainerException
     */
    public function call(callable $callable, array $arguments = []): mixed
    {
        $reflector = $this->reflectCallable($callable);
        $resolved = $this->resolveArguments($reflector, $arguments, $callable);

        // A method must be told which object to run on; a closure must not.
        return $reflector instanceof ReflectionMethod
            ? $reflector->invokeArgs($this->callableTarget($callable), $resolved)
            : $reflector->invokeArgs($resolved);
    }

    /**
     * The object a method callable belongs to, or null when it is static.
     */
    private function callableTarget(callable $callable): ?object
    {
        if (is_array($callable)) {
            return is_object($callable[0]) ? $callable[0] : null;
        }

        return is_object($callable) && !$callable instanceof \Closure ? $callable : null;
    }

    private function reflectCallable(callable $callable): \ReflectionFunctionAbstract
    {
        if (is_array($callable)) {
            return new ReflectionMethod($callable[0], $callable[1]);
        }

        if (is_object($callable) && !$callable instanceof \Closure) {
            return new ReflectionMethod($callable, '__invoke');
        }

        if (is_string($callable) && !str_contains($callable, '::')) {
            return new ReflectionMethod($callable, '__invoke');
        }

        return new ReflectionFunction($callable);
    }

    /**
     * Build a concrete class, autowiring its constructor.
     *
     * @param class-string $class
     *
     * @throws ContainerException
     */
    private function build(string $class, string $requestedBy): object
    {
        if (!class_exists($class)) {
            throw new ContainerException(interface_exists($class)
                ? sprintf('Cannot resolve "%s" requested by "%s": bind the interface to a concrete class.', $class, $requestedBy)
                : sprintf('Cannot resolve "%s" requested by "%s": no such class.', $class, $requestedBy));
        }

        $reflection = new \ReflectionClass($class);

        if (!$reflection->isInstantiable()) {
            throw new ContainerException(sprintf(
                'Cannot resolve "%s" requested by "%s": the class is abstract or an interface.',
                $class,
                $requestedBy
            ));
        }

        if (isset($this->resolving[$class])) {
            throw new ContainerException(sprintf(
                'Circular dependency while building "%s" (chain: %s).',
                $class,
                implode(' → ', array_keys($this->resolving))
            ));
        }

        $constructor = $reflection->getConstructor();

        if ($constructor === null) {
            return new $class();
        }

        $this->resolving[$class] = true;

        try {
            $arguments = $this->resolveArguments($constructor, [], $class);
        } finally {
            unset($this->resolving[$class]);
        }

        return $reflection->newInstanceArgs($arguments);
    }

    /**
     * Work out a value for each parameter the callable declares.
     *
     * @param array<string, mixed>  $arguments caller-supplied overrides by name
     * @param callable|class-string $subject   used only for error messages
     *
     * @return list<mixed>
     *
     * @throws ContainerException
     */
    private function resolveArguments(\ReflectionFunctionAbstract $reflector, array $arguments, $subject): array
    {
        $resolved = [];

        foreach ($reflector->getParameters() as $parameter) {
            $name = $parameter->getName();

            if (array_key_exists($name, $arguments)) {
                $resolved[] = $arguments[$name];

                continue;
            }

            // A variadic parameter can be left empty; stop rather than guess.
            if ($parameter->isVariadic()) {
                break;
            }

            $value = $this->resolveParameter($parameter, $subject);

            if ($value instanceof Unresolvable) {
                throw new ContainerException(sprintf(
                    'Cannot resolve $%s of %s: no binding, no autowirable type, and no default value.',
                    $name,
                    $this->describe($subject)
                ));
            }

            $resolved[] = $value;
        }

        return $resolved;
    }

    /**
     * @throws ContainerException
     */
    private function resolveParameter(ReflectionParameter $parameter, callable|int|string $subject): mixed
    {
        $type = $parameter->getType();

        if ($type instanceof ReflectionNamedType && !$type->isBuiltin()) {
            $name = $type->getName();

            // The container injects itself when a dependency asks for it.
            if ($name === self::class) {
                return $this;
            }

            if ($this->bound($name)) {
                return $this->make($name);
            }

            if (class_exists($name) && !$parameter->isDefaultValueAvailable()) {
                return $this->build($name, $this->describe($subject));
            }
        } elseif ($type instanceof ReflectionUnionType) {
            foreach ($type->getTypes() as $candidate) {
                if ($candidate instanceof ReflectionNamedType && !$candidate->isBuiltin() && class_exists($candidate->getName())) {
                    return $this->make($candidate->getName());
                }
            }
        } elseif ($type instanceof ReflectionIntersectionType) {
            $first = $type->getTypes()[0] ?? null;

            if ($first instanceof ReflectionNamedType && class_exists($first->getName())) {
                return $this->make($first->getName());
            }
        }

        if ($parameter->isDefaultValueAvailable()) {
            return $parameter->getDefaultValue();
        }

        if ($type instanceof ReflectionNamedType && $type->allowsNull()) {
            return null;
        }

        return new Unresolvable();
    }

    /**
     * @param callable(Container):mixed $factory
     */
    private function invokeFactory(callable $factory, string $abstract): mixed
    {
        $reflector = new ReflectionFunction($factory);

        return $reflector->getNumberOfParameters() === 0
            ? $factory()
            : $factory($this);
    }

    private function describe(callable|int|string $subject): string
    {
        return is_object($subject)
            ? $subject::class
            : (is_string($subject) ? $subject : 'a callable');
    }
}