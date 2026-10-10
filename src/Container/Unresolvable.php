<?php

declare(strict_types=1);

namespace IamAdty\Empira\Container;

/**
 * Marker returned by parameter resolution when a parameter cannot be filled.
 *
 * A dedicated object is needed because `null` is a legitimate value for an
 * optional object parameter, so "unresolvable" cannot be signalled with null.
 *
 * @internal
 */
final class Unresolvable
{
}