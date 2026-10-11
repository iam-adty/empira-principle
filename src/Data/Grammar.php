<?php

declare(strict_types=1);

namespace IamAdty\Empira\Data;

/**
 * The dialect a connection speaks.
 *
 * Most SQL is portable. What is not is how an identifier is quoted: MySQL and
 * SQLite use backticks, PostgreSQL uses double quotes, and a builder that emits
 * the wrong one produces a syntax error at runtime rather than at build time.
 * That is the whole reason this interface exists — it is the smallest thing
 * that has to differ between two databases.
 *
 * The identifier *validation* deliberately does not live here. Every grammar
 * inherits it from {@see AbstractGrammar}, so a new dialect cannot ship with a
 * looser check by accident. That check is a security boundary, not a
 * convenience: a column name arriving from a form is an injection vector.
 *
 * @see AbstractGrammar
 * @see Grammars\SqliteGrammar
 * @see Grammars\MySqlGrammar
 * @see Grammars\PostgresGrammar
 */
interface Grammar
{
    /**
     * The PDO driver name this grammar serves, e.g. `sqlite`.
     */
    public function driver(): string;

    /**
     * Check an identifier and quote it, segment by segment.
     *
     * `*` and `table.*` pass through; anything that is not plainly an
     * identifier is refused rather than escaped into something that merely
     * looks safe.
     *
     * @throws \InvalidArgumentException when the identifier is not one
     */
    public function quoteIdentifier(string $identifier): string;
}
