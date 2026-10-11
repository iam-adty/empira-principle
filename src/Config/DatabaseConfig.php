<?php

declare(strict_types=1);

namespace IamAdty\Empira\Config;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;

/**
 * Where the application finds its database, in one place.
 *
 * This exists because there used to be two answers. `Application::database()`
 * read `DB_*` from the environment with one set of defaults, and the `migrate`
 * console command read the same variables with *different* ones — SQLite in
 * memory, host `127.0.0.1`. Running `empira migrate` without the variables set
 * therefore migrated a database that did not exist and vanished on exit, which
 * nobody noticed only because the application migrated on boot instead.
 *
 * One reader, one set of defaults. A command and a request cannot disagree
 * about which database they are talking to, because neither of them decides.
 *
 *     $config = DatabaseConfig::fromEnvironment();
 *     $connection = DatabaseConfig::connection($config);
 *
 * Defaults are for *values* only. A driver this class cannot build throws
 * rather than falling back to SQLite, because a connection that quietly opens
 * the wrong database is a working application holding no data.
 */
final class DatabaseConfig
{
    /**
     * The database described by the environment, with the framework's defaults.
     *
     * @return Config flat `driver`, `database`, `host`, `port`, `username`, `password`
     */
    public static function fromEnvironment(): Config
    {
        return new Config([
            'driver' => $_ENV['DB_DRIVER'] ?? 'sqlite',
            'database' => $_ENV['DB_DATABASE'] ?? ':memory:',
            'host' => $_ENV['DB_HOST'] ?? '127.0.0.1',
            'port' => (int) ($_ENV['DB_PORT'] ?? 0),
            'username' => $_ENV['DB_USERNAME'] ?? '',
            'password' => $_ENV['DB_PASSWORD'] ?? '',
        ]);
    }

    /**
     * DBAL's spelling of the same settings.
     *
     * The driver names differ on purpose — Doctrine means them, and translating
     * them anywhere else would be the bug.
     *
     * @return array<string, mixed>
     *
     * @throws \InvalidArgumentException on a driver DBAL does not have
     */
    public static function dbalParameters(Config $config): array
    {
        $driver = strtolower($config->string('driver', 'sqlite'));

        $base = match ($driver) {
            'sqlite' => ['driver' => 'pdo_sqlite', 'path' => $config->string('database', ':memory:')],
            'mysql', 'mariadb' => ['driver' => 'pdo_mysql'],
            'pgsql', 'postgres', 'postgresql' => ['driver' => 'pdo_pgsql'],
            default => throw new \InvalidArgumentException(sprintf(
                'Unknown database driver "%s"; expected sqlite, mysql or pgsql.',
                $driver
            )),
        };

        if ($base['driver'] === 'pdo_sqlite') {
            return $base;
        }

        // Port only when one was actually given: a port of 0 is not "port zero",
        // it is "the driver decides", and passing it through is how a connection
        // to a socket-only server starts failing on a machine that used to work.
        $parameters = [
            'host' => $config->string('host', '127.0.0.1'),
            'dbname' => $config->string('database', ''),
            'user' => $config->string('username', ''),
            'password' => $config->string('password', ''),
        ];

        if ($config->int('port', 0) > 0) {
            $parameters['port'] = $config->int('port', 0);
        }

        return $base + $parameters;
    }

    /**
     * An open DBAL connection for these settings.
     *
     * @throws \Doctrine\DBAL\Exception on a database that is not there
     */
    public static function connection(Config $config): Connection
    {
        return DriverManager::getConnection(self::dbalParameters($config));
    }
}