<?php

declare(strict_types=1);

namespace IamAdty\Empira\Data;

/**
 * The friendly way in: a bag of methods that build and run queries for you.
 *
 * Most of what an application does with a database is one of five verbs
 * against one table, and writing it as a two-line chain every time adds noise
 * without adding safety. `Db` is the short path; {@see Query} is there for
 * everything else, and it is what these methods return if you want to keep
 * configuring.
 *
 *     $db = new Db($connection);
 *
 *     $db->insert('users', ['name' => 'Ada', 'team_id' => 1]);
 *
 *     $rows = $db->select('users', fn (Query $q) => $q->where('team_id', 1)->orderBy('name'));
 *
 *     $db->first('users', fn (Query $q) => $q->where('name', 'Ada'));
 *
 *     $db->update('users', ['name' => 'Ada L.'], fn (Query $q) => $q->where('id', 1));
 *
 *     $db->delete('users', fn (Query $q) => $q->where('id', 1));
 *
 * `select()` and `first()` hand back plain associative arrays; nothing here
 * needs to know about PDO statements or fetch modes.
 */
final class Db
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function connection(): Connection
    {
        return $this->connection;
    }

    /**
     * A query builder already pointed at this connection and the given table.
     */
    public function table(string $name): Query
    {
        return (new Query($this->connection))->get($name);
    }

    /**
     * Every matching row, or an empty list when nothing matches.
     *
     * @param (callable(Query): void)|null $configure
     *
     * @return list<array<string, mixed>>
     */
    public function select(string $table, ?callable $configure = null): array
    {
        return $this->configure($this->table($table), $configure)->fetch();
    }

    /**
     * The first matching row, or null when nothing matches.
     *
     * @param (callable(Query): void)|null $configure
     *
     * @return array<string, mixed>|null
     */
    public function first(string $table, ?callable $configure = null): ?array
    {
        return $this->configure($this->table($table), $configure)->first();
    }

    /**
     * Insert one row and report how many rows that was, which is 1 or 0.
     *
     * @param array<string, mixed> $values
     */
    public function insert(string $table, array $values): int
    {
        return $this->table($table)->insert($values)->affected();
    }

    /**
     * @param array<string, mixed>    $values
     * @param (callable(Query): void)|null $configure
     */
    public function update(string $table, array $values, ?callable $configure = null): int
    {
        return $this->configure($this->table($table), $configure)->update($values)->affected();
    }

    /**
     * @param (callable(Query): void)|null $configure
     */
    public function delete(string $table, ?callable $configure = null): int
    {
        return $this->configure($this->table($table), $configure)->delete()->affected();
    }

    /**
     * Run SQL you wrote yourself, with bindings still parameterised.
     *
     * Values are bound, so this is safe for anything that is a value; the SQL
     * itself is not checked, and identifiers cannot be bound by any driver.
     *
     * @param array<int, mixed> $bindings
     *
     * @return list<array<string, mixed>>
     */
    public function raw(string $sql, array $bindings = []): array
    {
        return $this->connection->fetchAll($sql, $bindings);
    }

    /**
     * @param (callable(Query): void)|null $configure
     */
    private function configure(Query $query, ?callable $configure): Query
    {
        if ($configure !== null) {
            $configure($query);
        }

        return $query;
    }
}