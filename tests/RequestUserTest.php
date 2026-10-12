<?php

declare(strict_types=1);

namespace Empira\Tests;

use Empira\Application;
use Empira\Security\Guard;
use Empira\Security\Identity;
use Empira\Security\NonAmbientCredential;
use Empira\Security\RequestUser;
use Empira\Http\Request;
use PHPUnit\Framework\TestCase;

/**
 * The framework without an authentication module installed.
 *
 * These are the tests that justify the contracts existing at all. Before them,
 * a request's user was an `Auth` object the framework constructed itself, and
 * there was no way to ask "who is this" without that class. `Empira\Auth` is a
 * module now, so core has to answer the question using only what it declares —
 * and the useful proof is that it answers it correctly when nothing
 * authentication-shaped has ever been registered.
 */
final class RequestUserTest extends TestCase
{
    public function testAnApplicationWithNoGuardsResolvesToNobody(): void
    {
        $app = new Application(secret: 'a-secret-long-enough-for-testing');
        $app->routes()->get('/who', fn(RequestUser $user): string => $user->guest() ? 'guest' : 'someone');

        self::assertSame('guest', $app->handle(new Request('GET', '/who'))->body());
    }

    /**
     * A route handler asking the container for the user is how a real page
     * does it, and it is the case that has to work without any authentication
     * module installed at all.
     */
    public function testTheContextIsBoundForTheRequestAndResolvesToNobody(): void
    {
        $app = new Application(secret: 'a-secret-long-enough-for-testing');
        $app->routes()->get('/who', function (RequestUser $user): string {
            self::assertInstanceOf(RequestUser::class, $user);
            self::assertNull($user->user());
            self::assertFalse($user->check());
            self::assertTrue($user->guest());
            self::assertNull($user->id());
            self::assertNull($user->guard());
            self::assertFalse($user->authenticatedByNonAmbientCredential());

            return 'asked';
        });

        self::assertSame('asked', $app->handle(new Request('GET', '/who'))->body());
    }

    public function testAGuardThatAnswersSuppliesTheIdentity(): void
    {
        $identity = new class implements Identity {
            public function id(): int|string|null
            {
                return 7;
            }
        };

        $guard = new class($identity) implements Guard {
            public function __construct(private readonly Identity $identity)
            {
            }

            public function identify(Request $request): ?Identity
            {
                return $this->identity;
            }
        };

        $user = new RequestUser([$guard], new Request('GET', '/'));

        self::assertSame($identity, $user->user());
        self::assertTrue($user->check());
        self::assertSame(7, $user->id());
        self::assertSame($guard, $user->guard());
    }

    /**
     * The property the CSRF check actually rests on, and the reason it is
     * stated as a marker rather than as a list of guard classes.
     *
     * A guard nobody has heard of — one a module invented last week — is
     * exempt from CSRF the moment it says it consumes a non-ambient
     * credential. The framework does not recognise the class, cannot name it,
     * and does not need to.
     */
    public function testAnyGuardCanClaimItsCredentialIsNonAmbient(): void
    {
        $guard = new class implements Guard, NonAmbientCredential {
            public function identify(Request $request): ?Identity
            {
                return new class implements Identity {
                    public function id(): int|string|null
                    {
                        return 1;
                    }
                };
            }
        };

        $user = new RequestUser([$guard], new Request('GET', '/'));

        self::assertTrue($user->authenticatedByNonAmbientCredential());
    }

    public function testAGuardWithoutTheMarkerIsTreatedAsAmbient(): void
    {
        $guard = new class implements Guard {
            public function identify(Request $request): ?Identity
            {
                return new class implements Identity {
                    public function id(): int|string|null
                    {
                        return 1;
                    }
                };
            }
        };

        $user = new RequestUser([$guard], new Request('GET', '/'));

        self::assertFalse($user->authenticatedByNonAmbientCredential());
    }

    /**
     * Guards are consulted in order and the first answer wins, which makes
     * registration order a security decision rather than a stylistic one.
     */
    public function testTheFirstGuardToAnswerWins(): void
    {
        $first = $this->guardReturningId(1);
        $second = $this->guardReturningId(2);

        self::assertSame(1, (new RequestUser([$first, $second], new Request('GET', '/')))->id());
        self::assertSame(2, (new RequestUser([$second, $first], new Request('GET', '/')))->id());
    }

    public function testAGuardReturningNothingIsPassedOver(): void
    {
        $silent = new class implements Guard {
            public function identify(Request $request): ?Identity
            {
                return null;
            }
        };

        $user = new RequestUser([$silent, $this->guardReturningId(9)], new Request('GET', '/'));

        self::assertSame(9, $user->id());
    }

    /**
     * Guards can be expensive — they read a session file, or hash a password —
     * so a page asking in a layout and again in a controller must not pay twice.
     */
    public function testTheGuardsAreConsultedOncePerRequest(): void
    {
        $calls = 0;

        $guard = new class($calls) implements Guard {
            public function __construct(private int &$calls)
            {
            }

            public function identify(Request $request): ?Identity
            {
                $this->calls++;

                return null;
            }
        };

        $user = new RequestUser([$guard], new Request('GET', '/'));
        $user->user();
        $user->user();
        $user->check();
        $user->id();
        $user->guard();

        self::assertSame(1, $calls);
    }

    /**
     * The reason this is not a singleton. Under a long-running worker, a
     * retained answer would mean request two is served as request one — with
     * nothing in any log to say so.
     */
    public function testWithoutARequestNobodyCanBeFound(): void
    {
        self::assertNull((new RequestUser([$this->guardReturningId(3)]))->user());
        self::assertFalse((new RequestUser([$this->guardReturningId(3)]))->check());
    }

    private function guardReturningId(int $id): Guard
    {
        return new class($id) implements Guard {
            public function __construct(private readonly int $id)
            {
            }

            public function identify(Request $request): ?Identity
            {
                return new class($this->id) implements Identity {
                    public function __construct(private readonly int $id)
                    {
                    }

                    public function id(): int|string|null
                    {
                        return $this->id;
                    }
                };
            }
        };
    }
}