<?php

declare(strict_types=1);

namespace Empira;

/**
 * One option an application gives a module, declared as an attribute.
 *
 * ## Why an interface rather than a named argument
 *
 * `#[Modules(AuthModule::class, login: LoginLayout::class)]` cannot work: a
 * variadic must be the last parameter, and PHP will not accept anything after
 * one. Putting the option in its own attribute solves that, and the attribute
 * can implement this so the framework can find every option an application
 * declared without being told what any of them are called.
 *
 * That is the whole point. **The framework never learns an option's name.** A
 * module wanting to be told something publishes an attribute; the application
 * puts it on the class; nothing in core changes, because nothing in core was
 * involved. Adding a module option is a change to that module alone — no
 * application edit, no framework edit, no release note here.
 *
 *     #[LoginPage(LoginLayout::class)]     // in the module's package
 *     final class LoginPage implements ModuleOption
 *     {
 *         public function options(): array
 *         {
 *             return ['login' => $this->page];
 *         }
 *     }
 *
 * @see ConfigurableModule the other half — the module that receives them
 */
interface ModuleOption
{
    /**
     * What this option contributes, as name => value.
     *
     * Merged across every {@see ModuleOption} an application declares, and
     * handed to each {@see ConfigurableModule}.
     *
     * Two options contributing one name: the **last declared** wins, because an
     * application saying the same thing twice has made a choice and honouring it
     * is less surprising than an exception about it. Note that this is two
     * *different* attribute classes agreeing on a key — PHP refuses to apply
     * the same attribute twice unless it is `IS_REPEATABLE`, so an author
     * wanting a repeatable attribute must declare it so.
     *
     * @return array<string, mixed>
     */
    public function options(): array;
}