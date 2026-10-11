<?php

declare(strict_types=1);

namespace IamAdty\Empira\Data;

/**
 * The half of a grammar that is the same everywhere.
 *
 * A dialect is usually one string — a quote character — and everything else is
 * the part that must *not* vary: deciding what counts as an identifier. That
 * decision is a security boundary, because identifiers cannot be bound by any
 * driver and therefore travel inside the SQL string. A column name taken from a
 * form, a query string or a config file is a first-class injection vector.
 *
 * So the check lives here, once, and a dialect that overrides
 * {@see AbstractGrammar::quoteIdentifier()} has to work harder to get it wrong
 * than to get it right.
 *
 *     $grammar->quoteIdentifier('users.name');   // `users`.`name`
 */
abstract class AbstractGrammar implements Grammar
{
    /**
     * The shape an identifier must have to be considered an identifier.
     *
     * Letters, digits and underscores, optionally qualified with dots. No
     * spaces, no semicolons, no quotes, no comment markers, no leading digit.
     */
    public const IDENTIFIER = '/^[A-Za-z_][A-Za-z0-9_.]*$/';

    /** The character that opens a quoted identifier. */
    protected const OPEN = '`';

    /** The character that closes a quoted identifier. */
    protected const CLOSE = '`';

    public function quoteIdentifier(string $identifier): string
    {
        if ($identifier === '*') {
            return '*';
        }

        if (str_ends_with($identifier, '.*')) {
            return $this->quoteIdentifier(substr($identifier, 0, -2)) . '.*';
        }

        if (preg_match(self::IDENTIFIER, $identifier) !== 1) {
            throw new \InvalidArgumentException(sprintf('Unsafe SQL identifier: %s', $identifier));
        }

        $quoted = [];

        // Each dot-separated segment is checked on its own, so `users.name`
        // becomes two quoted identifiers rather than one quoted string with a
        // dot in the middle — which is what a naive implementation produces, and
        // which is wrong for every database.
        foreach (explode('.', $identifier) as $segment) {
            if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $segment) !== 1) {
                throw new \InvalidArgumentException(sprintf('Unsafe SQL identifier: %s', $identifier));
            }

            // `static::`, not `self::`: the constants are declared here but
            // overridden by the dialect, and `self::` would bind to this class
            // and quietly ignore every one of them — which is exactly how
            // PostgreSQL ends up emitting backticks.
            $quoted[] = static::OPEN . $segment . static::CLOSE;
        }

        return implode('.', $quoted);
    }
}
