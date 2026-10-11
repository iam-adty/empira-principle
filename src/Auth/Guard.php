<?php

declare(strict_types=1);

namespace IamAdty\Empira\Auth;

use IamAdty\Empira\Http\Request;

/**
 * Turns a request into a user, or into nothing.
 *
 * This is the whole extension point. The framework ships guards for the two
 * mechanisms it understands — a session and a bearer token — and an application
 * that wants its own writes one more. Nothing else in the framework needs to
 * change, because nothing else knows how a user is found.
 *
 * A guard must return null for a credential it does not recognise. Returning
 * null is how "this is not for me" is said; returning a user for an
 * unrecognised credential would be a way to authenticate anyone.
 */
interface Guard
{
    /**
     * The user making this request, or null when there is not one.
     */
    public function identify(Request $request): ?User;
}
