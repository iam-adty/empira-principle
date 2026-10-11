<?php

declare(strict_types=1);

namespace IamAdty\Empira\Data;

use IamAdty\Empira\Data\Grammars\SqliteGrammar;

/**
 * A fluent query builder that is useful even without a database.
 *
 * The builder is split in two halves on purpose. The first — `from()`, `where()`,
 * `orderBy()`, `insert()` and friends — records what to do and does nothing
 * else. The second — `toSql()` and `bindings()` — turns that record into a SQL
 * string and its parameters. Only the terminal methods (`fetch()`, `first()`,
 * `affected()`) reach for a connection, and they refuse to run without one.
 *
 *     $query = (new Query())
 *         ->get('users')
 *         ->where('active', true)
 *         ->orderBy('name');
 *
 *     $query->toSql();      // 'SELECT * FROM `users` WHERE `active` = ? ORDER BY `name`'
 *     $query->bindings();   // [true]
 *
 * That split is the point: the interesting half of a query builder is the SQL
 * assembly, and it can be asserted in a unit test without a server, a schema or
 * a fixture. A builder that only exists to be executed forces every test
 * through a live database to prove something that was string manipulation all
 * along.
 *
 * ## Injection
 *
 * Values are never interpolated. Every one of them becomes a `?` placeholder
 * and travels in `bindings()`, including `LIMIT` and `OFFSET`.
 *
 * Identifiers cannot be bound by any driver, so they are checked and quoted
 * by the builder's {@see Grammar} — each dot-separated segment quoted on its
 * own, so `users.name` becomes `` `users`.`name` `` in SQLite and MySQL and
 * `"users"."name"` in PostgreSQL. A column name that arrives from a form, a
 * query string or a config file is a first-class injection vector, so anything
 * that is not plainly an identifier raises an {@see \InvalidArgumentException}
 * rather than being escaped into something that merely looks safe.
 *
 * A query is single-use: build one chain and hand it to one terminal method.
 */
final class Query
{
    /** The column an aggregate result is reported under. */
    private const AGGREGATE_ALIAS = 'aggregate';

    /** @var list<string> closed, so a function name is never interpolated freely */
    private const AGGREGATES = ['COUNT', 'SUM', 'AVG', 'MIN', 'MAX'];

    /** @var list<string> */
    private const OPERATORS = ['=', '!=', '<>', '>', '<', '>=', '<=', 'like', 'not like'];

    /** @var list<string> */
    private const DIRECTIONS = ['asc', 'desc'];

    private string $type = 'select';

    private ?string $table = null;

    /** @var list<string> */
    private array $columns = [];

    /** @var list<string> pre-rendered `INNER JOIN ... ON ...` clauses */
    private array $joins = [];

    /** @var list<array{sql: string, bindings: list<mixed>}> */
    private array $wheres = [];

    /** @var list<string> pre-rendered `column direction` pairs */
    private array $orders = [];

    /** @var list<string> quoted column names */
    private array $groups = [];

    /** @var list<array{sql: string, bindings: list<mixed>}> */
    private array $havings = [];

    /** @var list<string> quoted aggregate expressions, e.g. `COUNT(*) AS aggregate` */
    private array $aggregates = [];

    private ?int $limit = null;

    private ?int $offset = null;

    /** @var array<string, mixed> quoted column name => value */
    private array $writes = [];

    private readonly Grammar $grammar;

    /**
     * A builder with no connection builds SQL and nothing else.
     *
     * Without an explicit grammar it takes the connection's dialect, and with
     * neither it behaves as SQLite — so an unconnected builder still produces
     * the same backticked SQL it always did, which is what keeps the SQL-only
     * tests meaningful.
     */
    public function __construct(
        private readonly ?Connection $connection = null,
        ?Grammar $grammar = null,
    ) {
        $this->grammar = $grammar ?? $connection?->grammar() ?? new SqliteGrammar();
    }

    /**
     * The dialect this builder writes SQL in.
     */
    public function grammar(): Grammar
    {
        return $this->grammar;
    }

    /**
     * Shorthand for the common case: `from($table)`.
     *
     * It does not select `*` explicitly, because that would sit in the
     * projection alongside an aggregate and turn `count()` into
     * `SELECT *, COUNT(*)`. An empty projection already means "everything".
     */
    public function get(string $table): self
    {
        return $this->from($table);
    }

    public function from(string $table): self
    {
        $this->table = $this->quote($table);

        return $this;
    }

