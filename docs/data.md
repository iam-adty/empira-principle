# Data

> **Status: describes shipped code.** Verified against `790 tests, 1451
> assertions`; the PostgreSQL tests run against a real server in Docker.

Three layers, each of which knows only about the one below it:

| Layer | What it is |
|---|---|
| `Data\Connection` | a PDO handle that turns errors into exceptions and rows into arrays |
| `Data\Query` | builds SQL and its bindings, and can run them |
| `Data\Db` | the five verbs an application actually reaches for |

There is no model layer, and that is deliberate. See
[Preparing for an ORM](#preparing-for-an-orm) for what was built to make one
possible later, and what was deliberately left out.

## Connecting

```php
$app->database(['driver' => 'sqlite', 'database' => __DIR__ . '/app.sqlite']);
```

That is the whole wiring. `Connection` and `Db` go into the container, so from
then on anything can ask for one by type:

```php
final class TodoRepository
{
    public function __construct(private readonly Db $db) {}
}
```

Before `database()` is called the container cannot build a `Db` — its
constructor wants a `Connection`, which wants a `PDO`, and nothing about a
`PDO` can be guessed from a type. Nothing connects until you ask:

```php
$app->db();   // LogicException: No database is configured
```

With no argument it reads `db.*` from the container's `Config`.

| Key | Default | |
|---|---|---|
| `driver` | `sqlite` | `sqlite`, `mysql`/`mariadb`, `pgsql` |
| `database` | `:memory:` | a file path, or a database name |
| `host`, `port` | `127.0.0.1`, per driver | |
| `username`, `password` | driver default | |
| `log_queries` | `false` | record every statement |

An unknown driver is refused. A factory that fell back to SQLite would turn a
typo into a working application with no data in it.

### The keys are flat

`ConnectionFactory::make()` takes the **contents** of the `db` section, not the
section itself:

```php
$app->database(['driver' => 'sqlite', 'database' => ':memory:']);   // right
$app->database(['db' => ['driver' => 'sqlite']]);                   // wrong
```

The second form finds nothing and quietly opens an in-memory database. That
silence is why there is a test for it.

## Dialects

Most SQL is portable. How an identifier is quoted is not: MySQL and SQLite use
backticks, PostgreSQL double quotes, and a builder that emits the wrong one
produces a syntax error at request time rather than at build time.

A `Grammar` owns that, and nothing else:

```php
$connection->grammar();                       // derived from the PDO driver
(new Query(null, new PostgresGrammar()))->get('users')->toSql();
```

The **validation** deliberately does not vary. Every grammar inherits it from
`AbstractGrammar`, because a column name arriving from a form is a first-class
injection vector and a dialect with a looser check would be a hole. There is a
test asserting each grammar *inherits* the check rather than reimplementing it.

A `Query` with no connection behaves as SQLite, which is what keeps the
SQL-only tests meaningful.

> **MySQL and MariaDB are unit-tested, not integration-tested.** The suite runs
> PostgreSQL live in Docker; it asserts the SQL MySQL would receive, but no test
> runs against a MySQL server. Treat `MySqlGrammar` as reviewed, not verified.

## Queries

```php
$db->select('users', fn(Query $q) => $q->where('active', true)->orderBy('name'));
$db->first('users',  fn(Query $q) => $q->where('id', 7));
$db->insert('users', ['name' => 'Ada']);
$db->update('users', ['name' => 'Ada L.'], fn(Query $q) => $q->where('id', 1));
$db->delete('users', fn(Query $q) => $q->where('id', 1));
```

Aggregates, grouping and joins:

```php
$db->table('orders')
    ->select('customer_id')
    ->sum('total')
    ->groupBy('customer_id')
    ->having('SUM', 'total', 100, '>=')
    ->fetch();

$db->table('users')->leftJoin('teams', 'users.team_id', '=', 'teams.id')->fetch();
```

`having()` takes the function and the column rather than a name, because a
`HAVING` clause refers to the aggregate expression: SQL resolves neither the
selected alias nor a bare `sum`.

### Injection

Values never reach the SQL string. They become `?` placeholders and travel in
`bindings()` — including `LIMIT` and `OFFSET`.

Identifiers cannot be bound by any driver, so they are checked against
`AbstractGrammar::IDENTIFIER` and quoted by the dialect. Anything that is not
plainly an identifier raises rather than being escaped into something that
merely looks safe:

```php
$db->first('users', fn(Query $q) => $q->where('name = 1 OR 1', 'x'));
// InvalidArgumentException: Unsafe SQL identifier
```

`select()` takes identifiers, not expressions. There is no `col AS alias`
parsing and no raw fragment escape hatch, because both are places where a
string stops being checked — and that is the first half of an ORM, not
something to smuggle in.

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

Describing the column instead of writing the SQL removes the dialect branch
this section used to warn about. There is no portable spelling for an
auto-incrementing column in raw SQL — SQLite wants `AUTOINCREMENT` and
PostgreSQL wants `SERIAL` — but `['autoincrement' => true]` means the same thing
in both, and the platform decides.

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

## Transactions

```php
$db->connection()->transaction(function (Connection $c) use ($db): void {
    $db->insert('orders', ['total' => 100]);
    $db->insert('stock', ['id' => 1, 'left' => -1]);
});   // rolls back if anything throws
```

An outer transaction is left alone, so nesting is safe.

## Preparing for an ORM

What was built for one:

- **`Grammar`** — the dialect seam. A second database is a class, not a fork.
- **The query features an ORM needs** — aggregates, `groupBy`, `having`,
  `leftJoin`, `exists`. Without them there is nothing to build a model layer on.
- **`Db` autowires** — a repository or model can take `Db` in its constructor
  and be built by the container.
- **`UserProvider`** — see [auth.md](auth.md). Auth depends on the interface,
  never on `Db`, so an ORM-backed provider is a new class rather than a change
  to auth.

What was deliberately **not** built: a model base class, relationships, lazy
loading, scopes, or a schema builder. Each is the first half of an ORM, and a
framework that ships half an ORM has to keep it.

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
- [docs/auth.md](auth.md) — who is asking
- [ARCHITECTURE.md](../ARCHITECTURE.md) — where data sits in a request
