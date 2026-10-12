<?php

declare(strict_types=1);

namespace Empira\Security;

use Empira\Http\Request;

/**
 * Who is making this request, asked once.
 *
 * This is the read-only half of authentication, and it is all the framework
 * core needs. A layout asks whether there is a user and prints an id; CSRF asks
 * whether the credential was ambient. Neither signs anybody in, and neither
 * writes anything — so neither needs to know where identities are stored, which
 * is what lets authentication live in a module instead of in here.
 *
 *     $app->auth(new SessionGuard($session, $users), new BearerGuard($check));
 *
 *     $user->user();       // the Identity, or null
 *     $user->check();      // bool
 *     $user->guest();      // bool
 *     $user->id();         // the id, or null
 *     $user->guard();      // the Guard that answered, or null
 *
 * An instance is built per request by {@see \Empira\Application} and never
 * outlives it. That is the whole reason it is not a singleton: a fresh instance
 * cannot remember the request before it, which under a long-running worker is
 * the difference between request two being served as request one and not.
 *
 * Guards are consulted in order and the first answer wins.
 */
class RequestUser
{
    private ?Identity $user = null;

    private bool $asked = false;

    /** Which guard produced the identity — which is not the same as *who*. */
    private ?Guard $guard = null;

    /**
     * @param list<Guard> $guards consulted in order
     */
    public function __construct(
        private readonly array $guards = [],
        private readonly ?Request $request = null,
    ) {
    }

    /**
     * The identity making this request, or null when there is none.
     *
     * Resolved once and remembered, so a page that asks in a layout and again
     * in a controller runs the guards once rather than twice — some of them
     * hash a password or read a session file.
     */
    public function user(): ?Identity
    {
        if ($this->asked) {
            return $this->user;
        }

        $this->asked = true;

        if ($this->request === null) {
            return null;
        }

        foreach ($this->guards as $guard) {
            $user = $guard->identify($this->request);

            if ($user !== null) {
                $this->guard = $guard;

                return $this->user = $user;
            }
        }

        return null;
    }

    public function check(): bool
    {
        return $this->user() !== null;
    }

    public function guest(): bool
    {
        return !$this->check();
    }

    /**
     * The guard that identified the request, or null when nobody did.
     *
     * This matters more than it looks. "There is an identity" and "this request
     * proved it with a credential the browser cannot be tricked into sending"
     * are different questions, and only this one is asked when deciding
     * whether CSRF protection applies. A session-authenticated request has a
     * user and is still exactly the request CSRF exists for.
     */
    public function guard(): ?Guard
    {
        $this->user();

        return $this->guard;
    }

    /**
     * Did a credential the browser will not send on its own prove this request?
     *
     * Asks whether the answering guard is marked {@see NonAmbientCredential}
     * rather than whether it is one particular class, so a module can
     * introduce its own mechanism without the core learning its name.
     */
    public function authenticatedByNonAmbientCredential(): bool
    {
        return $this->guard() instanceof NonAmbientCredential;
    }

    public function id(): int|string|null
    {
        return $this->user()?->id();
    }

    /**
     * The guards, in the order they will be consulted.
     *
     * Exposed to subclasses because signing somebody in is a write against a
     * guard — only some guards have anything to write — and a subclass that
     * cannot see them cannot find the one that does.
     *
     * @return list<Guard>
     */
    final protected function guards(): array
    {
        return $this->guards;
    }

    /**
     * Record an identity the guards did not produce.
     *
     * The way signing in works: a credential is *minted* rather than read, so
     * there is no request to resolve against, and the only way the user behind
     * this request is the one just signed in is to say so explicitly. Left as a
     * single method rather than `protected` fields so that "a resolved identity
     * and the guard that produced it cannot disagree" stays a property of this
     * class instead of one every subclass has to remember.
     */
    final protected function resolve(?Identity $user, ?Guard $guard = null): void
    {
        $this->user = $user;
        $this->guard = $guard;
        $this->asked = true;
    }

    /**
     * Forget whoever this request was.
     */
    final protected function forget(): void
    {
        $this->user = null;
        $this->guard = null;
        $this->asked = true;
    }
}