    /**
     * Replace the selected columns. Called with nothing, it selects `*`.
     */
    public function select(string ...$columns): self
    {
        $this->columns = array_map($this->quote(...), $columns);

        return $this;
    }

    /**
     * Join on two identifiers, e.g. `join('teams', 'users.team_id', '=', 'teams.id')`.
     *
     * The condition is identifiers on both sides of the operator, so it takes
     * no bindings — this is the simple form, not the general one.
     */
    public function join(string $table, string $first, string $operator, string $second): self
    {
        return $this->joinAs('INNER', $table, $first, $operator, $second);
    }

    /**
     * A left join: keep every row on the left, with nulls where the right side
     * has nothing. The common way to answer "which of these have no children?".
     */
    public function leftJoin(string $table, string $first, string $operator, string $second): self
    {
        return $this->joinAs('LEFT', $table, $first, $operator, $second);
    }

    public function rightJoin(string $table, string $first, string $operator, string $second): self
    {
        return $this->joinAs('RIGHT', $table, $first, $operator, $second);
    }

    private function joinAs(string $type, string $table, string $first, string $operator, string $second): self
    {
        $this->joins[] = sprintf(
            '%s JOIN %s ON %s %s %s',
            $type,
            $this->quote($table),
            $this->quote($first),
            self::operator($operator),
            $this->quote($second),
        );

        return $this;
    }

    /**
     * Add a condition, ANDed with any already recorded.
     *
     * A `null` value becomes `IS NULL` rather than `= NULL`, which no row ever
     * satisfies — an easy trap for a builder that takes the value blindly.
     */
    public function where(string $column, mixed $value, string $operator = '='): self
    {
        if ($value === null) {
            return $this->whereNull($column);
        }

        $this->wheres[] = [
            'sql' => sprintf('%s %s ?', $this->quote($column), self::operator($operator)),
            'bindings' => [$value],
        ];

        return $this;
    }

    /**
     * @param array<mixed> $values
     */
    public function whereIn(string $column, array $values): self
    {
        $values = array_values($values);

        if ($values === []) {
            throw new \InvalidArgumentException('whereIn() needs at least one value.');
        }

        $this->wheres[] = [
            'sql' => sprintf('%s IN (%s)', $this->quote($column), implode(', ', array_fill(0, count($values), '?'))),
            'bindings' => $values,
        ];

        return $this;
    }

    public function whereNull(string $column): self
    {
        $this->wheres[] = ['sql' => $this->quote($column) . ' IS NULL', 'bindings' => []];

        return $this;
    }

    /**
     * Collapse rows into groups of this column.
     *
     * Almost always paired with an aggregate — a `GROUP BY` with nothing to
     * aggregate produces one arbitrary row per group, which is a confusing way
     * to say "I did not mean to do this".
     */
    public function groupBy(string ...$columns): self
    {
        foreach ($columns as $column) {
            $this->groups[] = $this->quote($column);
        }

        return $this;
    }

    /**
     * A condition on an aggregate, for narrowing the groups a `groupBy` made.
     *
     *     ->sum('price')->groupBy('team_id')->having('SUM', 'price', 30, '>=')
     *     // … HAVING SUM(`price`) >= ?
     *
     * It takes the function and the column rather than a name, because a
     * HAVING clause refers to the aggregate expression, not to the alias it
     * was selected under — SQL will not resolve `HAVING `aggregate``, and will
     * not resolve a bare `sum` either, because there is no column by that name.
     */
    public function having(string $function, string $column, mixed $value, string $operator = '='): self
    {
        $this->havings[] = [
            'sql' => sprintf('%s %s ?', $this->aggregateExpression($function, $column), self::operator($operator)),
            'bindings' => [$value],
        ];

        return $this;
    }

    /**
     * Select one aggregate over the whole result, aliased `aggregate`.
     *
     *     (new Query($db))->get('users')->count()->fetchOne();
     *     // ['aggregate' => 42]
     *
     * The alias exists so the result is the same shape whatever was aggregated,
     * which is what lets a caller read one key instead of guessing between
     * `count` and `total`.
     */
    public function count(string $column = '*'): self
    {
        return $this->aggregate('COUNT', $column);
    }

    public function sum(string $column): self
    {
        return $this->aggregate('SUM', $column);
    }

    public function avg(string $column): self
    {
        return $this->aggregate('AVG', $column);
    }

    public function min(string $column): self
    {
        return $this->aggregate('MIN', $column);
    }

    public function max(string $column): self
    {
        return $this->aggregate('MAX', $column);
    }

