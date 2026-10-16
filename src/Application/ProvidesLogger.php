<?php

declare(strict_types=1);

namespace Empira\Application;

use Psr\Log\LoggerInterface;

/**
 * Where an application gets its logger.
 *
 * One method, so replacing it is one method. The default is JSON on stderr.
 *
 * @see StderrLogger the framework's own
 * @see \Empira\Application::log() where the result is handed over
 */
interface ProvidesLogger
{
    public function logger(): LoggerInterface;
}