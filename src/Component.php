<?php

declare(strict_types=1);

namespace Empira;

use Empira\Attributes\Verb;
use Empira\Container\Container;
use Empira\Http\Request;
use Empira\Http\Response;
use Empira\Http\Session;
use Empira\State\ActionMap;
use Empira\State\PropBag;
use Empira\Validation\ErrorBag;
use Empira\Validation\Validator;

/**
     * The base class for every piece of interactive UI.
     *
     * A component owns three things: its props, the actions htmx may invoke,
     * and a `render()` that returns HTML. Everything htmx-related — the action
     * URLs, the swap target, the payload round-trip, even the HTTP verb — is
     * generated for you, so `hx-*` attributes never appear in your source:
 *
 *     final class Counter extends Component
 *     {
 *         #[Prop] public int $count = 0;
 *
 *         #[Post]
 *         public function increment(): void
 *         {
 *             $this->count++;
 *         }
 *
 *         protected function render(): Element
 *         {
 *             return $this->hx->div('#counter')
 *                 ->append($this->hx->strong()->text((string) $this->count))
 *                 ->append($this->hx->button('+1')->action('increment'));
 *         }
 *     }
 *
 * Note the two `append()` calls and no `text()` on the parent. `Element::text()`
 * **replaces** every child with one text node, so ending a chain with it
 * discards whatever was appended before it — this example once did exactly
 * that, and rendered a counter with no button at all. Every other copy in this
 * package got it right, so nothing caught it: a docblock is prose until
 * something renders it. {@see Console\Commands\MakeComponent} is the
 * executable version, and `ConsoleTest` renders what it writes rather than
 * matching its text.
 *
 * ## Actions
 *
 * An action is a public method marked with a verb attribute — `#[Get]`,
 * `#[Post]`, `#[Put]`, `#[Patch]`, `#[Delete]` — or with plain `#[Action]`,
 * which means POST. The verb decides which `hx-*` attribute is generated *and*
 * which HTTP method the router will accept, so the markup and the dispatcher
 * can never disagree.
 *
 * Listing names in `actions()` is still supported and equivalent to marking
 * each method `#[Action]`. Mixing the two is fine: the map is the union, and an
 * attribute wins for any action declared both ways.
 *
 * Every action has exactly one verb, and the router refuses a request that
 * arrives with another (`405`, with an `Allow` header). A verb attribute is the
 * declaration a component should use; `actions()` entries default to POST.
 *
 * ## State
 *
 * By default a component is stateless: its props are signed into the action
 * URL and travel with every request. The value above comes back from the
 * client on the next click, verified against the HMAC, so tampering with it
 * fails the signature check. Override {@see stateful()} to move props into a
 * {@see State\StateStore} instead — useful when they are large, sensitive, or
 * must not be readable from a URL.
 *
 * Props must be JSON-serialisable.
 */
abstract class Component
{
    /** @var array<string, mixed> */
    protected array $props = [];

    protected Request $request;
    protected ElementFactory $hx;

    private string $nonce;

    /**
     * What this component composed, in order.
     *
     * Held so a parent can ask; nothing in the framework reads it. It is the
     * tree that {@see child()} builds, made inspectable rather than flattened
     * into markup at the point of composition.
     *
     * @var list<Component>
     */
    private array $children = [];

    /**
     * An aggregating ancestor owns this component's rules, so it emits no sheet.
     *
     * Set by {@see child()} on the instance, after `prepare()` and before
     * anything renders — the only window where it can still be true.
     */
    private bool $stylesHoisted = false;

    /** True when this component collects its children's styles. */
    private bool $aggregating = false;

    /** True when its own rules should follow its children's. */
    private bool $selfLastStyles = false;

    /** The id that default `hx-target` attributes aim at. */
    private string $targetId;

