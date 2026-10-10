<?php

declare(strict_types=1);

namespace IamAdty\Empira\State;

/**
 * Where a component keeps its props between requests.
 *
 * By default components keep *no* server state: props ride along inside the
 * signed token, so the framework is stateless and horizontally scalable. A
 * component that opts into session-backed state instead returns a larger,
 * constant-size token and lets the store hold the props.
 *
 * @see NullState the default, a no-op store
 * @see SessionState the obvious implementation
 */
interface StateStore
{
    /**
     * @return array<string, mixed>
     */
    public function load(string $key): array;

    /**
     * @param array<string, mixed> $props
     */
    public function save(string $key, array $props): void;

    public function forget(string $key): void;
}