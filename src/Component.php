<?php

declare(strict_types=1);

namespace IamAdty\Empira;

use IamAdty\Empira\Attributes\Verb;
use IamAdty\Empira\Container\Container;
use IamAdty\Empira\Http\Request;
use IamAdty\Empira\Http\Response;
use IamAdty\Empira\Http\Session;
use IamAdty\Empira\State\ActionMap;
use IamAdty\Empira\State\PropBag;
use IamAdty\Empira\Validation\ErrorBag;
use IamAdty\Empira\Validation\Validator;

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
 *                 ->append($this->hx->button('+1')->action('increment'))
 *                 ->text((string) $this->count);
 *         }
 *     }
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
    protected Response $response;
    protected ElementFactory $hx;

    private string $nonce;

    /** The id that default `hx-target` attributes aim at. */
    private string $targetId;

    final public function __construct(
        protected readonly Context $context,
        array $props = [],
        ?string $nonce = null,
    ) {
        $this->props = $props;
        $this->nonce = $nonce ?? bin2hex(random_bytes(8));
        $this->targetId = $this->id();
        $this->request = $context->request;
        $this->response = $context->response;

        // Every element built by this component can resolve `->action('name')`
        // into a signed endpoint. The default target is a closure because a
        // component may name its own root element, and that is only known once
        // `render()` has produced it.
        $this->hx = (new ElementFactory())->withResolver(
            fn(string $action): string => $this->endpoint($action),
            [
                'swap' => 'outerHTML',
                'target' => fn(): string => '#' . $this->targetId,
                'method' => fn(string $action): string => $this->verbFor($action)->value,
            ],
        );
    }

    // ---------------------------------------------------------------- rendering

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
        $rendered = $this->render();

        if ($rendered instanceof Element) {
            // A component may name its own root element; adopt that id as the
            // default swap target. Failing that, mint one, so the generated
            // `hx-target` attributes always point at something that exists.
            $this->targetId = $rendered->getId() ?? $this->id();

            if ($rendered->getId() === null) {
                $rendered->id($this->targetId);
            }

            return $rendered;
        }

        return $rendered instanceof Raw ? $rendered : Raw::of($rendered);
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

        return $this->markup();
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

    // ---------------------------------------------------------------- lifecycle

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

    // ---------------------------------------------------------------- conveniences

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
     * @param array<string, string|list<string>> $rules
     */
    protected function validate(array $rules, ?array $data = null): ErrorBag
    {
        $errors = Validator::make($data ?? $this->request->all(), $rules);

        // Only announce a failure: an event on every successful action would be
        // noise, and a client that listens for it would have to filter anyway.
        if ($errors->any()) {
            $this->response()->triggerAfterSwap($this->eventName('invalid'), $errors->jsonSerialize());
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

    // ---------------------------------------------------------------- identity

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

    // ---------------------------------------------------------------- props

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

    // ---------------------------------------------------------------- context

    protected function request(): Request
    {
        return $this->request;
    }

    protected function response(): Response
    {
        return $this->response;
    }

    /**
     * Forget this component's server-side state.
     */
    protected function forgetState(): void
    {
        $this->context->state->forget($this->stateKey());
    }

    /**
     * Render a second component from inside this one — handy for composing
     * a page out of smaller components without any parent knowing their URLs.
     */
    protected function renderComponent(string $class, array $props = []): Element|Raw
    {
        $instance = new $class($this->context, $props);

        return $instance->initialise($props);
    }
}