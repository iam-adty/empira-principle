<?php

declare(strict_types=1);

namespace IamAdty\Empira\Data;

use PDO;

/**
 * A thin wrapper around PDO: execute a statement, hand back plain rows.
 *
 * Nothing clever happens here. The value the wrapper adds is a single place
 * where errors become exceptions, rows come back as associative arrays rather
 * than as whatever fetch mode the caller last left on the handle, and queries
 * can be recorded for debugging.
 *
 *     $connection = Connection::sqlite(':memory:', ['CREATE TABLE users (
 *         id INTEGER PRIMARY KEY, name TEXT)']);
 *
 *     $connection->execute('INSERT INTO users (name) VALUES (?)', ['Ada']);
 *     $connection->fetchAll('SELECT * FROM users');   // [['id' => 1, 'name' => 'Ada']]
 *
 * When you build the PDO yourself, set
 * `PDO::ATTR_EMULATE_PREPARES => false` on MySQL so parameter binding happens
 * in the server rather than in the client.
 */
final class Connection
{
    /** @var list<array{sql: string, bindings: array<int, mixed>, ms: float}> */
    private array $log = [];

    public function __construct(
        private readonly PDO $pdo,
        private readonly bool $logQueries = false,
    ) {
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->pdo->setAttribute(PDO::ATTR_STRINGIFY_FETCHES, false);
    }

    /**
     * A ready-to-use connection, optionally seeded with a schema.
     *
     * SQLite needs no server, which makes it the right choice for tests and for
     * a prototype that has not picked its database yet.
     *
     * @param list<string> $schema statements run in order, e.g. `CREATE TABLE ...`
     */
    public static function sqlite(string $path = ':memory:', array $schema = []): self
    {
        $pdo = new PDO('sqlite:' . $path);
        $connection = new self($pdo);

        foreach ($schema as $statement) {
            $connection->execute($statement);
        }

        return $connection;
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    /**
     * Every statement run since construction, when logging is switched on.
     *
     * @return list<array{sql: string, bindings: array<int, mixed>, ms: float}>
     */
    public function queries(): array
    {
        return $this->log;
    }

    /**
     * Run a statement for its side effect and report how many rows it touched.
     *
     * @param array<int, mixed> $bindings
     */
    public function execute(string $sql, array $bindings = []): int
    {
        $start = microtime(true);

        $statement = $this->pdo->prepare($sql);
        $statement->execute($bindings);

        if ($this->logQueries) {
            $this->log[] = [
                'sql' => $sql,
                'bindings' => $bindings,
                'ms' => round((microtime(true) - $start) * 1000, 4),
            ];
        }

        return $statement->rowCount();
    }

    /**
     * @param array<int, mixed> $bindings
     *
     * @return list<array<string, mixed>>
     */
    public function fetchAll(string $sql, array $bindings = []): array
    {
        return $this->run($sql, $bindings)->fetchAll();
    }

    /**
     * @param array<int, mixed> $bindings
     *
     * @return array<string, mixed>|null
     */
    public function fetchOne(string $sql, array $bindings = []): ?array
    {
        $row = $this->run($sql, $bindings)->fetch();

        return $row === false ? null : $row;
    }

    public function lastInsertId(): string
    {
        return $this->pdo->lastInsertId();
    }

    /**
     * Run a callback inside a transaction, rolling back if it throws.
     *
     * An outer transaction is left alone, so nesting is safe.
     *
     * @param callable(Connection): mixed $callback
     */
    public function transaction(callable $callback): mixed
    {
        if ($this->pdo->inTransaction()) {
            return $callback($this);
        }

        $this->pdo->beginTransaction();

        try {
            $result = $callback($this);
        } catch (\Throwable $e) {
            $this->pdo->rollBack();

            throw $e;
        }

        $this->pdo->commit();

        return $result;
    }

    /**
     * @param array<int, mixed> $bindings
     */
    private function run(string $sql, array $bindings): \PDOStatement
    {
        $start = microtime(true);

        $statement = $this->pdo->prepare($sql);
        $statement->execute($bindings);

        if ($this->logQueries) {
            $this->log[] = [
                'sql' => $sql,
                'bindings' => $bindings,
                'ms' => round((microtime(true) - $start) * 1000, 4),
            ];
        }

        return $statement;
    }
}