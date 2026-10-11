# Components

> **Status: describes shipped code.** Verified against the current suite.

A component is a class. Its state is a typed property, its behaviour is a
method, and its `render()` returns an `Element`. Everything htmx-related is
generated.

## The smallest useful component

```php
namespace App\Components;

use IamAdty\Empira\Attributes\Post;
use IamAdty\Empira\Attributes\Prop;
use IamAdty\Empira\Component;
use IamAdty\Empira\Element;

final class Counter extends Component
{
    public const STEP = 5;

    #[Prop] public int $count = 0;

    #[Post]
    public function increment(): void
    {
        $this->count += self::STEP;
    }

    #[Post]
    public function decrement(): void
    {
        $this->count -= self::STEP;
    }

    protected function render(): Element
    {
        return $this->hx->div('#counter')
            ->append($this->hx->button('-')->action('decrement'))
            ->append($this->hx->strong()->text((string) $this->count))
            ->append($this->hx->button('+')->action('increment'));
    }
}
```

Register it — a token cannot resolve a class the registry does not know:

```php
$app->registry()->alias('counter', Counter::class);
```

Render it inside a page:

```php
$app->render(Counter::class);                 // Element|Raw, ready to append
$app->render(Counter::class, ['count' => 10]); // with initial props
```

## `render()` must return `Element|Raw`

`Element|Raw|string` is accepted by the signature, and a string *will* be escaped
when you `append()` it — turning your markup into visible angle brackets in the
middle of the page. `Component::markup()` is typed `Element|Raw` so that the
mistake becomes a type error. Return an `Element`.

## State

### `#[Prop]` — typed state

A `#[Prop]` property is hydrated from the incoming props **before any component
code runs**, and written back whenever the component's identity is signed into an
action URL or saved to the state store.

```php
#[Prop] public int $count = 0;
#[Prop] public string $label = 'hello';
#[Prop] public array $items = [];
#[Prop] public ?string $note = null;
```

Values are coerced to the declared type where that is unambiguous (`'7'` → `7`),
and anything that cannot be coerced falls back to the property's default, so a
token written before a type change degrades instead of fataling.

Three properties are **ignored**, because the framework cannot safely write
them:

| Property | Why ignored |
|---|---|
| `private` | state the component cannot see is state the framework cannot round-trip |
| `static` | not per-instance |
| `readonly` | cannot be written after initialisation |

The property is the source of truth. Writing the same key through `with()` has
no effect once it carries `#[Prop]`.

### Object-typed props never instantiate

```php
#[Prop] public ?\DateTimeImmutable $when = null;
```

A value from a token is accepted only if it is **already an instance** of the
declared class. A token naming a class name cannot cause the framework to
construct it.

### The props array (legacy, still supported)

Components without `#[Prop]` use the array API:

```php
$this->with('count', $this->intProp('count') + 1);
```

Readers: `prop()`, `intProp()`, `stringProp()`, `boolProp()`, `arrayProp()`,
plus `hasProp()`, `with()`, `merge()`. The two styles may be mixed across
components, but within one component a `#[Prop]` key wins.

## Actions

An action is a public method htmx may invoke. Declare it with a verb attribute:

```php
#[Get]     public function peek(): void {}     // → hx-get
#[Post]    public function create(): void {}   // → hx-post
#[Put]     public function replace(): void {}  // → hx-put
#[Patch]   public function amend(): void {}    // → hx-patch
#[Delete]  public function destroy(): void {}   // → hx-delete
```

`#[Action]` alone means POST. `#[Action(verb: Verb::Get)]` overrides it.

**The declared verb feeds both sides.** It names the generated `hx-*` attribute
*and* the method the router will accept, so the markup and the dispatcher cannot
disagree. A request with the wrong verb gets `405` and an `Allow` header.

The legacy form still works, and the two union:

```php
public function actions(): array { return ['increment', 'reset']; }
```

Actions that are refused, always:

- not declared by either mechanism;
- non-public;
- inherited from `Component` itself, so a careless `actions()` cannot expose
  `toHtml()` or `with()`.

## Wiring elements

Inside a component, `$this->hx` is an `ElementFactory` already wired to this
component's endpoints:

```php
$this->hx->button('Save')->action('save');              // verb from the action
$this->hx->button('Load')->action('load', ['method' => 'GET']);
$this->hx->input()->action('search', ['trigger' => 'input changed delay:250ms']);
```

`action()` fills in the endpoint URL, the default `hx-target` (your root
element's id) and `hx-swap="outerHTML"`. Explicit options always win.

Outside a component, the global `hx()` helper builds plain elements:

```php
echo hx()->button('Refresh')->hxGet('/fragment')->render();
```

## Lifecycle hooks

| Hook | When | Use |
|---|---|---|
| `mount()` | before the first render | seed initial state |
| `hydrate()` | after state loads, every request | normalise props |
| `preAction()` | before an action runs | extra guards |
| `postAction()` | after an action mutates state | side effects |
| `stateful()` | — | move props out of the token into a `StateStore` |

## Conveniences

```php
$this->validate(['title' => 'required|max:200']);   // returns an ErrorBag
$this->flash('success', 'Saved.');                 // for the next request
$this->outOfBand($this->hx->span()->text($count)); // swap outside the target
$this->lazy('load');                               // fetch after the page settles
$this->eventName('invalid');                       // namespaced DOM event name
```

`validate()` fires an `HX-Trigger-After-Swap` event carrying the messages **only
when validation fails** — an event on every successful action would be noise.

## Stateful components

By default a component keeps nothing on the server: props ride inside its signed
URL. For state that is large or sensitive, move it into a store:

```php
protected function stateful(): bool { return true; }
```

The token then stays a constant size and the props live in the `StateStore`
(`SessionState`, or your own implementation). The trade-off is that the component
is tied to one user's session.

## Composing components

```php
protected function renderComponent(string $class, array $props = []): Element|Raw
```

Builds another component with the same context, so a page can be assembled from
parts without the parent knowing their URLs.

## Further reading

- [SECURITY.md](../SECURITY.md) — the token model in full
- [../docs/recipes.md](recipes.md) — copy-pasteable how-tos
- [../SPEC.md](../SPEC.md) — planned work