<?php

declare(strict_types=1);

namespace Empira\Config;

/**
 * A tiny reader for `.env`-style files.
 *
 * Configuration is not allowed to be a hard dependency of the framework, so
 * this only understands the common subset: `KEY=value`, `#` comments, an
 * optional `export ` prefix, single or double quotes, and `${OTHER}`
 * interpolation against values defined earlier in the same file.
 *
 * A missing file is not an error — most apps simply run without one.
 *
 *     Env::fromFile('/app/.env')  // ['APP_ENV' => 'local', ...]
 */
final class Env
{
    /** Matches `export KEY=value` and validates the key shape. */
    private const LINE = '/^(?:export\s+)?([A-Za-z_][A-Za-z0-9_.]*)\s*=\s*(.*)$/';

    /**
     * Parse the contents of an env file.
     *
     * @return array<string, string>
     */
    public static function parse(string $contents): array
    {
        $values = [];

        foreach (preg_split('/\R/', $contents) ?: [] as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            if (preg_match(self::LINE, $line, $matches) !== 1) {
                continue;
            }

            [, $key, $value] = $matches;

            $values[$key] = self::unquote(trim($value), $values);
        }

        return $values;
    }

    /**
     * Read and parse a file, treating an unreadable path as "no env".
     *
     * @return array<string, string>
     */
    public static function fromFile(string $path): array
    {
        if (!is_file($path) || !is_readable($path)) {
            return [];
        }

        $contents = file_get_contents($path);

        return $contents === false ? [] : self::parse($contents);
    }

    /**
     * @param array<string, string> $values
     */
    private static function unquote(string $value, array $values): string
    {
        if (strlen($value) >= 2) {
            $quote = $value[0];

            if (($quote === '"' || $quote === "'") && str_ends_with($value, $quote)) {
                $value = substr($value, 1, -1);
            }
        }

        return self::interpolate($value, $values);
    }

    /**
     * Replace `${KEY}` placeholders with earlier values, leaving unknown ones
     * as written so a missing key is visible rather than silently blank.
     *
     * @param array<string, string> $values
     */
    private static function interpolate(string $value, array $values): string
    {
        return preg_replace_callback(
            '/\$\{([A-Za-z_][A-Za-z0-9_]*)\}/',
            static fn (array $matches): string => $values[$matches[1]] ?? $matches[0],
            $value
        ) ?? $value;
    }
}