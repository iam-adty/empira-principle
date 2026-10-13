<?php

declare(strict_types=1);

namespace Empira\Config;

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
 * Defaults are for *values* only. A driver that is not SQLite throws rather than
 * falling back to it, because a connection that quietly opens the wrong database
 * is a working application holding no data — and on SQLite, "the wrong database"
 * is a file that does not exist yet and will be created empty.
 */
final class DatabaseConfig
{
    /**
     * The database described by the environment, with the framework's defaults.
     *
     * @return Config flat `driver`, `database`
     */
    public static function fromEnvironment(): Config
    {
        return new Config([
            'driver' => $_ENV['DB_DRIVER'] ?? 'sqlite',
            'database' => $_ENV['DB_DATABASE'] ?? ':memory:',
        ]);
    }

    /**
     * DBAL's spelling of the same settings.
     *
     * **SQLite is the only driver.** Doctrine still does all the work — DBAL
     * opens the file, ORM maps and queries it, Migrations plans against it — so
     * none of that is being bypassed by dropping the servers.
     *
     * What went is the *mapping* for the other two, and with it the `host`, `port`,
     * `username` and `password` parameters: those existed only to reach a server,
     * and with no server driver left there is no code path that reads them.
     * Keeping the keys would mean publishing configuration the framework silently
     * ignores, which is how a connection ends up pointing somewhere nobody chose.
     *
     * `DB_DRIVER=pgsql` now refuses, by name, rather than quietly opening a
     * different database — see {@see self::fromEnvironment()}.
     *
     * @return array<string, mixed>
     *
     * @throws \InvalidArgumentException on any driver but sqlite
     */
    public static function dbalParameters(Config $config): array
    {
        $driver = strtolower($config->string('driver', 'sqlite'));

        if ($driver !== 'sqlite') {
            throw new \InvalidArgumentException(sprintf(
                'Unknown database driver "%s"; this framework speaks SQLite only.',
                $driver
            ));
        }

        return ['driver' => 'pdo_sqlite', 'path' => $config->string('database', ':memory:')];
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