<?php

declare(strict_types=1);

namespace Empira;

/**
 * An action was requested over the wrong HTTP method.
 *
 * Raised when a request hits a known action but the verb does not match the one
 * the action declared (`#[Get]`, `#[Delete]`, …). Distinct from
 * {@see ForbiddenActionException}, which means the action is not exposed at all.
 */
final class MethodNotAllowedException extends Exception
{
    /**
     * @param list<string> $allow
     */
    public function __construct(string $message, public readonly array $allow = [])
    {
        parent::__construct($message);
    }
}