<?php

declare(strict_types=1);

namespace IamAdty\Empira\Data;

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
 * Identifiers cannot be bound by any driver, so they are checked against
 * {@see Query::IDENTIFIER} and quoted with backticks, each dot-separated
 * segment quoted on its own so `users.name` becomes `` `users`.`name` ``. A
 * column name that arrives from a form, a query string or a config file is a
 * first-class injection vector, so anything that is not plainly an identifier
 * raises an {@see \InvalidArgumentException} rather than being escaped into
 * something that merely looks safe. Backticks are the MySQL and SQLite dialect;
 * a PostgreSQL connection wants the same builder with double quotes.
 *
 * A query is single-use: build one chain and hand it to one terminal method.
 */
final class Query
{
    /**
     * The shape an identifier must have to be considered an identifier.
     *
     * Letters, digits and underscores, optionally qualified with dots. No
     * spaces, no semicolons, no quotes, no comment markers, no leading digit.
     */
    public const IDENTIFIER = '/^[A-Za-z_][A-Za-z0-9_.]*$/';

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

    private ?int $limit = null;

    private ?int $offset = null;

    /** @var array<string, mixed> quoted column name => value */
    private array $writes = [];

    /**
     * A builder with no connection builds SQL and nothing else.
     */
    public function __construct(private readonly ?Connection $connection = null)
    {
    }

    /**
     * Shorthand for the common case: `from($table)->select('*')`.
     */
    public function get(string $table): self
    {
        return $this->from($table)->select('*');
    }

    public function from(string $table): self
    {
        $this->table = self::quote($table);

        return $this;
    }

    /**
     * Replace the selected columns. Called with nothing, it selects `*`.
     */
    public function select(string ...$columns): self
    {
        $this->columns = array_map(self::quote(...), $columns);

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
        $this->joins[] = sprintf(
            'INNER JOIN %s ON %s %s %s',
            self::quote($table),
            self::quote($first),
            self::operator($operator),
            self::quote($second),
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
            'sql' => sprintf('%s %s ?', self::quote($column), self::operator($operator)),
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
            'sql' => sprintf('%s IN (%s)', self::quote($column), implode(', ', array_fill(0, count($values), '?'))),
            'bindings' => $values,
        ];

        return $this;
    }

    public function whereNull(string $column): self
    {
        $this->wheres[] = ['sql' => self::quote($column) . ' IS NULL', 'bindings' => []];

        return $this;
    }

    public function orderBy(string $column, string $direction = 'asc'): self
    {
        $direction = strtolower($direction);

        if (!in_array($direction, self::DIRECTIONS, true)) {
            throw new \InvalidArgumentException(sprintf('Unknown sort direction: %s', $direction));
        }

        $this->orders[] = self::quote($column) . ' ' . $direction;

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

    private function selectSql(): string
    {
        $sql = 'SELECT ' . ($this->columns === [] ? '*' : implode(', ', $this->columns));

        $sql .= ' FROM ' . $this->table();

        if ($this->joins !== []) {
            $sql .= ' ' . implode(' ', $this->joins);
        }

        $sql .= $this->whereSql();

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
    private static function quoteKeys(array $values): array
    {
        $quoted = [];

        foreach ($values as $column => $value) {
            $quoted[self::quote((string) $column)] = $value;
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
     * Check an identifier and quote it segment by segment.
     *
     * `*` and `table.*` pass through as-is; everything else has to look like an
     * identifier before a backtick is put anywhere near it.
     */
    private static function quote(string $identifier): string
    {
        if ($identifier === '*') {
            return '*';
        }

        if (str_ends_with($identifier, '.*')) {
            return self::quote(substr($identifier, 0, -2)) . '.*';
        }

        if (preg_match(self::IDENTIFIER, $identifier) !== 1) {
            throw new \InvalidArgumentException(sprintf('Unsafe SQL identifier: %s', $identifier));
        }

        $quoted = [];

        foreach (explode('.', $identifier) as $segment) {
            if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $segment) !== 1) {
                throw new \InvalidArgumentException(sprintf('Unsafe SQL identifier: %s', $identifier));
            }

            $quoted[] = '`' . $segment . '`';
        }

        return implode('.', $quoted);
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