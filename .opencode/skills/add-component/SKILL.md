---
name: add-component
description: Use when adding or changing an Empira component — creating a class extending Component, adding #[Prop] typed state, adding #[Get]/#[Post]/#[Put]/#[Patch]/#[Delete] actions, wiring ->action() elements, or making it reachable by token. Covers the escaping rule, the token round trip, and composition.
---

# Adding an Empira component

A component is a class: state as typed properties, behaviour as methods, `render()`
returning an `Element`. Every `hx-*` attribute is generated — you never write one.

## The four rules that break things

1. **`render()` must return `Element|Raw`.** A bare string gets **escaped** when
   appended, turning your markup into visible angle brackets. `Component::markup()`
   is typed `Element|Raw` precisely so this is a type error.
2. **`->action()` takes an action name, not a URL.** It asks the component where its
   endpoint is. Use `->hxPost($url)` only for a literal URL.
3. **Every action needs a verb.** `#[Post]` (or `#[Get]`, `#[Put]`, `#[Patch]`,
   `#[Delete]`). The verb names the `hx-*` attribute **and** the method the router
   accepts — one declaration, so they cannot drift.
4. **Every component must be registered — including one you only nest.** A token
   cannot resolve an unregistered class, and `renderComponent()` does not exempt
   you: it builds the component directly, but the token its button carries is
   resolved through the registry when pressed.

## Scaffold

```bash
php bin/empira make:component TodoList
```

Then:

```php
<?php

declare(strict_types=1);

namespace App\Components;

use Empira\Attributes\Post;
use Empira\Attributes\Prop;
use Empira\Component;
use Empira\Element;

final class TodoList extends Component
{
    #[Prop] public array $items = [];

    #[Post]
    public function add(): void
    {
        $title = trim($this->request()->string('title'));

        if ($title === '') {
            return;
        }

        $this->items[] = ['id' => bin2hex(random_bytes(4)), 'title' => $title];
    }

    protected function render(): Element
    {
        return $this->hx->div('#todos')
            ->append(
                $this->hx->form()
                    ->action('add')
                    ->append($this->hx->input()->attr('name', 'title'), $this->hx->button('Add'))
            )
            ->append($this->hx->ul()->append(...array_map(
                fn(array $i): Element => $this->hx->li()->text($i['title']),
                $this->items
            )));
    }
}
```

## Make it reachable

The application says which namespace is in scope, once:

```php
$app->components(__NAMESPACE__);
```

Every `Component` under it can then be named by a token, **including one you
only ever nest**. That used to need an alias per component, which is why this
section used to be titled "not optional" and why it warned that a nested
component renders perfectly and then answers 404 when somebody presses its
button. That warning described the registration list, not the framework; it is
gone.

An alias is still worth having for two reasons:

```php
$app->registry()->alias('todos', TodoList::class);
```

- **Short tokens.** Without one, the token carries `App\Components\TodoList`,
  in every action URL, for every button.
- **Rename-safety.** Aliases outlive the class moving namespaces; tokens already
  in users' histories do not.

## State: `#[Prop]` or the props array

`#[Prop]` is the modern form:

```php
#[Prop] public int $count = 0;
#[Prop] public ?string $note = null;
```

Hydrated before your code runs, written back when the token is signed. Values are
coerced to the declared type; anything uncoercible falls back to the default.

**Ignored**, because the framework cannot write them: `private`, `static`,
`readonly`.

A class-typed prop accepts only a value that is **already** an instance — a token
naming a class cannot make the framework construct it.

The legacy array API still works and the two may coexist across components, but
within one component a `#[Prop]` key wins over `with()`.

## Actions take what they ask for

An action's parameters are resolved by type, exactly as a controller's are — so
a component can do the work itself rather than handing its form to a controller
somewhere else:

```php
#[Post]
public function login(Auth $auth, UserRepository $users): void
{
    $user = $users->byEmail($this->request()->string('email'));

    if ($user === null || !$users->verify($user, $this->request()->string('password'))) {
        $this->with('error', 'Those credentials did not match any account.');

        return;
    }

    $auth->login($user);

    $this->response()->redirect('/dashboard');
}
```

**The return value is discarded.** An action responds by mutating props (`with()`
re-renders the swap) and the response (`response()->redirect()` sets
`HX-Redirect`). It is what the swap is built from.

`$this->validate()` returns an `ErrorBag` and **does not throw** — a
`try { validate() } catch (ValidationException)` writes invalid data to the
database.

## Styling travels with the component

```php
protected function styles(): Raw
{
    return Raw::of('.todo-list li { margin: .25rem 0 }');
}
```

It is emitted as a `<style>` **inside the root element**, which is the swap
target — so it arrives again with every htmx swap, where a sheet in `<head>`
would be long gone. Return an empty `Raw` for a structural component with no
appearance of its own; a form composes its fields and owns their styling.

## Out of band and lazy

```php
$this->outOfBand($this->hx->span()->id('count')->text('3'));   // swap elsewhere
$this->lazy('load');                                           // fetch after load
$this->validate(['title' => 'required|max:200']);              // returns ErrorBag
$this->flash('success', 'Saved.');                             // next request only
```

## Test it by round trip

Do not assert on markup by hand. Render, follow the signed URL the markup itself
advertises, assert on the fragment:

```php
$html = (string) $app->render(TodoList::class)->render();

preg_match('/hx-post="([^"]*add)"/', $html, $m);
$add = html_entity_decode($m[1], ENT_QUOTES);

$fragment = $app->handle(new Request('POST', $add, body: ['title' => 'walk dog']))->body();

$this->assertStringContainsString('<li>walk dog</li>', $fragment);
```

This exercises signing, routing and rendering together. If wiring breaks, it fails.

**Make sure the assertion can fail.** `assertStringContainsString('todo', $body)`
would pass on any page containing the word. Assert the exact element that can only
appear if the behaviour worked.

## Before you finish

```bash
composer test
find src tests -name '*.php' -print0 | xargs -0 -n1 php -l
```

Then prove the test is real: revert the change, confirm it goes red, restore.

## Reference

- `docs/components.md` — the full component API
- `docs/recipes.md` — copy-pasteable recipes
- `../SECURITY.md` — read before touching action exposure or props