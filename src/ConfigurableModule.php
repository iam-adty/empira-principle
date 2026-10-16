<?php

declare(strict_types=1);

namespace Empira;

/**
 * A module that takes options from the application that installs it.
 *
 * Modules are normally resolved by class name and built by the container, which
 * is what makes `#[Modules(AuthModule::class)]` possible — and what makes them
 * impossible to pass anything to. A module that needs one thing from the
 * application it is being installed into had, until this interface, nowhere to
 * ask for it.
 *
 * ## Why an interface rather than a constructor the application knows about
 *
 * `Application` cannot call `AuthModule::usePage()`, because core does not know
 * that class exists — and should not. Every module that wanted a seam would
 * otherwise become a type the framework had to be told about, which is how a
 * module system turns into a list of special cases.
 *
 * So the module says what it accepts, and the application says what it offers,
 * and neither names the other. An option nobody understands is simply ignored.
 */
interface ConfigurableModule extends Module
{
    /**
     * @param array<string, mixed> $options keyed by option name; a module ignores
     *                                     any it does not recognise, so an
     *                                     application may pass the same set to
     *                                     every module it installs
     */
    public function configure(array $options): void;
}