    /**
     * Everything a component needs to exist, and the wiring that makes its
     * elements resolve.
     *
     * The element factory's defaults are **closures rather than strings**,
     * because two of the three cannot be known yet: a component may name its own
     * root element, and that id only exists once `render()` has produced it.
     * Resolving them eagerly would mean every component declared a target it did
     * not control, and `hx-target` would point at the wrong element for anyone
     * who overrode `id()`.
     */
    final public function __construct(
        protected readonly Context $context,
        array $props = [],
        ?string $nonce = null,
    ) {
        $this->props = $props;
        $this->nonce = $nonce ?? bin2hex(random_bytes(8));
        $this->targetId = $this->id();
        $this->request = $context->request;

        $this->hx = (new ElementFactory())->withResolver(
            fn(string $action): string => $this->endpoint($action),
            [
                'swap' => 'outerHTML',
                'target' => fn(): string => '#' . $this->targetId,
                'method' => fn(string $action): string => $this->verbFor($action)->value,
            ],
        );
    }

    /**
     * Build the component's HTML.
     *
     * Return an {@see Element}, a string of HTML, or a mix via the element's
     * `append()`.
     */
    abstract protected function render(): Element|Raw|string;

    /**
     * Hook run once, the first time the component is rendered on a page.
     *
     * Use it to seed props before the first render.
     */
    protected function mount(): void
    {
    }

    /**
     * Render the component to markup that can be composed into other elements.
     *
     * Returning an `Element` rather than a string matters: a string handed to
     * `append()` would be escaped, quietly turning a component's markup into
     * visible angle brackets in the middle of the page.
     */
    final public function markup(): Element|Raw
    {
        // Before `render()`, which is what creates the children whose hoisting
        // depends on this decision.
        $this->prepareForRender();

        $rendered = $this->render();

        if ($rendered instanceof Element) {
            // A component may name its own root element; adopt that id as the
            // default swap target. Failing that, mint one, so the generated
            // `hx-target` attributes always point at something that exists.
            $this->targetId = $rendered->getId() ?? $this->id();

            if ($rendered->getId() === null) {
                $rendered->id($this->targetId);
            }

            $style = $this->aggregating ? $this->collectedStyleElement() : $this->ownStyleElement();

            if ($style !== null) {
                $rendered->prepend($style);
            }

            return $rendered;
        }

        // A component that renders raw markup rather than an element still gets
        // its styling, prefixed as text — there is no element to prepend to.
        $style = $this->aggregating ? $this->collectedStyleElement() : $this->ownStyleElement();
        $css = $style instanceof Raw ? $style->get() : ($style?->render() ?? '');

        if ($rendered instanceof Raw) {
            return $css === '' ? $rendered : Raw::of($css . $rendered->get());
        }

        return Raw::of($css . $rendered);
    }

    /**
     * This component's own `<style>`, or null when it has none **or** an
     * aggregating ancestor owns its rules.
     */
    private function ownStyleElement(): Element|Raw|null
    {
        return $this->stylesHoisted ? null : $this->styleElement();
    }

    /**
     * One `<style>` for this component's whole subtree.
     *
     * Walks the component tree rather than the markup: {@see Element} has no
     * children accessor, and the instances are here anyway. Only children that
     * were hoisted count — one that {@see swapsIndependually()} kept its own
     * sheet, and collecting it as well would put the same rules on the page twice.
     */
    private function collectedStyleElement(): Element|Raw|null
    {
        $parts = [];

        $own = $this->styles();
        $own = $own instanceof Element ? $own->render() : $own->get();

        if ($own !== '') {
            $parts[] = $own;
        }

        foreach ($this->children as $child) {
            if ($child->stylesHoisted) {
                $parts[] = $child->contributeStyles();
            }
        }

        // Identical rules, once. Five children of one class are one rule block,
        // which is the whole reason a composite bothers to collect.
        $parts = array_values(array_unique(array_filter($parts, static fn(string $s): bool => trim($s) !== '')));

        if ($this->selfLastStyles) {
            $parts = array_reverse($parts);
        }

        $css = implode("\n", $parts);

        return $css === '' ? null : $this->hx->style()->raw($css);
    }

