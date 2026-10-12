<?php

declare(strict_types=1);

namespace Empira\Security;

use Empira\Http\Request;

/**
 * Turns a request into an identity, or into nothing.
 *
 * This is the whole extension point. The core ships no guards and implements
 * none of the mechanisms; a module supplies whichever ones it understands —
 * a session, a bearer token, a signed header, an mTLS client certificate — and
 * every one of them is a `Guard`.
 *
 * Guards are consulted in order and the first answer wins, which means a
 * request carrying two credentials is decided by the order they were
 * registered rather than by any judgement about which is stronger. Registering
 * order is therefore a security decision, not a formatting one.
 *
 * Returning null is how "this credential is not mine" is said. Returning an
 * identity for a credential that was not recognised would be a way to
 * authenticate anybody.
 */
interface Guard
{
    /**
     * The identity this request presents, or null when it presents none that
     * this guard recognises.
     */
    public function identify(Request $request): ?Identity;
}