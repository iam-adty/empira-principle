<?php

declare(strict_types=1);

namespace Empira\Application;

/**
 * One module option, in an attribute of its own.
 *
 * `#[Modules(…)]` names the modules; this names the one thing nearly every
 * application wants to say about one of them.
 *
 *     #[Modules(AuthModule::class)]
 *     #[LoginPage(LoginLayout::class)]
 *
 * It cannot live on `Modules` as a named argument, because a variadic has to be
 * the last parameter and PHP will not allow anything after it. That constraint
 * produced this type, and it turned out to be the better shape anyway: a module's
 * identity and a module's configuration are different facts, and one attribute
 * per fact reads without a comment explaining which half you are looking at.
 *
 * @see \Empira\ConfigurableModule how any option reaches a module
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class LoginPage
{
    /**
     * @param class-string $page a {@see \Empira\View\Layout}, rendered by the
     *                           module for its own sign-in URL
     */
    public function __construct(public readonly string $page)
    {
    }
}