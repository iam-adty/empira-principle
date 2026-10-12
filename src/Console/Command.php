<?php

declare(strict_types=1);

namespace Empira\Console;

/**
 * One console command.
 *
 * A command describes itself for the listing and returns an exit code, so the
 * dispatcher stays a map lookup and a call:
 *
 *     final class Ping implements Command
 *     {
 *         public function name(): string { return 'ping'; }
 *         public function description(): string { return 'Check the app boots.'; }
 *         public function arguments(): array { return []; }
 *         public function handle(array $options, Output $output): int
 *         {
 *             $output->success('pong');
 *
 *             return 0;
 *         }
 *     }
 *
 * ## The options array
 *
 * `run()` parses argv before calling `handle()`: positional arguments land in
 * `arguments` as a list, `--force` becomes `force => true`, and `--target=dir`
 * becomes `target => 'dir'`. Commands read their own options out of that array
 * and supply their own defaults, so there is nothing to configure and nothing to
 * validate beyond what the command itself cares about.
 */
interface Command
{
    /**
     * The name typed on the command line, e.g. `make:component`.
     */
    public function name(): string;

    /**
     * One line, shown in the command listing.
     */
    public function description(): string;

    /**
     * The usage hint after the name, e.g. `['<Name>', '[--force]']`.
     *
     * @return list<string>
     */
    public function arguments(): array;

    /**
     * Run the command.
     *
     * @param array{arguments?: list<string>, ...<string, mixed>} $options parsed argv
     *
     * @return int the exit code: 0 for success, non-zero for failure
     */
    public function handle(array $options, Output $output): int;
}
