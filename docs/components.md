# Components

> **Status: describes shipped code.** Verified against the current suite.

A component is a class. Its state is a typed property, its behaviour is a
method, and its `render()` returns an `Element`. Everything htmx-related is
generated.

## The smallest useful component

```php
namespace App\Components;

use Empira\Attributes\Post;
use Empira\Attributes\Prop;
use Empira\Component;
use Empira\Element;

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

Make it reachable — the application names which namespace is in scope, once:

```php
$app->components(__NAMESPACE__);
```

A token for a component with no alias carries its fully-qualified name, which is
longer in every action URL. Add an alias when that matters, or when the token
should survive the class moving namespaces:

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

### An action takes what it asks for

Parameters are resolved by type, exactly as a controller's are:

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

Without this, a component could render a form and then have nowhere to go with
it. An action that asks for nothing is unaffected, and one that asks for
something unresolvable is refused loudly rather than called with a missing
argument.

Note that **the return value is discarded.** An action responds by mutating its
props and the response — `with()` re-renders the swap, `response()->redirect()`
sets `HX-Redirect` — and that is what the swap is built from.

Autowiring does not widen what may be *invoked*: an undeclared action is refused
before any of this happens. A mangled action URL is still a 403.

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

**A nested component does not need registering.** This used to be the sharpest
trap in the framework: `renderComponent()` built the child directly, so nothing
looked wrong, but the token in its action URL named the class and was resolved
through the {@see \Empira\Registry} when the button was pressed. An
unregistered nested component rendered perfectly and then answered 404 — a
confusing way to find out, and paid for by applications that had to register
every component they ever nested. `$app->components()` removes the
registration; what it does not remove is the need for the class to be under the
allowed namespace.

## Styling

```php
protected function styles(): Raw
{
    return Raw::of('.login-form .field { margin-bottom: .9rem }');
}
```

Emitted as a `<style>` **inside the component's root element**, which is the swap
target. htmx replaces that element's `innerHTML` and nothing else, so a sheet in
`<head>` would be gone by the time the component is re-fetched on its own. Inside
the root it arrives with every swap, and it is prepended rather than appended so
it wins on order against the global sheet it is meant to override.

A structural component with no appearance of its own should return an empty
`Raw` — a form composes `FormField`s and owns their styling, and a `<style>` on
each field would repeat once per field per form.

## Aggregating a composite's styles

A composite emits **one** sheet for its whole subtree instead, when it says so:

```php
protected function aggregateStyles(): ?StyleOrder
{
    return StyleOrder::SelfLast;
}
```

Three things come out of that. Identical rulesheets are emitted once rather than
once per instance, so five children of a class cost one rule block. The composite
decides where its own rules sit among its children's, which positional insertion
never let it do. And the parent can style its children's internals as a unit.

**The order nobody chose.** A parent prepends its sheet to its own root while its
children's already sit inside them, so the parent's rules land *first* — and CSS
gives the last of two equal rules the win, which means **children win a tie**.
`StyleOrder::SelfFirst` preserves that, so opting in does not silently reorder an
application. `SelfLast` is for a parent whose purpose is to dress its children.

**The cost, and the promise.** A child swapped on its own would arrive unstyled,
because its rules have moved up to a root that was not swapped. Say so:

```php
protected function swapsIndependently(): bool
{
    return true;   // I keep my own sheet, even inside an aggregating parent
}
```

It then keeps its sheet *and* is left out of the parent's, so its rules appear
once and travel with every swap of it. The framework cannot check this — a
component that self-swaps and does not say so simply arrives unstyled, and
nothing reports it. That is why the default is the safe one.

Anything rendered on its own is unaffected. `lazy()` fetches through an action,
so that render pass has no aggregating parent, and the component emits its own
sheet — which is right, because anything fetched alone has to stand alone.

## Further reading

- [SECURITY.md](../SECURITY.md) — the token model in full
- [../docs/recipes.md](recipes.md) — copy-pasteable how-tos
- [SPEC.md](https://github.com/iam-adty/empira-dev/blob/main/planning/SPEC.md../) — planned work