    /**
     * This component's stylesheet and its hoisted descendants', as CSS.
     *
     * Recurses so a grandchild is reached without the root knowing how deep the
     * tree goes.
     *
     * @internal read by an aggregating parent
     */
    protected function contributeStyles(): string
    {
        $parts = [];
        $own = $this->styles();
        $own = $own instanceof Element ? $own->render() : $own->get();

        if ($own !== '') {
            $parts[] = $own;
        }

        foreach ($this->children as $child) {
            if ($child->stylesHoisted) {
                $parts[] = $child->contributeStyles();
            }
        }

        return implode("\n", $parts);
    }

/**
 * Collect this component's stylesheet and its children's into one sheet at this
 * component's root. Returning null — the default — and nothing changes.
 *
 * Every component carries its own sheet inside its own root element, because the
 * root is the swap target and a sheet in `<head>` does not travel with a swap.
 * That is right for a component that is swapped on its own, and repetitive for
 * one composed of others: five children of a class would emit five identical
 * `<style>` blocks, and the parent would have no say in the order they landed
 * in.
 *
 * Opting in says the composite is the swap unit — its whole subtree arrives and
 * leaves together — so the CSS can be gathered at the top:
 *
 *     protected function aggregateStyles(): ?StyleOrder
 *     {
 *         return StyleOrder::SelfLast;
 *     }
 *
 * Identical rulesheets are emitted once, not once per instance.
 *
 * **The order you get today was never chosen.** A parent prepends its sheet to
 * its own root, its children's sheets already sit inside the children, so the
 * parent's rules land *first* — and CSS gives the last of two equal rules the
 * win, which means children win. `SelfFirst` preserves that, so opting in does
 * not silently reorder an application; `SelfLast` is for a parent whose purpose
 * is to dress its children.
 *
 * **The promise.** A child swapped on its own would arrive unstyled, because its
 * rules have moved up to a root that was not swapped. Declare
 * {@see swapsIndependently()} on such a child; it keeps its own sheet and is not
 * collected.
 *
 * Anything rendered on its own is unaffected. `lazy()` fetches through an action,
 * so that render pass has no aggregating parent and the component emits its own
 * sheet — which is right, because anything fetched alone has to stand alone.
 */
protected function aggregateStyles(): ?StyleOrder
{
    return null;
}

/**
 * I am swapped on my own, so my stylesheet stays inside me.
 *
 * False by default, and only meaningful inside a component that answers `true`
 * to {@see aggregateStyles()}: it then keeps its own sheet *and* is left out of
 * the parent's, so the rules appear once and travel with every swap of this
 * component.
 *
 * The framework cannot check this. A component that self-swaps and does not say
 * so gets unstyled markup after the swap, and nothing reports it — which is why
 * the default is the safe one and the declaration is the author's promise.
 */
protected function swapsIndependently(): bool
{
    return false;
}

/**
 * This component's own styling, emitted inside its root element.
 *
 * The alternative is one stylesheet in `<head>` shared by every component,
 * which makes the sheet a list of everyone's private rules and means a
 * component cannot be lifted out with its appearance intact.
 *
 * Inside the root element is the point. The root element is the swap
 * target, so the `<style>` arrives again with every swap htmx performs —
     * put it in `<head>` and a component re-fetched on its own would arrive
     * unstyled. It is prepended rather than appended so it wins on order
     * against a global sheet it is meant to override — and, with
     * {@see aggregateStyles()} returning `null`, a child's sheet also wins on
     * order against its parent's, which is what this has always done.
     *
     * An aggregating composite emits one sheet for the whole subtree instead;
     * {@see StyleOrder} covers where its own rules go among its children's.
     *
     *     protected function styles(): Raw
     *     {
     *         return Raw::of('.counter { display: flex }');
     *     }
     */
    protected function styles(): Raw
    {
        return Raw::of('');
    }

    /**
     * The `<style>` element for {@see Component::styles()}, or null when the
     * component has none.
     *
     * An {@see Element} return from `styles()` is taken as-is, so a component
     * can emit its own tag the way a layout can.
     */
    private function styleElement(): Element|Raw|null
    {
        $styles = $this->styles();

        if ($styles instanceof Element) {
            return $styles;
        }

        $css = $styles->get();

        return $css === '' ? null : $this->hx->style()->raw($css);
    }

    /**
     * Render the component and return its HTML.
     */
    final public function toHtml(): string
    {
        $markup = $this->markup();

        return $markup instanceof Element ? $markup->render() : $markup->get();
    }

