<?php

declare(strict_types=1);

namespace IamAdty\Empira\Logging;

/**
 * Appends one line per entry to a file — the default, and usually the only,
 * logger an app needs.
 *
 *     [2026-01-02 03:04:05] ERROR: Checkout failed {"order":42}
 *
 * Two details are deliberate. Entries are separated by a single "\n" and a
 * message carrying newlines has them collapsed to a literal `\n`, while context
 * is JSON-encoded, so nothing a caller passes — an exception message, a
 * payload, a user-supplied string — can forge a second entry and poison
 * whatever reads this file.
 *
 * And a write that fails is dropped. A full disk or an unwritable path must
 * not take the request down with it; the absence of a log line is a smaller
 * problem than the 500 it would cause.
 */
final class FileLogger implements Logger
{
    private const TIMESTAMP = 'Y-m-d H:i:s';

    public function __construct(
        private readonly string $path,
        private readonly LogLevel $minimumLevel = LogLevel::Debug,
        private readonly bool $withContext = true,
    ) {
    }

    public function log(LogLevel $level, string $message, array $context = []): void
    {
        if ($level->severity() > $this->minimumLevel->severity()) {
            return;
        }

        if (!$this->prepareDirectory()) {
            return;
        }

        @file_put_contents($this->path, $this->line($level, $message, $context) . "\n", FILE_APPEND | LOCK_EX);
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

    private function prepareDirectory(): bool
    {
        $directory = dirname($this->path);

        if (is_dir($directory)) {
            return true;
        }

        return @mkdir($directory, 0777, true) || is_dir($directory);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function line(LogLevel $level, string $message, array $context): string
    {
        $line = sprintf('[%s] %s: %s', date(self::TIMESTAMP), $level->label(), self::flatten($message));

        $encoded = $this->withContext ? self::encode($context) : null;

        return $encoded === null ? $line : $line . ' ' . $encoded;
    }

    /**
     * Fold every kind of line break into a literal `\n` so one entry is always
     * one line.
     */
    private static function flatten(string $message): string
    {
        return str_replace(["\r\n", "\r", "\n"], ['\\n', '\\n', '\\n'], $message);
    }

    /**
     * @param array<string, mixed> $context
     */
    private static function encode(array $context): ?string
    {
        if ($context === []) {
            return null;
        }

        $json = json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        // Context that cannot be encoded — invalid UTF-8, a resource — is left
        // out rather than allowed to throw; the entry still matters.
        return $json === false ? null : $json;
    }
}