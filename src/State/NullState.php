<?php

declare(strict_types=1);

namespace IamAdty\Empira\State;

/**
 * The stateless default: props always travel inside the signed token, so
 * nothing is ever written to the server.
 */
final class NullState implements StateStore
{
    public function load(string $key): array
    {
        return [];
    }

    public function save(string $key, array $props): void
    {
    }

    public function forget(string $key): void
    {
    }
}