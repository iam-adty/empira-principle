<?php

declare(strict_types=1);

namespace Empira\Tests;

use Empira\Application;
use Empira\Http\Middleware;
use Empira\Http\Request;
use Empira\Http\Response;
use Empira\Module;
use Empira\Security\Guard;
use Empira\Security\Identity;
use Empira\Security\RequestUser;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use PHPUnit\Framework\TestCase;

/**
 * The extension point modules plug into.
 *
 * Deliberately small. The temptation with a "module system" is to build a
 * dependency sorter, a lifecycle, and a way for modules to depend on each other
 * — and all of it would be machinery for a graph of three edges that reads
 * perfectly well in the order it was written.
 */
final class ModuleTest extends TestCase
{
    public function testAModuleIsRegisteredAndItsRegisterMethodIsCalled(): void
    {
        $app = new Application(secret: 'a-secret-long-enough-for-testing');

        $module = new class implements Module {
            public bool $called = false;

            public function register(Application $app): void
            {
                $this->called = true;
            }
        };

        $app->module($module);

        self::assertTrue($module->called);
        self::assertSame([$module], $app->modules());
    }

    public function testRegistrationIsChainable(): void
    {
        $app = new Application(secret: 'a-secret-long-enough-for-testing');

        $result = $app->module($this->nullModule())->module($this->nullModule());

        self::assertSame($app, $result);
        self::assertCount(2, $app->modules());
    }

    /**
     * Order is the module author's responsibility, and it is load-bearing: a
     * module that installs global middleware has to do it before anything else
     * runs, or its middleware is registered too late to be part of the chain.
     * Registering immediately — rather than collecting and calling later — is
     * what makes "too late" impossible to write by accident.
     */
    public function testAModuleRunsAsSoonAsItIsRegistered(): void
    {
        $app = new Application(secret: 'a-secret-long-enough-for-testing');
        $app->auth($this->guardReturningId(4));

        $observed = null;

        $app->module(new class($observed) implements Module {
            public function __construct(private mixed &$observed)
            {
            }

            public function register(Application $app): void
            {
                // At registration time, with no request in flight, asking for
                // the context is legal and finds nobody — which is exactly
                // what a module wiring things up should expect.
                $this->observed = $app->guards();
            }
        });

        self::assertCount(1, $observed);
    }

    /**
     * The property the whole arrangement exists for: a module can add a guard
     * to the application without the application knowing anything about it.
     */
    public function testAModuleCanInstallAGuardTheFrameworkHasNoClassFor(): void
    {
        $app = new Application(secret: 'a-secret-long-enough-for-testing');

        $app->module(new class implements Module {
            public function register(Application $app): void
            {
                $app->auth(new class implements Guard {
                    public function identify(Request $request): ?Identity
                    {
                        return new class implements Identity {
                            public function id(): int|string|null
                            {
                                return 42;
                            }
                        };
                    }
                });
            }
        });

        $app->routes()->get('/who', fn(RequestUser $user): string => (string) $user->id());

        self::assertSame('42', $app->handle(new Request('GET', '/who'))->body());
    }

    /**
     * A module that wants middleware gets it from the same public API the
     * application uses, so installing a module and hand-wiring it produce the
     * same application.
     */
    public function testAModuleCanInstallMiddleware(): void
    {
        $app = new Application(secret: 'a-secret-long-enough-for-testing');

        $app->module(new class implements Module {
            public function register(Application $app): void
            {
                $app->use(new class implements Middleware {
                    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
                    {
                        return Response::html('intercepted');
                    }
                });
            }
        });

        $app->fallback(fn(Request $request): string => 'original');

        self::assertSame('intercepted', $app->handle(new Request('GET', '/anything'))->body());
    }

    /**
     * A module that cannot be satisfied should say so at boot. Failing halfway
     * through the first request instead leaves the application looking healthy
     * until traffic arrives.
     */
    public function testAModuleMayRefuseToRegister(): void
    {
        $app = new Application(secret: 'a-secret-long-enough-for-testing');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('needs orm() first');

        $app->module(new class implements Module {
            public function register(Application $app): void
            {
                throw new \RuntimeException('This module needs orm() first.');
            }
        });
    }

    private function nullModule(): Module
    {
        return new class implements Module {
            public function register(Application $app): void
            {
            }
        };
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