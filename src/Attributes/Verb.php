<?php

declare(strict_types=1);

namespace Empira\Attributes;

use ReflectionMethod;

/**
 * The verbs an action may be declared with.
 *
 * Each one marks a method as an htmx action *and* records the HTTP method htmx
 * should use to reach it, so the generated `hx-*` attribute and the router's
 * method check can never drift apart.
 *
 * `#[Action]` on its own means POST, which is what `->action('save')` has
 * always emitted.
 */
enum Verb: string
{
    case Get = 'GET';
    case Post = 'POST';
    case Put = 'PUT';
    case Patch = 'PATCH';
    case Delete = 'DELETE';

    /**
     * The verb a method declares, if it declares one.
     */
    public static function of(ReflectionMethod $method): ?self
    {
        foreach (self::cases() as $verb) {
            if ($method->getAttributes($verb->attribute()) !== []) {
                return $verb;
            }
        }

        return $method->getAttributes(Action::class) !== [] ? self::Post : null;
    }

    /**
     * The attribute class that declares this verb.
     *
     * @return class-string
     */
    public function attribute(): string
    {
        return match ($this) {
            self::Get => Get::class,
            self::Post => Post::class,
            self::Put => Put::class,
            self::Patch => Patch::class,
            self::Delete => Delete::class,
        };
    }

    /**
     * The htmx attribute that issues this verb.
     */
    public function attributeName(): string
    {
        return 'hx-' . strtolower($this->value);
    }
}