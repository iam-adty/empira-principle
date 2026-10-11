<?php

declare(strict_types=1);

namespace IamAdty\Empira\Console\Commands;

use IamAdty\Empira\Console\Command;
use IamAdty\Empira\Console\Output;
use IamAdty\Empira\Data\ConnectionFactory;
use IamAdty\Empira\Data\Migrator;

/**
 * Runs the migrations in a project's `migrations/` directory.
 *
 * The database comes from the environment, because a migration that connected
 * to whatever it liked would be a migration nobody could trust:
 *
 *     DB_DRIVER=pgsql DB_HOST=… DB_DATABASE=… \
 *         php bin/empira migrate --target=path/to/app
 *
 * The same `DB_*` names an application uses at runtime, read through `Env`, so
 * there is one answer to "which database is this" rather than two that can
 * disagree.
 */
final class Migrate implements Command
{
    public function name(): string
    {
        return 'migrate';
    }

    public function description(): string
    {
        return 'Run the migrations in a project\'s migrations/ directory.';
    }

    public function arguments(): array
    {
        return ['[--target=dir]', '[--step=down]'];
    }

    public function handle(array $options, Output $output): int
    {
        $target = rtrim(trim((string) ($options['target'] ?? '')), '/');
        $target = $target === '' ? (getcwd() ?: '.') : $target;

        $directory = $target . '/migrations';

        if (!is_dir($directory)) {
            $output->error(sprintf('No migrations directory at %s.', $directory));

            return 1;
        }

        $steps = trim((string) ($options['step'] ?? ''));

        try {
            $connection = ConnectionFactory::make(self::config());
            $migrator = new Migrator($connection, $directory);

            $did = $steps === 'down'
                ? $migrator->rollback()
                : $migrator->run();
        } catch (\Throwable $e) {
            $output->error($e->getMessage());

            return 1;
        }

        if ($did === []) {
            $output->warn($steps === 'down' ? 'Nothing to roll back.' : 'Nothing to migrate.');

            return 0;
        }

        foreach ($did as $name) {
            $output->line(($steps === 'down' ? '  rolled back ' : '  migrated ') . $name);
        }

        return 0;
    }

    /**
     * Database settings from the environment, under `db.*` so the factory
     * reads the same keys an application's configuration would.
     */
    private static function config(): \IamAdty\Empira\Config\Config
    {
        $env = $_ENV;

        return new \IamAdty\Empira\Config\Config([
            'driver' => $env['DB_DRIVER'] ?? 'sqlite',
            'database' => $env['DB_DATABASE'] ?? ':memory:',
            'host' => $env['DB_HOST'] ?? '127.0.0.1',
            'port' => $env['DB_PORT'] ?? 0,
            'username' => $env['DB_USERNAME'] ?? '',
            'password' => $env['DB_PASSWORD'] ?? '',
        ]);
    }
}
