<?php

declare(strict_types=1);

namespace IamAdty\Empira\Logging;

/**
 * The logger that discards everything.
 *
 * The right default for tests, CLI scripts and anything that has no business
 * writing to disk: pass one in and no call site needs a `if ($debug)` around
 * it.
 */
final class NullLogger implements Logger
{
    public function log(LogLevel $level, string $message, array $context = []): void
    {
    }

    public function debug(string $message, array $context = []): void
    {
        $this->log(LogLevel::Debug, $message, $context);
    }

    public function info(string $message, array $context = []): void
    {
        $this->log(LogLevel::Info, $message, $context);
    }

    public function warning(string $message, array $context = []): void
    {
        $this->log(LogLevel::Warning, $message, $context);
    }

    public function error(string $message, array $context = []): void
    {
        $this->log(LogLevel::Error, $message, $context);
    }
}