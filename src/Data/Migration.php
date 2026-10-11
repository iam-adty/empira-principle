<?php

declare(strict_types=1);

namespace IamAdty\Empira\Data;

/**
 * An object a migration file returns.
 *
 * Each file is named for when it happened and returns one of these. Keeping
 * `down()` beside `up()` is the point: a migration you cannot reverse is a
 * migration you cannot try out, and a mistake made on a developer's machine
 * becomes permanent the moment the table is created.
 */
interface Migration
{
    /**
     * Bring the schema forward.
     */
    public function up(Connection $connection): void;

    /**
     * Undo {@see Migration::up()}.
     *
     * Best effort where a database cannot undo something — SQLite cannot drop
     * a column without rebuilding the table — so this documents what "reversed"
     * honestly means for each migration.
     */
    public function down(Connection $connection): void;
}
