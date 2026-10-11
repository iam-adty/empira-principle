<?php

declare(strict_types=1);

namespace IamAdty\Empira\Auth;

/**
 * Who is making this request.
 *
 * Deliberately a value and nothing more. The framework does not store users,
 * hash passwords or decide what a user is — it only needs enough of an
 * identity to say "someone is signed in" and to hand the application whatever
 * it stored alongside the id.
 *
 *     new User(id: 7, attributes: ['email' => 'ada@example.com', 'role' => 'admin']);
 */
final readonly class User
{
    /**
     * @param int|string        $id         the application's own identifier
     * @param array<string, mixed> $attributes whatever the application chose to carry
     */
    public function __construct(
        public int|string $id,
        public array $attributes = [],
    ) {
    }

    /**
     * One attribute, or a default when it is not there.
     */
    public function attribute(string $key, mixed $default = null): mixed
    {
        return $this->attributes[$key] ?? $default;
    }

    /**
     * @throws \InvalidArgumentException when the attribute is absent
     */
    public function require(string $key): mixed
    {
        if (!array_key_exists($key, $this->attributes)) {
            throw new \InvalidArgumentException(sprintf('This user has no "%s" attribute.', $key));
        }

        return $this->attributes[$key];
    }
}
