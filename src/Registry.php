<?php

declare(strict_types=1);

namespace IamAdty\Empira;

/**
 * The set of components a token may resolve to.
 *
 * A token carries a class name, so this is the boundary that stops a crafted
 * request from instantiating an arbitrary class: anything not reachable here —
 * by alias, by exact class, or by sitting under an allowed namespace — is
 * rejected before `new` is ever reached.
 *
 * ## Two ways in
 *
 * An **alias** names one class explicitly: `$app->registry()->alias('login',
 * LoginForm::class)`. It is short, so tokens stay short, and it survives the
 * class moving namespaces — which matters because tokens outlive deployments
 * and sit in users' histories.
 *
 * A **namespace** admits a whole tree at once: `$app->components(__NAMESPACE__)`
 * makes every `Component` under it reachable without naming any of them. That is
 * what stops composition from costing a registration per component — see
 * {@see Component::child()}, which is why a nested component composes and then
 * answers its own action URL instead of rendering until someone presses a
 * button and getting a 404.
 *
 * The namespace rule says *which code*, not *that anything goes*: the candidate
 * still has to be a `Component`. It is a weaker boundary than an alias list,
 * deliberately — an internal component under that namespace is reachable if the
 * secret leaks, where a list would not include it. `vendor/` is not covered by
 * anything but an explicit `allowNamespace()`, so a third-party component
 * package opts in by name rather than arriving pre-authorised.
 */
final class Registry
{
    /** @var array<string, class-string<Component>> */
    private array $classes = [];

    /** @var array<string, string> */
    private array $aliases = [];

    /** @var list<string> */
    private array $namespaces = [];

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

    /**
     * Make every `Component` under `$prefix` reachable, without naming them.
     *
     * The trailing separator is normalised on, so `App` and `App\` are the same
     * rule and neither admits `Application\Other`.
     *
     * @param class-string|string $prefix
     */
    public function allowNamespace(string $prefix): self
    {
        $this->namespaces[] = rtrim($prefix, '\\') . '\\';

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
        $class = $this->aliases[$name]
            ?? $this->classes[$name]
            ?? $this->underAllowedNamespace($name);

        if ($class === null) {
            throw new UnknownComponentException(sprintf(
                'Component "%s" is not reachable: it is not registered and not under an allowed namespace.',
                $name
            ));
        }

        return $class;
    }

    /**
     * A class is admitted by a namespace only if it is *really* a component.
     *
     * The namespace says which code is in scope; it says nothing about whether
     * the class is one that can be built and have its actions invoked, and a
     * directory that happens to sit under the prefix will contain plenty of
     * things that are not.
     *
     * @return class-string<Component>|null
     */
    private function underAllowedNamespace(string $name): ?string
    {
        if (!class_exists($name)) {
            return null;
        }

        foreach ($this->namespaces as $prefix) {
            if (str_starts_with($name, $prefix) && is_subclass_of($name, Component::class)) {
                /** @var class-string<Component> $name */
                return $name;
            }
        }

        return null;
    }

    /**
     * The canonical name a token should carry for this component.
     *
     * An alias when one exists, and the fully-qualified class name when it does
     * not — which is exactly what a namespace rule can resolve, so a component
     * nobody registered still produces a working action URL. It costs the token
     * some length, which is what `alias()` is for when that matters.
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