<?php

declare(strict_types=1);

namespace Empira\Tests\Fixtures;

use Empira\Application;
use Empira\Application\Modules;
use Empira\Application\Overrides;
use Empira\Application\SqliteStorage;

/**
 * An application declaring the seam attributes, and nothing else.
 *
 * Its own `boot()` is not written because this is a fixture rather than an
 * application — what is under test is that the attributes are *readable from a
 * class that declares them*, which is the one thing the attributes are for.
 */
#[Modules(Application::class)]
#[Overrides(secret: Secret::class, logger: Logger::class, storage: SqliteStorage::class)]
final class SeamApp extends Application
{
}

/** The one method {@see \Empira\Application\Overrides} names. */
final class Secret implements \Empira\Application\ProvidesSecret
{
    public function secret(): string
    {
        return 'a-secret-long-enough-for-testing';
    }
}

/** Likewise. */
final class Logger implements \Empira\Application\ProvidesLogger
{
    public function logger(): \Psr\Log\LoggerInterface
    {
        return new \Psr\Log\NullLogger();
    }
}