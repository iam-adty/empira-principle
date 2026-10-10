<?php

declare(strict_types=1);

namespace IamAdty\Empira;

/**
 * The allow-list of components a token may resolve to.
 *
 * A token carries a class name, so the registry is the boundary that stops a
 * crafted request from instantiating an arbitrary class: anything not
 * registered here — alias or FQCN — is rejected before `new` is ever reached.
 *
 * Aliases keep tokens short and rename-safe: a component can move namespaces
 * without invalidating links already sitting in users' histories.
 */
final class Registry
{
    /** @var array<string, class-string<Component>> */
    private array $classes = [];

    /** @var array<string, string> */
    private array $aliases = [];

    /**
     * @param class-string<Component> ...$classes
     */
    public function add(string ...$classes): self
    {
        foreach ($classes as $class) {
            $this->guard($class);

            $this->classes[$class] = $class;
        }

        return $this;
    }

    /**
     * Register `$class` under a short stable name.
     *
     * @param class-string<Component> $class
     */
    public function alias(string $name, string $class): self
    {
        $this->guard($class);

        if (preg_match('/^[A-Za-z][A-Za-z0-9_.:-]*$/', $name) !== 1) {
            throw new \InvalidArgumentException(sprintf('Illegal component alias "%s".', $name));
        }

        $this->aliases[$name] = $class;
        $this->classes[$class] = $class;

        return $this;
    }

    public function has(string $name): bool
    {
        return isset($this->aliases[$name]) || isset($this->classes[$name]);
    }

    /**
     * @return class-string<Component>
     *
     * @throws UnknownComponentException
     */
    public function resolve(string $name): string
    {
        $class = $this->aliases[$name] ?? $this->classes[$name] ?? null;

        if ($class === null) {
            throw new UnknownComponentException(sprintf('Component "%s" is not registered.', $name));
        }

        return $class;
    }

    /**
     * The canonical name a token should carry for this component.
     */
    public function nameFor(string $class): string
    {
        $this->guard($class);

        $alias = array_search($class, $this->aliases, true);

        return $alias !== false ? $alias : $class;
    }

    /**
     * @return array<string, class-string<Component>>
     */
    public function all(): array
    {
        return $this->aliases + $this->classes;
    }

    /**
     * @param class-string<Component> $class
     */
    private function guard(string $class): void
    {
        if (!class_exists($class) || !is_subclass_of($class, Component::class)) {
            throw new \InvalidArgumentException(sprintf('"%s" is not an Empira component.', $class));
        }
    }
}