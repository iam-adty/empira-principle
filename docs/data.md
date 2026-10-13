# Data

> **Status: describes shipped code.** Verified against `473 tests, 1071
> assertions`; the PostgreSQL tests run against a real server in Docker.

Persistence is Doctrine's. The framework wires it and gets out of the way:

| Layer | What it is |
|---|---|
| `Persistence\EntityManagerFactory` | builds the `EntityManager` |
| `Config\DatabaseConfig` | one reader for `DB_*`, and the only place a DSN is built |
| `Auth\User` | the framework's one entity, mapped to `users` |

There is no query builder, and there is deliberately no second way to reach the
database. A hand-written data layer next to an ORM is two answers to every
question — what quoting does this driver want, which transactions are allowed,
where does the version ledger live — and only one of them gets maintained.

## Connecting

```php
$app->orm(['driver' => 'sqlite', 'database' => __DIR__ . '/app.sqlite']);

// …now in any component, controller or middleware:
public function __construct(private readonly EntityManagerInterface $em) {}
```

or, from the environment:

```php
$app->orm();   // reads DB_DRIVER, DB_DATABASE, DB_HOST, …
```

`$app->entities()` hands back the manager, and is what throws if `orm()` was
never called.

Entities are found through the manager, not by constructing them:

```php
$users = $app->entities()->getRepository(User::class);

$user = $users->find(1);
$user = $users->findOneBy(['email' => 'ada@example.com']);
$users->save($user);
```

## The worker rule

**`EntityManager` is shared per worker; its identity map is not.**

`find()` on something the manager has already loaded returns the cached object
**without touching the database**. Under php-fpm that is invisible, because the
process ends with the request. Under a worker it means request two is served
request one's user — silently, with nothing in any log.

So `Application::handle()` calls `clear()` on the manager at the start of every
request, beside the `Session::beginRequest()` it already called. Same trap, same
answer, same reason. It is tested in both directions: comment the `clear()` out
and [OrmLifecycleTest](../tests/OrmLifecycleTest.php) fails with the second
request still reporting the first request's name.

## Migrations

```bash
php bin/empira migrate --target=path/to/app
php bin/empira migrate --target=path/to/app --status
php bin/empira migrate --target=path/to/app --step=down
```

Migrations run through [Doctrine Migrations](https://www.doctrine-project.org/projects/migrations.html).
Files live in `migrations/`, are named `Version<when>`, and extend
`AbstractMigration`:

```php
// migrations/Version20261011000001.php
namespace Empira\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261011000001 extends AbstractMigration
{
    public function up(Schema $schema): void
    {
        $users = $schema->createTable('users');
        $users->addColumn('id', 'integer', ['autoincrement' => true]);
        $users->setPrimaryKey(['id']);
        $users->addColumn('email', 'string', ['length' => 255]);
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('users');
    }
}
```

Three things the namespace and the name are not decoration. Doctrine globs
`Version*.php`, and it only collects a class that lives in the namespace the
command registered — a file with no `namespace` statement is silently invisible,
and `migrate` reports "Nothing to migrate" with no explanation.

**Migrations are a deploy step, not something that happens on boot.** Under a
worker, boot runs once per thread, so a boot that migrates has every thread
racing through the same migration. Run them where a deploy runs them.

The version ledger is Doctrine's, in a `migrations` table it creates itself.
`--step=down` undoes exactly the most recent migration rather than the whole
history: a mistake should cost one migration, not all of them.

## Where the database connection comes from

One reader, `DatabaseConfig`, used by both the application and the console:

```php
$config = DatabaseConfig::fromEnvironment();          // reads DB_*
$connection = DatabaseConfig::connection($config);    // a DBAL connection
```

This used to be two readers with different defaults, and the difference was a
trap: `empira migrate` with no `DB_*` set migrated an in-memory SQLite that
vanished on exit, while the application sat on a real database. It printed
"migrated" either way. A command and a request cannot disagree about which
database they mean, because neither of them decides.

## Dialects

There are none of the framework's own. Doctrine's platform classes quote, limit
and offset, and knowing which is which is not a decision this framework gets to
make again.

The portable spelling of a column type is still not portable: SQLite has no
`JSONB` and PostgreSQL has no `CLOB`, and a migration that has to run on both
asks the platform which one it is standing on. Asking through the Schema is
better than writing a type and being told `type "clob" does not exist`.

## Transactions

```php
$app->entities()->wrapInTransaction(function (EntityManagerInterface $em): void {
    $em->persist($order);
    $em->persist($line);
});   // rolls back if anything throws
```

`wrapInTransaction()` nests safely: an inner transaction that is already inside
an outer one is a savepoint, not a second commit.

## Running the tests

The suite runs in Docker against a real PostgreSQL:

```bash
composer test                 # starts the services if they are down
```

`tools/test` refuses to run if the database cannot start, rather than reporting
a pass with the driver tests silently skipped. To iterate on SQLite alone:

```bash
vendor/bin/phpunit --exclude-group driver
```

## Further reading

- [docs/routing.md](routing.md) — handlers and middleware
- [auth/README.md](https://github.com/iam-adty/empira-auth/blob/main/README.md) — who is asking, and what an entity means here
- [ARCHITECTURE.md](../ARCHITECTURE.md) — where persistence sits in a request