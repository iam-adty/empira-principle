<?php

declare(strict_types=1);

namespace IamAdty\Empira\Data;

use IamAdty\Empira\Config\Config;

/**
 * Builds a {@see Connection} from configuration.
 *
 * This is the seam that makes a database swappable. Everything above it — the
 * query builder, a repository, an ORM — takes a `Connection` and never learns
 * where it came from; everything below is PDO, which is where the dialect
 * finally matters.
 *
 * The configuration it takes is the **contents** of the `db` section, not the
 * whole application config — flat `driver`, `database`, `host` keys. Passing
 * the nested form would find nothing and quietly fall back to an in-memory
 * SQLite, which is a confusing way to discover a typo.
 *
 *     $connection = ConnectionFactory::make(new Config([
 *         'driver' => 'sqlite',
 *         'database' => __DIR__ . '/app.sqlite',
 *     ]));
 *
 *     $db = new Db($connection);
 *
 * The driver is read from the `driver` key and is deliberately explicit rather than
 * inferred: a framework that guesses which database you meant is a framework
 * that will eventually connect to the wrong one.
 */
final class ConnectionFactory
{
    /**
     * A connection described by the keys above.
     *
     * @throws \InvalidArgumentException when the driver is one this factory
     *                                  cannot build, rather than falling back
     *                                  to something that looks like success
     */
    public static function make(Config $config): Connection
    {
        $driver = strtolower($config->string('driver', 'sqlite'));

        $pdo = match ($driver) {
            'sqlite' => self::sqlite($config),
            'mysql', 'mariadb' => self::mysql($config),
            'pgsql', 'postgres', 'postgresql' => self::postgres($config),
            default => throw new \InvalidArgumentException(sprintf(
                'Unknown database driver "%s"; expected sqlite, mysql or pgsql.',
                $driver
            )),
        };

        return new Connection($pdo, $config->bool('log_queries', false));
    }

    private static function sqlite(Config $config): \PDO
    {
        // SQLite takes a path rather than a host, so the usual DSN keys do not
        // apply and are quietly ignored rather than silently concatenated.
        $pdo = new \PDO('sqlite:' . $config->string('database', ':memory:'));

        // Without this, SQLite silently ignores a foreign key and a cascade
        // delete never happens. It is a per-connection setting, so it has to be
        // set here or not at all.
        $pdo->exec('PRAGMA foreign_keys = ON');

        return $pdo;
    }

    private static function mysql(Config $config): \PDO
    {
        return new \PDO(
            sprintf('mysql:host=%s;port=%d;dbname=%s', $config->string('host', '127.0.0.1'), $config->int('port', 3306), $config->string('database', '')),
            $config->string('username', 'root'),
            $config->string('password', ''),
            [
                // Server-side prepares. With emulation on, PDO interpolates the
                // values into the SQL string in the client, which quietly
                // defeats the binding the rest of this namespace depends on.
                \PDO::ATTR_EMULATE_PREPARES => false,
            ]
        );
    }

    private static function postgres(Config $config): \PDO
    {
        return new \PDO(
            sprintf('pgsql:host=%s;port=%d;dbname=%s', $config->string('host', '127.0.0.1'), $config->int('port', 5432), $config->string('database', '')),
            $config->string('username', 'postgres'),
            $config->string('password', ''),
            [
                \PDO::ATTR_EMULATE_PREPARES => false,
            ]
        );
    }
}