    /**
     * Prepare a freshly created instance and return its markup. This is the
     * entry point for a component's *first* appearance on a page.
     */
    final public function initialise(array $props = []): Element|Raw
    {
        $this->prepare($props);

        return $this->markup();
    }

    /**
     * Everything a component needs before its first render, without rendering it.
     *
     * Split out of {@see initialise()} so {@see child()} can hand back a live
     * component to compose with, rather than a string of already-rendered
     * markup. A parent that wants to look at a child before deciding what to do
     * with it now can.
     *
     * @internal called by `initialise()` and `child()`
     */
    final public function prepare(array $props = []): void
    {
        $this->props = $props;

        if ($this->stateful()) {
            $this->context->state->save($this->stateKey(), $props);
        }

        $this->boot();
        $this->mount();

        // Save again so state seeded by `mount()` survives to the next request.
        if ($this->stateful()) {
            $this->context->state->save($this->stateKey(), $this->exportProps());
        }
    }

    /**
     * The props this component is currently in, with `#[Prop]` properties
     * written back over their array entries.
     *
     * This is what gets signed into an action URL and what gets saved to the
     * state store, so a component that mutates a typed property and a component
     * that mutates the props array travel the same path.
     *
     * @return array<string, mixed>
     */
    private function exportProps(): array
    {
        return PropBag::flush($this, $this->props);
    }

    /**
     * Load any server-side state. Called after construction and before the
     * first render, and again before every action.
     */
    final public function boot(): void
    {
        if ($this->stateful()) {
            $this->props = $this->context->state->load($this->stateKey());
        }

        // A `#[Prop]` property is the source of truth for its own key, so give
        // it its value before any user code runs.
        PropBag::hydrate($this, $this->props);

        $this->hydrate();
    }

    /**
     * Override to seed or normalise props after state has been loaded.
     */
    protected function hydrate(): void
    {
    }

    /**
     * The action names htmx is allowed to invoke on this component.
     *
     * Anything not returned here is unreachable — the router rejects it before
     * the method is called.
     *
     * @return list<string>
     */
    public function actions(): array
    {
        return [];
    }

    /**
     * Every reachable action, as action name => HTTP verb.
     *
     * Union of the `actions()` list (POST) and any `#[Action]` methods, so a
     * component can migrate one action at a time.
     *
     * @return array<string, Verb>
     */
    final public function actionMap(): array
    {
        return ActionMap::of(static::class);
    }

    /**
     * The verb an action must be reached with, defaulting to POST.
     */
    final public function verbFor(string $action): Verb
    {
        return $this->actionMap()[$action] ?? Verb::Post;
    }

    /**
     * Run before an action. The default verifies CSRF when a guard is present.
     */
    final public function beforeAction(string $action): void
    {
        $this->context->csrf?->verify($this->request);

        $this->preAction($action);
    }

    protected function preAction(string $action): void
    {
    }

    /**
     * Run after an action has mutated props.
     */
    final public function afterAction(string $action): void
    {
        if ($this->stateful()) {
            $this->context->state->save($this->stateKey(), $this->exportProps());
        }

        $this->postAction($action);
    }

    protected function postAction(string $action): void
    {
    }

    /**
     * Queue a message for the next request.
     *
     * A flash is the natural companion to htmx: an action sets one, and the
     * layout renders it in a region that swaps in with the response.
     */
    protected function flash(string $key, mixed $value): void
    {
        $this->container()->get(Session::class)->flash($key, $value);
    }

    /**
     * Validate this request's input against a set of rules.
     *
     * Returns the error bag so a component can decide what to do — but it also
     * fires an `hx-invalid` event carrying the messages, so a form can show
     * them without the component knowing anything about the page around it.
     *
     *     if ($this->validate(['title' => 'required|max:200'])->any()) {
     *         return;   // the component re-renders, now showing the errors
     *     }
     *
     * A failure also queues an `invalid` event carrying the errors, so a client
     * can mark the offending fields. **Only a failure does** — an event on every
     * successful action would be noise, and a client listening for one would have
     * to filter anyway.
     *
     * @param array<string, string|list<string>> $rules
     */
    protected function validate(array $rules, ?array $data = null): ErrorBag
    {
        $errors = Validator::make($data ?? $this->request->all(), $rules);

        if ($errors->any()) {
            // The modified copy has to be kept — see {@see updateResponse()}.
            $this->updateResponse($this->response()->triggerAfterSwap(
                $this->eventName('invalid'),
                $errors->jsonSerialize(),
            ));
        }

        return $errors;
    }

