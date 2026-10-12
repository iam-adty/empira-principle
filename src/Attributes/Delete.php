<?php

declare(strict_types=1);

namespace Empira\Attributes;

/**
 * Marks a method as an htmx action reached with HTTP DELETE.
 *
 * Implies {@see Action}, so it can be used on its own.
 */
#[\Attribute(\Attribute::TARGET_METHOD)]
final class Delete
{
}
