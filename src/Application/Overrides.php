<?php

declare(strict_types=1);

namespace Empira\Application;

/**
 * The defaults this application is built from.
 *
 * Named here rather than passed to a constructor, so replacing one is one word
 * on one line rather than an edit to the constructor that also builds a logger:
 *
 *     #[Overrides(logger: MyLogger::class)]
 *
 * **The cost is that a wrong class is a runtime failure**, where a wrong
 * `protected function` signature would be caught at compile time. Resolving all
 * three happens before the application touches anything else, so a mistake stops
 * the boot with a message naming the class and the contract it missed, rather
 * than surfacing mid-request.
 *
 * @see ProvidesSecret
 * @see ProvidesLogger
 * @see ProvidesStorage
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class Overrides
{
    /** @param class-string<ProvidesSecret> $secret */
    /** @param class-string<ProvidesLogger> $logger */
    /** @param class-string<ProvidesStorage> $storage */
    public function __construct(
        public readonly string $secret,
        public readonly string $logger,
        public readonly string $storage,
    ) {
    }
}