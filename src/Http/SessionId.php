<?php

declare(strict_types=1);

namespace IamAdty\Empira\Http;

/**
 * Something that can hand out a fresh session id.
 *
 * {@see Session} implements it, which is all `SessionGuard` needs. It is an
 * interface only because rotating the id on login is a security property worth
 * being able to assert, and `Session::regenerate()` is a no-op under the CLI —
 * a test that watched the id change would pass whether or not a rotation
 * happened.
 */
interface SessionId
{
    /**
     * Discard the current session id and issue a new one.
     *
     * This is what defeats session fixation: without it, an id an attacker
     * planted before the victim signed in is still valid afterwards.
     */
    public function regenerate(): void;
}
