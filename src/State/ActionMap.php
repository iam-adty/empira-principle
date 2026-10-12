<?php

declare(strict_types=1);

namespace Empira\State;

use Empira\Attributes\Action;
use Empira\Attributes\Verb;
use Empira\Component;
use ReflectionClass;
use ReflectionMethod;

/**
 * Builds a component's action map from its `#[Action]` attributes.
 *
 * A component may declare actions two ways, and both remain supported:
 *
 *   - by listing method names in {@see Component::actions()};
 *   - by marking methods with `#[Action]` (or a verb attribute, which implies it).
 *
 * When a class uses attributes, they are the source of truth for both *which*
 * methods are reachable and *what verb* reaches them. A component that mixes
 * the two keeps the union, so migrating one method at a time is safe.
 *
 * The map is action name => HTTP verb. Only public, non-static, non-abstract
 * methods qualify; the router refuses anything else before it is called.
 */
final class ActionMap
{
    /** @var array<class-string, array<string, Verb>> */
    private static array $cache = [];

    /**
     * The actions a component exposes, as name => verb.
     *
     * @param class-string<Component> $class
     *
     * @return array<string, Verb>
     */
    public static function of(string $class): array
    {
        if (isset(self::$cache[$class])) {
            return self::$cache[$class];
        }

        $attributes = self::fromAttributes($class);

        // The default object lets us call actions() without booting a component;
        // the method is pure in every sane implementation, and the router's
        // own guard still runs against the real instance.
        $declared = self::fromActionsMethod($class);

        $merged = $declared;

        foreach ($attributes as $name => $verb) {
            $merged[$name] = $verb;
        }

        return self::$cache[$class] = $merged;
    }

    /**
     * @param class-string<Component> $class
     *
     * @return array<string, Verb>
     */
    private static function fromAttributes(string $class): array
    {
        $actions = [];

        foreach ((new ReflectionClass($class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->isStatic() || $method->isAbstract()) {
                continue;
            }

            $verb = Verb::of($method);

            if ($verb === null) {
                continue;
            }

            $explicit = $method->getAttributes(Action::class)[0] ?? null;
            $override = $explicit?->newInstance()->verb;

            $actions[$method->getName()] = $override ?? $verb;
        }

        return $actions;
    }

    /**
     * Actions listed by the legacy `actions()` method, defaulting to POST.
     *
     * `actions()` is called on an instance built without its constructor, so it
     * must describe actions without depending on instance state (props,
     * request, ...). The router re-checks the real instance's allow-list before
     * dispatch, so this map only ever decides verbs and membership.
     *
     * @param class-string<Component> $class
     *
     * @return array<string, Verb>
     */
    private static function fromActionsMethod(string $class): array
    {
        $component = (new \ReflectionClass($class))->newInstanceWithoutConstructor();

        if (!$component instanceof Component || !method_exists($component, 'actions')) {
            return [];
        }

        $actions = [];

        foreach ($component->actions() as $name) {
            if (is_string($name)) {
                $actions[$name] = Verb::Post;
            }
        }

        return $actions;
    }
}