    public function orderBy(string $column, string $direction = 'asc'): self
    {
        $direction = strtolower($direction);

        if (!in_array($direction, self::DIRECTIONS, true)) {
            throw new \InvalidArgumentException(sprintf('Unknown sort direction: %s', $direction));
        }

        $this->orders[] = $this->quote($column) . ' ' . $direction;

        return $this;
    }

    /**
     * `null` clears the limit. Negative values are a bug at the call site, not
     * a shorthand for "no limit", so they are refused.
     */
    public function limit(?int $limit): self
    {
        if ($limit !== null && $limit < 0) {
            throw new \InvalidArgumentException('The limit cannot be negative.');
        }

        $this->limit = $limit;

        return $this;
    }

    public function offset(?int $offset): self
    {
        if ($offset !== null && $offset < 0) {
            throw new \InvalidArgumentException('The offset cannot be negative.');
        }

        $this->offset = $offset;

        return $this;
    }

    /**
     * Turn this into an insert. Keys are column names and are validated like
     * any other identifier; a second call merges into the first.
     *
     * @param array<string, mixed> $values
     */
    public function insert(array $values): self
    {
        if ($values === []) {
            throw new \InvalidArgumentException('An insert needs at least one column.');
        }

        $this->type = 'insert';
        $this->writes = array_merge($this->writes, self::quoteKeys($values));

        return $this;
    }

    /**
     * Turn this into an update, constrained by whatever `where()` recorded.
     *
     * @param array<string, mixed> $values
     */
    public function update(array $values): self
    {
        if ($values === []) {
            throw new \InvalidArgumentException('An update needs at least one column.');
        }

        $this->type = 'update';
        $this->writes = array_merge($this->writes, self::quoteKeys($values));

        return $this;
    }

    /**
     * Turn this into a delete, constrained by whatever `where()` recorded.
     *
     * Sort and paging are not part of a delete, so `orderBy()` and `limit()`
     * are ignored here. Filter first.
     */
    public function delete(): self
    {
        $this->type = 'delete';

        return $this;
    }

    public function toSql(): string
    {
        return match ($this->type) {
            'insert' => $this->insertSql(),
            'update' => $this->updateSql(),
            'delete' => 'DELETE FROM ' . $this->table() . $this->whereSql(),
            default => $this->selectSql(),
        };
    }

    /**
     * @return list<mixed> in the order the placeholders appear in {@see toSql()}
     */
    public function bindings(): array
    {
        if ($this->type === 'insert') {
            return array_values($this->writes);
        }

        $bindings = [];

        foreach ($this->wheres as $where) {
            foreach ($where['bindings'] as $binding) {
                $bindings[] = $binding;
            }
        }

        // HAVING placeholders come after the WHERE ones in the SQL and after
        // them in this list, which is the only ordering that matters. Leaving
        // them out would silently bind a condition's value to the wrong clause.
        foreach ($this->havings as $having) {
            foreach ($having['bindings'] as $binding) {
                $bindings[] = $binding;
            }
        }

        if ($this->type === 'update') {
            return [...array_values($this->writes), ...$bindings];
        }

        if ($this->type === 'select' && ($this->limit !== null || $this->offset !== null)) {
            $bindings[] = $this->limit ?? -1;
            $bindings[] = $this->offset ?? 0;
        }

        return $bindings;
    }

    /**
     * Run the query and return every row.
     *
     * @return list<array<string, mixed>>
     */
    public function fetch(): array
    {
        return $this->connection()->fetchAll($this->toSql(), $this->bindings());
    }

    /**
     * Run the query and return its first row, or null when there is none.
     *
     * @return array<string, mixed>|null
     */
    /**
     * Is there at least one matching row?
     *
     * `SELECT *` is enough to answer it and costs the same as `COUNT(*)` on
     * every database worth using, so the row is fetched and discarded rather
     * than counted.
     */
    public function exists(): bool
    {
        return $this->first() !== null;
    }

    public function first(): ?array
    {
        return $this->connection()->fetchOne($this->toSql(), $this->bindings());
    }

    /**
     * Run the query and report how many rows it touched.
     */
    public function affected(): int
    {
        return $this->connection()->execute($this->toSql(), $this->bindings());
    }

    private function connection(): Connection
    {
        if ($this->connection === null) {
            throw new \LogicException('This query has no connection; use toSql() and bindings(), or build it through Db.');
        }

        return $this->connection;
    }

