<?php

declare(strict_types=1);

namespace IamAdty\Empira\Auth;

use IamAdty\Empira\Http\Request;

/**
 * The guards, asked once per request.
 *
 *     $app->auth(new SessionGuard($session, $users), new BearerGuard($check));
 *
 *     $auth->user();       // the User, or null
 *     $auth->check();      // bool
 *     $auth->id();         // the id, or null
 *     $auth->guest();      // bool
 *
 * An instance is bound per request by {@see \IamAdty\Empira\Application}, so
 * there is nothing to reset and nothing to leak: a fresh instance cannot
 * remember the request before it. That is the whole reason it is not a
 * singleton.
 *
 * Guards are consulted in order and the first user wins. A request carrying
 * both a session and a bearer token is therefore a session request, which is
 * the safer of the two to have win by accident.
 */
final class Auth
{
    private ?User $user = null;

    private bool $asked = false;

    /** Which guard produced the user — which is not the same as *who*. */
    private ?Guard $guard = null;

    /**
     * @param list<Guard> $guards
     */
    public function __construct(
        private readonly array $guards = [],
        private readonly ?Request $request = null,
    ) {
    }

    /**
     * The user making this request, or null.
     *
     * Resolved once and remembered, so a page that asks in a layout and again
     * in a controller runs the guards once rather than twice — some of them
     * hash a password or read a session file.
     */
    public function user(): ?User
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
     * The guard that identified the user, or null when nobody did.
     *
     * This matters more than it looks. "There is a user" and "this request
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
     * Did a bearer token, rather than an ambient cookie, prove this request?
     */
    public function authenticatedByBearer(): bool
    {
        return $this->guard() instanceof BearerGuard;
    }

    public function id(): int|string|null
    {
        return $this->user()?->id();
    }

    /**
     * Sign a user in through the first guard that can do it.
     *
     * Only a {@see SessionGuard} can log someone in; a bearer guard validates
     * a credential the client already holds and has nothing to write.
     */
    public function login(User $user): void
    {
        foreach ($this->guards as $guard) {
            if ($guard instanceof SessionGuard) {
                $guard->login($user);

                $this->asked = true;
                $this->user = $user;

                return;
            }
        }

        throw new \LogicException('No session guard is configured, so there is nowhere to sign a user in.');
    }

    public function logout(): void
    {
        foreach ($this->guards as $guard) {
            if ($guard instanceof SessionGuard) {
                $guard->logout();
            }
        }

        $this->asked = true;
        $this->user = null;
        $this->guard = null;
    }
}
