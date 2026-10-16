<?php

declare(strict_types=1);

namespace Empira\Application;

/**
 * The modules an application installs, in order.
 *
 * Variadic rather than an array, because a list of classes reads as a list when
 * there is no second thing to distinguish it from:
 *
 *     #[Modules(AuthModule::class, DebugToolsModule::class)]
 *
 * rather than `#[Modules([AuthModule::class, DebugToolsModule::class])]`, whose
 * brackets say "this is a list" about something the syntax already says.
 *
 * ## It says one thing
 *
 * Which modules load. Options belong to the modules themselves: a module
 * publishes a {@see \Empira\ModuleOption} attribute, the application puts it on
 * its class, and `Application::installModules()` collects them — so core never
 * learns an option's name, and adding one is a change to that module alone.
 *
 * ## Why a name rather than an instance
 *
 * A name the container can resolve is what lets a module build itself from what
 * the framework has already bound — an auth module needs the application's
 * session and its entity manager, and neither exists at the point an attribute
 * is read. A list of instances built eagerly would fail for exactly that reason,
 * which is why this holds names and the application resolves them once the
 * container is ready.
 *
 * @see \Empira\Application::module() where the resolved list is handed over
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class Modules
{
    /** @var list<class-string<\Empira\Module>> */
    public readonly array $modules;

    public function __construct(string ...$modules)
    {
        // `array_values` because a promoted variadic is not possible — PHP
        // forbids promoting one — so the property is assigned here instead. It
        // also normalises named arguments into a list, since `Modules(modules: …)`
        // would otherwise leave keys in.
        $this->modules = array_values($modules);
    }
}