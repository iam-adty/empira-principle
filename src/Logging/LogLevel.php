<?php

declare(strict_types=1);

namespace IamAdty\Empira\Logging;

/**
 * The eight standard levels, in the order everyone already uses them.
 *
 * The values are lowercase so they read well in config (`log.level = notice`)
 * and so `fromName()` is a lookup rather than a parse. Two views are offered:
 * `label()` for the uppercase word a log file wants, `severity()` for the
 * number a filter wants — lower is more severe, so `emergency` is 0 and
 * `debug` is 7.
 *
 *     LogLevel::fromName('Notice')   // LogLevel::Notice
 *     LogLevel::Notice->label()      // 'NOTICE'
 */
enum LogLevel: string
{
    case Debug = 'debug';
    case Info = 'info';
    case Notice = 'notice';
    case Warning = 'warning';
    case Error = 'error';
    case Critical = 'critical';
    case Alert = 'alert';
    case Emergency = 'emergency';

    /**
     * The uppercase name, as it appears in a log line.
     */
    public function label(): string
    {
        return strtoupper($this->value);
    }

    /**
     * How serious this level is: 0 for `emergency` up to 7 for `debug`.
     *
     * Lower is worse, which is what makes a minimum level a single
     * comparison — write when `$level->severity() <= $minimum->severity()`.
     */
    public function severity(): int
    {
        return match ($this) {
            self::Emergency => 0,
            self::Alert => 1,
            self::Critical => 2,
            self::Error => 3,
            self::Warning => 4,
            self::Notice => 5,
            self::Info => 6,
            self::Debug => 7,
        };
    }

    /**
     * Resolve a level from a name, whatever case it was written in.
     *
     * Returns null for anything unrecognised — a typo in a config value should
     * not become an exception during a request.
     */
    public static function fromName(string $name): ?self
    {
        return self::tryFrom(strtolower($name));
    }
}