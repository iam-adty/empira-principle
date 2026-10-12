<?php

declare(strict_types=1);

namespace Empira\State;

use Empira\Attributes\Prop;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionProperty;
use ReflectionType;
use ReflectionUnionType;
use Traversable;

/**
 * Reflects `#[Prop]` properties and moves state between them and a props array.
 *
 * The array is the wire format the {@see \Empira\TokenCodec} already signs, so
 * this class deliberately keeps that shape flat and unchanged: a typed property
 * is just a props entry with a default value and a type to coerce against.
 *
 * Incoming values are coerced to the declared type where that is unambiguous
 * and fall back to the property default when it is not. Props arrive from a
 * signed token, so they are authentic but not necessarily current — a stale
 * token from before a rename must not be able to produce a broken component.
 *
 * Values are never instantiated: a class-typed property only accepts an object
 * that is already an instance of that class, so a token cannot name a class and
 * have the framework construct it.
 */
final class PropBag
{
    /** @var array<class-string, array<string, ReflectionProperty>> */
    private static array $cache = [];

    /**
     * The `#[Prop]` properties the framework will actually manage.
     *
     * @param class-string|object $target
     *
     * @return array<string, ReflectionProperty>
     */
    public static function properties(object|string $target): array
    {
        $class = is_object($target) ? $target::class : $target;

        if (isset(self::$cache[$class])) {
            return self::$cache[$class];
        }

        $managed = [];

        foreach ((new ReflectionClass($class))->getProperties() as $property) {
            if ($property->getAttributes(Prop::class) === []) {
                continue;
            }

            if ($property->isStatic() || $property->isReadOnly() || $property->isPrivate()) {
                continue;
            }

            $managed[$property->getName()] = $property;
        }

        return self::$cache[$class] = $managed;
    }

    /**
     * Copy props onto the component's typed properties.
     *
     * Props that are absent keep whatever the property already holds, which is
     * its declared default.
     *
     * @param array<string, mixed> $props
     */
    public static function hydrate(object $target, array $props): void
    {
        foreach (self::properties($target) as $name => $property) {
            if (!array_key_exists($name, $props)) {
                continue;
            }

            $property->setValue($target, self::coerce(
                $props[$name],
                $property->getType(),
                self::defaultOf($property)
            ));
        }
    }

    /**
     * Copy the component's typed properties back into a props array.
     *
     * Uninitialised properties (declared with a type but no default, never
     * assigned) are left out rather than read.
     *
     * @param array<string, mixed> $props
     *
     * @return array<string, mixed>
     */
    public static function flush(object $target, array $props): array
    {
        foreach (self::properties($target) as $name => $property) {
            if (!$property->isInitialized($target)) {
                continue;
            }

            $props[$name] = $property->getValue($target);
        }

        return $props;
    }

    /**
     * @param ReflectionProperty<object> $property
     */
    private static function defaultOf(ReflectionProperty $property): mixed
    {
        return $property->hasDefaultValue() ? $property->getDefaultValue() : null;
    }

    /**
     * Fit an incoming value to a declared type, or fall back to the default.
     */
    private static function coerce(mixed $value, ?ReflectionType $type, mixed $default): mixed
    {
        if ($type === null) {
            return $value;
        }

        if ($value === null) {
            return $type->allowsNull() ? null : $default;
        }

        if ($type instanceof ReflectionUnionType) {
            foreach ($type->getTypes() as $candidate) {
                if (!$candidate instanceof ReflectionNamedType) {
                    continue;
                }

                $coerced = self::coerceNamed($value, $candidate, $default);

                if ($coerced !== $default || $value === $default) {
                    return $coerced;
                }
            }

            return $default;
        }

        return $type instanceof ReflectionNamedType
            ? self::coerceNamed($value, $type, $default)
            : $default;
    }

    private static function coerceNamed(mixed $value, ReflectionNamedType $type, mixed $default): mixed
    {
        if (!$type->isBuiltin()) {
            // An object-typed prop only accepts an object that already is one.
            return $value instanceof ($type->getName()) ? $value : $default;
        }

        return match ($type->getName()) {
            'mixed' => $value,
            'int' => is_numeric($value) ? (int) $value : $default,
            'float' => is_numeric($value) ? (float) $value : $default,
            'string' => is_scalar($value) ? (string) $value : $default,
            'bool' => is_bool($value)
                ? $value
                : (is_scalar($value)
                    ? (filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? $default)
                    : $default),
            'array' => is_array($value) ? $value : $default,
            'iterable' => is_array($value) || $value instanceof Traversable ? $value : $default,
            default => $default,
        };
    }
}