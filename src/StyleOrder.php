<?php

declare(strict_types=1);

namespace Empira;

/**
 * Where a composite's own rules sit relative to the ones it collects.
 *
 * CSS gives the last of two equal rules the win, so this is the difference
 * between a parent and its children agreeing on precedence and quietly not.
 *
 * @see Component::aggregateStyles()
 */
enum StyleOrder
{
    /**
     * The composite's rules come first, so its children win a tie.
     *
     * This is what the framework did before aggregation existed, because that is
     * what positional insertion produced: a parent prepends its sheet to its own
     * root while its children's already sit inside them. Preserved as the
     * default so opting in does not silently reorder an application.
     */
    case SelfFirst;

    /**
     * The composite's rules come last, so it wins a tie.
     *
     * For a parent whose whole purpose is to dress its children.
     */
    case SelfLast;
}