    /**
     * Mark part of this response as an out-of-band swap.
     *
     * OOB is how one action updates two places at once: the swapped region and
     * a counter, a badge, anything else on the page.
     */
    protected function outOfBand(Element|Raw $content, string|bool $swap = true): Element|Raw
    {
        return $content instanceof Element
            ? $content->attr('hx-swap-oob', $swap === true ? 'true' : $swap)
            : Raw::of(sprintf('<div hx-swap-oob="%s">%s</div>', $swap === true ? 'true' : Html::escape((string) $swap), $content->get()));
    }

    /**
     * Fetch this component's contents once the page has settled.
     *
     * Use it for something slow that nobody is waiting on, so the page it sits
     * in arrives immediately.
     */
    protected function lazy(string $action, string $trigger = 'load'): Element
    {
        return $this->hx->div()
            ->id($this->id())
            ->attr('hx-get', $this->endpoint($action))
            ->attr('hx-trigger', $trigger);
    }

    /**
     * The DOM event name this component fires for `$event`.
     */
    final public function eventName(string $event): string
    {
        return $this->id() . ':' . $event;
    }

    /**
     * The application's container, so a component can reach a service.
     *
     * Protected rather than private because an action receives what it needs
     * by type, but `render()` is called internally and has no arguments — so
     * this is how a component reads `Auth`, a repository or configuration
     * while it is rendering. Without it, everything a component can depend on
     * has to be passed in as a prop, which is not a prop's job.
     *
     * Resolving through the container never widens what may be *invoked*: the
     * router refuses an undeclared action before it calls one. This only
     * decides what a declared action is handed.
     */
    protected function container(): Container
    {
        return $this->context->container ?? new Container();
    }

    /**
     * The component's DOM id, which is also its default `hx-target`.
     */
    public function id(): string
    {
        return 'e-' . substr(hash('sha256', static::class . '|' . $this->nonce), 0, 12);
    }

    public function nonce(): string
    {
        return $this->nonce;
    }

    /**
     * Keep props in the state store instead of inside the token.
     */
    protected function stateful(): bool
    {
        return false;
    }

    protected function stateKey(): string
    {
        return static::class . ':' . $this->nonce;
    }

    /**
     * The signed, URL-safe identity of this component instance.
     */
    protected function token(): string
    {
        return $this->context->tokens->encode(
            $this->context->registry->nameFor(static::class),
            $this->nonce,
            $this->stateful() ? [] : $this->exportProps(),
        );
    }

    /**
     * The URL for one of this component's actions.
     */
    protected function endpoint(string $action): string
    {
        return rtrim($this->context->baseUrl, '/')
            . '/' . rawurlencode($this->token())
            . '/' . rawurlencode($action);
    }

    protected function hasProp(string $key): bool
    {
        return array_key_exists($key, $this->props);
    }

    protected function prop(string $key, mixed $default = null): mixed
    {
        return $this->props[$key] ?? $default;
    }

    protected function intProp(string $key, int $default = 0): int
    {
        $value = $this->prop($key, $default);

        return is_numeric($value) ? (int) $value : $default;
    }

    protected function stringProp(string $key, string $default = ''): string
    {
        $value = $this->prop($key, $default);

        return is_scalar($value) ? (string) $value : $default;
    }

    protected function boolProp(string $key, bool $default = false): bool
    {
        $value = $this->prop($key, $default);

        return is_bool($value) ? $value : filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? $default;
    }

    /**
     * @return array<array-key, mixed>
     */
    protected function arrayProp(string $key, array $default = []): array
    {
        $value = $this->prop($key, $default);

        return is_array($value) ? $value : $default;
    }

    /**
     * Set a prop and return `$this`.
     */
    protected function with(string $key, mixed $value): static
    {
        $this->props[$key] = $value;

        return $this;
    }

