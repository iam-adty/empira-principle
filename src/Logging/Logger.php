<?php

declare(strict_types=1);

namespace IamAdty\Empira\Logging;

/**
 * Where an app says what happened.
 *
 * Deliberately tiny: one `log()` plus the four levels most code reaches for.
 * The convenience methods exist so the common case reads well at the call
 * site; each implementation forwards them to `log()`, so a level nobody calls
 * directly is still loggable and an implementation only has one thing to write.
 *
 * @see FileLogger writes to a file
 * @see NullLogger throws it all away, for tests and throwaway scripts
 */
interface Logger
{
    /**
     * Record one entry.
     *
     * @param array<string, mixed> $context
     */
    public function log(LogLevel $level, string $message, array $context = []): void;

    /**
     * @param array<string, mixed> $context
     */
    public function debug(string $message, array $context = []): void;

    /**
     * @param array<string, mixed> $context
     */
    public function info(string $message, array $context = []): void;

    /**
     * @param array<string, mixed> $context
     */
    public function warning(string $message, array $context = []): void;

    /**
     * @param array<string, mixed> $context
     */
    public function error(string $message, array $context = []): void;
}