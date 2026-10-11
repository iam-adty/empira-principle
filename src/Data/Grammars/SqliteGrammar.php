<?php

declare(strict_types=1);

namespace IamAdty\Empira\Data\Grammars;

use IamAdty\Empira\Data\AbstractGrammar;

/**
 * SQLite, which quotes identifiers with backticks.
 *
 * The default, because {@see \IamAdty\Empira\Data\Query} builds SQL without a
 * connection and has to pick a dialect: an unconnected builder behaves as
 * SQLite, which is also the dialect most likely to be running a test.
 */
final class SqliteGrammar extends AbstractGrammar
{
    public function driver(): string
    {
        return 'sqlite';
    }
}
