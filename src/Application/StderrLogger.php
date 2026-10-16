<?php

declare(strict_types=1);

namespace Empira\Application;

use Monolog\Formatter\JsonFormatter;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;
use Psr\Log\LoggerInterface;

/**
 * The default logger: JSON on stderr.
 *
 * stderr rather than a file, because a log file needs rotating and cleaning and
 * nobody reads one during development. JSON rather than Monolog's line format,
 * because these lines are meant to be read by something — `jq` over a stream of
 * records beats grepping a stack trace out of prose.
 *
 * ## Why this one is here and the contract is the point
 *
 * `Application::log()` takes a `LoggerInterface`, and that is the interface the
 * framework depends on — deliberately, since a framework that pinned a logging
 * implementation would hand every consumer a dependency they may not want.
 *
 * This class is the one place that decision is spent: the framework ships *a*
 * working default rather than no default, and an application with its own logger
 * never touches Monolog. That is why `monolog/monolog` is a dependency of the
 * package and why nothing in `principle/src` outside this file names it.
 *
 * A bad `LOG_LEVEL` falls back rather than refusing to boot: it is a typo, and a
 * typo should not stop the application serving traffic.
 */
final class StderrLogger implements ProvidesLogger
{
    public function logger(): LoggerInterface
    {
        $handler = new StreamHandler(
            $_ENV['LOG_STREAM'] ?? 'php://stderr',
            $this->level(),
            bubble: true,
        );

        $handler->setFormatter(new JsonFormatter(JsonFormatter::BATCH_MODE_JSON, appendNewline: true));

        return new Logger('app', [$handler]);
    }

    private function level(): Level
    {
        try {
            return Level::fromName(strtolower((string) ($_ENV['LOG_LEVEL'] ?? 'info')));
        } catch (\Throwable) {
            return Level::Info;
        }
    }
}