    /**
     * Record `FUNCTION(column) AS aggregate`.
     *
     * The function name is from a closed list rather than interpolated from the
     * caller, and the column goes through the same identifier check as any
     * other — aggregates are SQL too, and `count('id) FROM users; DROP ...')`
     * would otherwise be a way past every other guard in this class.
     *
     * @param 'COUNT'|'SUM'|'AVG'|'MIN'|'MAX' $function
     */
    private function aggregate(string $function, string $column): self
    {
        // The alias goes through the grammar like every other identifier —
        // writing the quote character by hand here is precisely the bug the
        // grammar exists to prevent, and PostgreSQL rejects it as a syntax
        // error at runtime.
        $this->aggregates[] = sprintf(
            '%s AS %s',
            $this->aggregateExpression($function, $column),
            $this->quote(self::AGGREGATE_ALIAS)
        );

        return $this;
    }

    /**
     * `SUM(`price`)`, from a closed function list and a checked column.
     *
     * Aggregates are SQL too, and a caller-supplied function name would walk
     * straight past every other guard in this class.
     */
    private function aggregateExpression(string $function, string $column): string
    {
        $function = strtoupper($function);

        if (!in_array($function, self::AGGREGATES, true)) {
            throw new \InvalidArgumentException(sprintf('Unknown aggregate: %s', $function));
        }

        return sprintf('%s(%s)', $function, $column === '*' ? '*' : $this->quote($column));
    }

    private function selectSql(): string
    {
        // An aggregate sits alongside whatever columns were named, and stands
        // alone when none were — `count()` with no columns is "how many rows",
        // not "one arbitrary row".
        $projection = [...$this->columns, ...$this->aggregates];

        $sql = 'SELECT ' . ($projection === [] ? '*' : implode(', ', $projection));

        $sql .= ' FROM ' . $this->table();

        if ($this->joins !== []) {
            $sql .= ' ' . implode(' ', $this->joins);
        }

        $sql .= $this->whereSql();

        if ($this->groups !== []) {
            $sql .= ' GROUP BY ' . implode(', ', $this->groups);
        }

        if ($this->havings !== []) {
            $sql .= ' HAVING ' . implode(' AND ', array_column($this->havings, 'sql'));
        }

        if ($this->orders !== []) {
            $sql .= ' ORDER BY ' . implode(', ', $this->orders);
        }

        if ($this->limit !== null || $this->offset !== null) {
            $sql .= ' LIMIT ? OFFSET ?';
        }

        return $sql;
    }

    private function insertSql(): string
    {
        $columns = array_keys($this->writes);

        return sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $this->table(),
            implode(', ', $columns),
            implode(', ', array_fill(0, count($columns), '?')),
        );
    }

    private function updateSql(): string
    {
        $assignments = array_map(
            static fn (string $column): string => sprintf('%s = ?', $column),
            array_keys($this->writes),
        );

        return sprintf('UPDATE %s SET %s', $this->table(), implode(', ', $assignments)) . $this->whereSql();
    }

    /**
     * Quote the column names of an insert or an update.
     *
     * Keys come from an array, so a numeric-looking one arrives as an `int`
     * and is cast back to a string only to fail the identifier check: `0` is
     * not a column name. Doing it here rather than at render time means a bad
     * column name fails where it was written, not three calls later.
     *
     * @param array<array-key, mixed> $values
     *
     * @return array<string, mixed>
     */
    private function quoteKeys(array $values): array
    {
        $quoted = [];

        foreach ($values as $column => $value) {
            $quoted[$this->quote((string) $column)] = $value;
        }

        return $quoted;
    }

    private function whereSql(): string
    {
        if ($this->wheres === []) {
            return '';
        }

        return ' WHERE ' . implode(' AND ', array_column($this->wheres, 'sql'));
    }

    private function table(): string
    {
        if ($this->table === null) {
            throw new \LogicException('No table set; call from() or get() first.');
        }

        return $this->table;
    }

    /**
     * Quote an identifier in this builder's dialect.
     *
     * The rules live in {@see Grammar}, not here, because what counts as an
     * identifier must not vary between drivers — and what quotes it must.
     */
    private function quote(string $identifier): string
    {
        return $this->grammar->quoteIdentifier($identifier);
    }

    /**
     * Operators are never bound, so the list is closed rather than escaped.
     */
    private static function operator(string $operator): string
    {
        $operator = strtolower(trim($operator));

        if (!in_array($operator, self::OPERATORS, true)) {
            throw new \InvalidArgumentException(sprintf('Unknown operator: %s', $operator));
        }

        return $operator;
    }
}