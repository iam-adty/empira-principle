<?php

declare(strict_types=1);

namespace IamAdty\Empira\Data\Grammars;

use IamAdty\Empira\Data\AbstractGrammar;

/**
 * MySQL, which quotes identifiers with backticks like SQLite.
 *
 * Identical to {@see SqliteGrammar} in every respect this interface covers.
 * It exists as its own class because "they happen to agree today" is not the
 * same as "they are the same": MySQL has quoting corners SQLite does not, and
 * this is where they would diverge.
 */
final class MySqlGrammar extends AbstractGrammar
{
    public function driver(): string
    {
        return 'mysql';
    }
}
