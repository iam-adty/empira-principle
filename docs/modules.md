# Modules

> **Status: describes shipped code.** Verified against `473 tests, 1071
> assertions`.

The framework assumes an application needs very little. Everything else is
something you install — a package implementing `Module`, wired in one call.

```php
$app->module(new AuthModule([new SessionGuard($app->session(), $users)]));
```

Authentication is the one module that ships, in
[`../auth`](../auth). It is the worked example for everything here.

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
[AGENTS.md](../../dev/agents/principle.md#known-traps).