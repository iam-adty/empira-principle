# Modules

> **Status: describes shipped code.** Verified against `521 tests, 1168
> assertions`.

The framework assumes an application needs very little. Everything else is
something you install — a package implementing `Module`, wired in one call.

```php
$app->module(new AuthModule([new SessionGuard($app->session(), $users)]));
```

Modules ship as their own packages:
[`iam-adty/empira-auth`](https://github.com/iam-adty/empira-auth) for
authentication, [`iam-adty/empira-frankenphp`](https://github.com/iam-adty/empira-frankenphp)
for worker mode, and
[`iam-adty/empira-debug-tools`](https://github.com/iam-adty/empira-debug-tools)
for request profiling. Authentication is the worked example for everything here.

## Why this exists

Authentication used to be in the framework's spine. `Application` imported the
`Auth` class, constructed one on every request, and `orm()` would not describe a
configuration without mapping a `users` table first. Every application carried
authentication whether or not it wanted any — and "make it optional" was a rewrite
rather than a move, because the dependency ran *inward*, from core to feature.

That direction is the whole problem. Core cannot depend on a feature if the
feature is optional, so the cut has to run the other way: core declares the
contracts, and a package implements them.

## What core keeps

| Contract | Answers |
|---|---|
| `Module` | one method — `register(Application $app): void` |
| `MigrationSource` | which migration directories this module ships |
| `Console\CommandProvider` | which console commands this module ships |

And, for authentication specifically, the contracts that make it possible to ask
who is asking without owning an answer:

| Contract | Answers |
|---|---|
| `Security\Identity` | who is this — an `id()`, and nothing else |
| `Security\Guard` | given *this* request, whose credential is it |
| `Security\RequestUser` | the guards, asked once per request |
| `Security\NonAmbientCredential` | marker: a browser will not send this by itself |

## Writing one

```php
use Empira\Application;
use Empira\Module;

final class GreetingModule implements Module
{
    public function register(Application $app): void
    {
        $app->container()->bind(Greeter::class, fn() => new Greeter());
    }
}
```

`register()` is called **once, during `$app->module()`, before any request is
handled**. Throwing from it is how a module refuses to be wired into an
application that cannot support it, which is much better than failing halfway
through the first request.

Registration is immediate rather than collected and called later, so "I
registered too late" cannot be written by accident. The order is yours to get
right: a module that adds middleware must be installed before anything runs, and
one that needs `orm()` to have been called must come after it.

### Anything per-request goes through the container

`register()` runs once, but the container is what `Application::handle()` rebuilds
between requests. So a module that publishes something per-request binds a
factory, never an object:

```php
$app->container()->bind(Auth::class, function ($container) use ($app): Auth {
    return new Auth($app->guards(), $container->get(Request::class));
});
```

Two things about that line, both learned the hard way:

- **`bind`, not `singleton`.** The factory closes over the *current* `Request`. A
  shared instance is a service frozen at whichever request first resolved it —
  request two served as request one, silently.
- **The factory, not the object, closes over `$app`.** Reading `$app->guards()`
  inside the closure is what lets guards registered *after* the module still be
  picked up.

### Shipping migrations

Implement `MigrationSource` alongside `Module`:

```php
public function migrations(): array
{
    return ['Empira\\Migrations\\Greeting' => dirname(__DIR__) . '/migrations'];
}
```

Each directory needs **its own** namespace, matching the `namespace` line inside
its files exactly. Doctrine maps `namespace => directory` and keys its ledger by
version, so:

- Sharing a namespace with another package means two files declaring the same
  class name, and which one runs depends on which `require` reached the
  definition first — a coin toss that looks like a successful migration.
- A namespace that does not match the files means the file is found, read, and
  discarded, and `migrate` reports "Nothing to migrate" with no error anywhere.
  That is a failure shaped exactly like success.

`empira migrate` plans across every source — the project's and every module's —
in one sequence against one version ledger. That is what makes "the module's
table, then your column on it" a question with one answer.

The command learns about modules because `Bootstrap::boot()` returns the booted
application rather than only its commands, and `bin/empira` hands it to the
console, which hands it to `migrate`. **Anything else driving the console has to
do the same**, or it will migrate a schema missing every table the modules own.

### Shipping commands

Implement `CommandProvider` alongside `Module`:

```php
public function commands(): array
{
    return ['greet:everyone' => new GreetEveryone($app->entities())];
}
```

The commands are collected by `Application::module()` immediately after
`register()`, beside migrations. A module that returns no commands registers none,
which is why the interface is opt-in rather than part of `Module`: most modules
have nothing to put there.

Declaring them here rather than calling `$app->command()` inside `register()`
keeps the module's commands a property of the module. Registering by hand works
and a scratch application does it, but it mixes what the module *is* with how it
was wired in — and there is then no way for the installing application to say
*install this module without its `db:vacuum`*.

A command cannot take a name `empira` already owns. `Application` cannot know
which names those are without holding a copy that would drift, so it accepts the
registration and the console refuses it by name when it is built:

```
$ vendor/bin/empira list
empira: A command called "migrate" is already registered.
```

## The application's own commands

Modules are half of it. Commands belonging to the application rather than to a
package are **found rather than listed** — `Application::discoverCommands()` scans
a directory, and any class implementing `Console\Command` is one:

```php
// bootstrap/app.php — the console entry, not public/index.php
$app = App::bootConsole();

$app->discoverCommands(dirname(__DIR__) . '/src/Console', 'App\\Console');

return $app;
```

Two things this decides for you. The name comes from `Command::name()`, not the
filename, so a class called `Vacuum` may be invoked as `db:vacuum`. And commands
are built by the container, so one may declare what it needs:

```php
final class Purge implements Command
{
    public function __construct(private readonly Application $app) {}
}
```

A class in the directory that is not a command is skipped, so `app/Console/` can
hold helpers, and a directory that does not exist contributes nothing — an
application with no commands has no `src/Console/`, and that is not an error to
report.

Discovery belongs in the console's entry point rather than in the application's
boot, for the same reason the web and console boots are separate methods: `boot()`
serving traffic knows nothing about what an application chooses to expose on a
command line.

## What a module is, in Composer terms

The contracts above are what a module implements in PHP. This is what it is in
`composer.json`, and the distinction is not bookkeeping — it is the same cut,
drawn in the place where dependency direction is actually enforced.

| | `type` | Requires |
|---|---|---|
| `iam-adty/empira-principle` | `library` | nothing |
| a module, e.g. `iam-adty/empira-auth` | `library` | `empira-principle` |
| an application | `project` | `empira-principle` + the modules it installs |

A module is a **library** and requires the framework. An application is a
**project** and requires its modules. Neither fact is cosmetic:

- A library is something other packages may depend on, so its own dependencies are
  its business and its `composer.lock` is not committed — what it resolves to is
  its consumers' decision.
- A project is the thing that closes the set. Its lock is committed, because a
  deployed application is not reproducible otherwise.

If a module were `type: project`, Composer would treat it as an application root
and consumers could not require it as a dependency at all. The failure is a
resolution error at install time, which is at least an honest one — much better
than the framework depending on a feature, which nothing catches until the
dependency is already the wrong way round.

`dev/tools/module-contract` checks this across every package in the workspace, and
lives in `dev` rather than here on purpose: enforcing it needs to see every
package at once, and a test in `principle` that reached into `../auth` would be the
inward dependency this document exists to argue against — dressed as a test.

## What a module may not do

**Name a framework internal.** Core does not know its classes and must not depend
on any. If a module seems to need something from the framework's spine, the
question is whether the framework can answer it *without knowing what the feature
is about* — usually it can, and usually the honest answer is a narrower contract.

**Add a default that changes an application's behaviour.** The old `orm()`
silently mapped a `users` table, which meant an application could not have an ORM
without getting a schema it never asked for. A module's defaults are its own;
everything that touches an application's decisions is the application's to make.

## The boundary worth measuring

Core's read-only `RequestUser` and a module's `Auth` are **two separate objects**,
even though `Auth` extends `RequestUser`. Binding one under both names looks like
tidiness and is a trap: `Auth` narrows `user()` to its own `User`, so a guard
returning any other `Identity` is silently reported as *nobody*. PHP accepts the
narrowing without complaint, and the symptom reads as a broken feature rather
than as a type mistake. See the framework's
[AGENTS.md](https://github.com/iam-adty/empira-dev/blob/main/agents/principle.md).