    /**
     * @param array<string, mixed> $props
     */
    protected function merge(array $props): static
    {
        $this->props = array_merge($this->props, $props);

        return $this;
    }

    protected function request(): Request
    {
        return $this->request;
    }

    protected function response(): Response
    {
        return $this->context->response;
    }

    /**
     * Keep the result of an immutable change to the response.
     *
     * `Response` is immutable, so `pushUrl()`, `triggerAfterSwap()` and the rest
     * return a **modified copy** and leave the one they were called on alone.
     * An action that queues a header therefore has to put the copy back, or the
     * header is silently dropped:
     *
     *     $this->updateResponse($this->response()->pushUrl('/posts/1'));
     *
     * It goes on the context rather than on the component, because the router
     * returns `$context->response` and a second copy held here would be a second
     * answer to the same question — and the two would disagree the moment a
     * component and its context disagreed about a header.
     */
    protected function updateResponse(Response $response): Response
    {
        return $this->context->response = $response;
    }

    /**
     * Forget this component's server-side state.
     */
    protected function forgetState(): void
    {
        $this->context->state->forget($this->stateKey());
    }

/**
 * Compose another component and get the component back.
 *
 * The Composite idea, taken seriously: a component containing components and a
 * component that does not are the same kind of thing, and the framework does
 * not care which is which. There is one type, one render path, one token shape,
 * one set of rules.
 *
 *     $logout = $this->child(LogoutButton::class);
 *
 *     return $this->hx->nav()->append($logout->markup());
 *
 * What comes back is a live component, so a parent can look at it — its props,
 * whether it rendered anything, what it composed itself — before deciding where
 * it goes. {@see children()} then reports everything this component composed,
 * for the cases where a parent needs the whole set rather than one at a time.
 *
 * Two things make this work that used to be the parent's problem. The child's
 * action URLs carry its own class name, which now resolves through
 * {@see Application::components()} — so a nested component answers its own
 * button instead of rendering fine and then 404-ing when pressed, which is what
 * registering every nested component by hand existed to prevent. And its
 * stylesheet rides inside its own root element, which is the swap target, so
 * composition brings its styles with it.
 *
 * Props follow the same path they do anywhere else, so a child is given them
 * the way the router would give them.
 *
 * @param class-string<Component> $class
 */
protected function child(string $class, array $props = []): Component
{
    /** @var Component $instance */
    $instance = new $class($this->context, $props);

    $instance->prepare($props);

    // Decided after `prepare()` and before anything renders, which is the only
    // window where it can still be true. A child that self-swaps keeps its own
    // sheet *and* is left out of ours, so its rules appear once and travel with
    // every swap of it.
    if ($this->aggregating && !$instance->swapsIndependently()) {
        $instance->stylesHoisted = true;
    }

    $this->children[] = $instance;

    return $instance;
}

/**
 * Run `render()`, having first decided whether this component's stylesheet is
 * its own to emit or its subtree's to collect.
 *
 * Separate from {@see markup()} so the decision is made once per render, before
 * any child exists — a child's hoisting depends on it, and `render()` is what
 * creates the children.
 *
 * @internal called by `markup()`
 */
final public function prepareForRender(): void
{
    // One call. A user's method need not be idempotent, and reading two flags
    // should not mean asking twice.
    $order = $this->aggregateStyles();

    $this->aggregating = $order !== null;
    $this->selfLastStyles = $order === StyleOrder::SelfLast;
}

/**
 * Every component this one composed, in the order it composed them.
 *
 * Only direct children — ask a child for its own to walk further down, which is
 * what keeps a tree from having to be flattened into something it is not.
 *
 * @return list<Component>
 */
protected function children(): array
{
    return $this->children;
}

/**
 * Render a second component from inside this one — handy for composing
 * a page out of smaller components without any parent knowing their URLs.
 *
 * Shorthand for `child($class, $props)->markup()`. Prefer {@see child()} when
 * you want the instance rather than the markup.
 *
 * @param class-string<Component> $class
 */
protected function renderComponent(string $class, array $props = []): Element|Raw
{
    return $this->child($class, $props)->markup();
}
}