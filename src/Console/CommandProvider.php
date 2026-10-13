<?php

declare(strict_types=1);

namespace Empira\Console;

use Empira\Module;

/**
 * A module that ships its own console commands.
 *
 * Separate from {@see Module} for exactly the reason
 * {@see \Empira\MigrationSource} is: most modules have no commands, and an
 * interface every module must implement only to return an empty array is an
 * interface that teaches nothing. Opting in means there are commands.
 *
 * ## Why this exists when `$app->command()` already does
 *
 * Because calling it means reaching into the application from inside
 * `register()`. That works, and {@see \App\SeedCommand} in the workspace's
 * scratch app does precisely that — so this is not about capability, it is about
 * where the answer lives.
 *
 * A module that registers a command by hand mixes two things: what the module
 * *is*, and how it happens to be wired into whichever application it lands in.
 * The second is a decision the installing application may disagree with, and
 * cannot override — there is no way for an app to say "install this module
 * without its `db:vacuum` command". Declaring them here keeps the module's
 * commands a property of the module, and leaves the application in charge of
 * deciding whether to install the module at all.
 *
 * ```php
 * final class BackupModule implements Module, CommandProvider
 * {
 *     public function register(Application $app): void
 *     {
 *         // …guards, entity paths, whatever else this module brings
 *     }
 *
 *     public function commands(): array
 *     {
 *         return ['backup:run' => new RunBackup($app->entities())];
 *     }
 * }
 * ```
 *
 * ## Ordering
 *
 * Collected by {@see \Empira\Application::module()} immediately after
 * `register()`, beside `MigrationSource`. Two modules offering the same command
 * name is refused by `Application::command()` rather than resolved by whichever
 * happened to be registered last.
 *
 * A command cannot take a name the framework already owns. That is checked by
 * the console when it is built, not here — `Application` cannot know which
 * names `empira` ships, so it accepts them and the console refuses them by name.
 */
interface CommandProvider
{
    /**
     * This module's commands, keyed by the name each is invoked with.
     *
     * The key is checked against the framework's own commands when the console
     * is assembled, so `migrate` here fails with `migrate` named rather than
     * with a stack trace from whichever one won.
     *
     * @return array<string, Command>
     */
    public function commands(): array;
}