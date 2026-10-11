---
name: add-component
description: Use when adding or changing an Empira component — creating a class extending Component, adding #[Prop] typed state, adding #[Get]/#[Post]/#[Put]/#[Patch]/#[Delete] actions, wiring ->action() elements, or registering a component alias. Covers the escaping rule, the token round trip, and the mandatory registry alias.
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
4. **Every component must be registered.** A token cannot resolve an unregistered
   class.

## Scaffold

```bash
php bin/empira make:component TodoList
```

Then:

```php
<?php

declare(strict_types=1);

namespace App\Components;

use IamAdty\Empira\Attributes\Post;
use IamAdty\Empira\Attributes\Prop;
use IamAdty\Empira\Component;
use IamAdty\Empira\Element;

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

## Register it — not optional

```php
$app->registry()->alias('todos', TodoList::class);
```

Without this the token cannot resolve the class and the request 403s.

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