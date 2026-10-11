<?php

declare(strict_types=1);

namespace IamAdty\Empira\Data\Grammars;

use IamAdty\Empira\Data\AbstractGrammar;

/**
 * PostgreSQL, which quotes identifiers with double quotes.
 *
 * The dialect that {@see Query::quote()} could not serve before grammar
 * existed: backticks are a syntax error here, so every query this grammar
 * builds would have failed on arrival.
 */
final class PostgresGrammar extends AbstractGrammar
{
    protected const OPEN = '"';

    protected const CLOSE = '"';

    public function driver(): string
    {
        return 'pgsql';
    }
}
