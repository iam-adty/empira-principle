<?php

declare(strict_types=1);

namespace Empira;

use Closure;

/**
 * A fluent HTML tag builder.
 *
 * Elements are mutable builders — every method returns `$this`. Children given
 * as plain strings are escaped and rendered as text nodes; wrap a string in
 * {@see Raw::of()} to emit it unescaped.
 *
 * The htmx half of the API has two entry points:
 *
 *   - `hxGet()`/`hxPost()`/... take a literal URL;
 *   - `action()` takes a component *action name* and asks the element's
 *     resolver for the URL. Inside a component every element already has a
 *     resolver wired up, so `->action('save')` is enough.
 */
final class Element implements \Stringable
{
    private const VOID_TAGS = [
        'area', 'base', 'br', 'col', 'embed', 'hr', 'img',
        'input', 'link', 'meta', 'param', 'source', 'track', 'wbr',
    ];

    private string $tag;
    private array $attrs = [];
    private array $children = [];
    private ?string $id = null;
    private ?Closure $resolver = null;
    private array $actionDefaults = [];

    /** Attribute names the caller set by hand, as opposed to action defaults. */
    private array $explicit = [];

    public function __construct(string $tag, mixed ...$children)
    {
        $this->tag = self::normalizeTag($tag);

        foreach ($children as $child) {
            // A leading '#foo' argument is shorthand for the id attribute.
            if ($this->id === null && $this->children === [] && self::looksLikeId($child)) {
                $this->id = substr($child, 1);
                continue;
            }

            $this->appendChild($child);
        }
    }

    public function append(Element|Raw|string|int|float|bool|null ...$children): self
    {
        foreach ($children as $child) {
            $this->appendChild($child);
        }

        return $this;
    }

    /**
     * Replace all children with a single escaped text node.
     */
    public function text(mixed $text): self
    {
        $this->children = [Html::escape($text)];

        return $this;
    }

    /**
     * Append an unescaped HTML string.
     */
    public function raw(string $html): self
    {
        return $this->append(Raw::of($html));
    }

    public function prepend(Element|Raw|string|int|float|bool|null ...$children): self
    {
        foreach (array_reverse($children) as $child) {
            if ($child === null || $child === false) {
                continue;
            }

            array_unshift($this->children, $child instanceof Element || $child instanceof Raw ? $child : Html::escape($child));
        }

        return $this;
    }

    public function attr(string $name, mixed $value = true): self
    {
        return $this->attrs([$name => $value]);
    }

    public function attrs(array $attrs): self
    {
        foreach ($attrs as $name => $value) {
            $name = Html::attrName((string) $name);
            $this->explicit[$name] = true;

            if ($value === false || $value === null) {
                unset($this->attrs[$name]);
                continue;
            }

            $this->attrs[$name] = $value;
        }

        return $this;
    }

    public function removeAttr(string $name): self
    {
        $this->explicit[$name] = true;
        unset($this->attrs[$name]);

        return $this;
    }

    public function id(string $id): self
    {
        $this->id = $id;

        return $this->attr('id', $id);
    }

    /**
     * Append to the class list rather than replacing it.
     */
    public function class(string ...$classes): self
    {
        $classes = array_values(array_filter($classes, static fn(string $c): bool => $c !== ''));

        if ($classes === []) {
            return $this;
        }

        $existing = isset($this->attrs['class']) ? Html::stringify($this->attrs['class']) : '';

        return $this->attr('class', trim($existing . ' ' . implode(' ', $classes)));
    }

    public function style(string|array $style): self
    {
        if (is_array($style)) {
            $style = implode(';', array_map(
                static fn($value, $key): string => $key . ':' . Html::stringify($value),
                $style,
                array_keys($style)
            ));
        }

        return $this->attr('style', $style);
    }

    public function data(string $key, mixed $value): self
    {
        return $this->attr('data-' . $key, $value);
    }

    public function hxGet(string $url): self
    {
        return $this->attr('hx-get', $url);
    }

    public function hxPost(string $url): self
    {
        return $this->attr('hx-post', $url);
    }

    public function hxPut(string $url): self
    {
        return $this->attr('hx-put', $url);
    }

    public function hxPatch(string $url): self
    {
        return $this->attr('hx-patch', $url);
    }

    public function hxDelete(string $url): self
    {
        return $this->attr('hx-delete', $url);
    }

    public function hxTarget(string $selector, ?string $swap = null): self
    {
        $this->attr('hx-target', $selector);

        return $swap === null ? $this : $this->hxSwap($swap);
    }

    public function hxSwap(string $strategy): self
    {
        return $this->attr('hx-swap', $strategy);
    }

    public function hxTrigger(string $trigger): self
    {
        return $this->attr('hx-trigger', $trigger);
    }

    /** Shorthand for `hx-trigger="<event> from:<selector>"`. */
    public function hxOn(string $event, string $from = 'body'): self
    {
        return $this->attr('hx-trigger', $from === '' ? $event : $event . ' from:' . $from);
    }

    public function hxTargetSelf(string $swap = 'outerHTML'): self
    {
        return $this->hxTarget('this', $swap);
    }

    public function hxVals(array|string|null $values): self
    {
        return $this->attr('hx-vals', is_array($values) ? Html::json($values) : $values);
    }

    public function hxHeaders(array|string|null $headers): self
    {
        return $this->attr('hx-headers', is_array($headers) ? Html::json($headers) : $headers);
    }

    public function hxInclude(string $selector): self
    {
        return $this->attr('hx-include', $selector);
    }

    public function hxExclude(string $selector): self
    {
        return $this->attr('hx-exclude', $selector);
    }

    public function hxParams(string $params): self
    {
        return $this->attr('hx-params', $params);
    }

    public function hxSync(string $sync): self
    {
        return $this->attr('hx-sync', $sync);
    }

    public function hxIndicator(string|bool $indicator): self
    {
        return $this->attr('hx-indicator', $indicator);
    }

    public function hxDisabled(bool|array|string $disabled = true): self
    {
        if (is_array($disabled)) {
            return $this->attr('hx-disabled-elt', $disabled);
        }

        return $this->attr('hx-disabled', $disabled);
    }

    public function hxDisableElement(string $selector): self
    {
        return $this->attr('hx-disabled-elt', $selector);
    }

    public function hxConfirm(string $question): self
    {
        return $this->attr('hx-confirm', $question);
    }

    public function hxPrompt(string $prompt): self
    {
        return $this->attr('hx-prompt', $prompt);
    }

    public function hxPushUrl(bool|string $url = true): self
    {
        return $this->attr('hx-push-url', $url);
    }

    public function hxReplaceUrl(bool|string $url = true): self
    {
        return $this->attr('hx-replace-url', $url);
    }

    public function hxHistory(bool|string $history = true): self
    {
        return $this->attr('hx-history', $history);
    }

    public function hxPreserve(bool $preserve = true): self
    {
        return $this->attr('hx-preserve', $preserve);
    }

    public function hxExt(string $extensions): self
    {
        return $this->attr('hx-ext', $extensions);
    }

    public function hxBoost(bool|string $selector = true): self
    {
        return $this->attr('hx-boost', $selector);
    }

    /**
     * Mark this element for an out-of-band swap.
     */
    public function hxSwapOob(bool|string $swap = true): self
    {
        return $this->attr('hx-swap-oob', $swap);
    }

    public function withResolver(?Closure $resolver): self
    {
        $this->resolver = $resolver;

        return $this;
    }

    /**
     * Options merged underneath every `action()` call on this element.
     */
    public function withActionDefaults(array $defaults): self
    {
        $this->actionDefaults = $defaults;

        return $this;
    }

    /**
     * Point this element at a component action.
     *
     * Options use htmx attribute names in camelCase (`swap`, `target`,
     * `trigger`, `confirm`, ...); `method` selects the verb and defaults to
     * POST. The verb itself may be a Closure — a component supplies one that
     * reads the action's `#[Get]`/`#[Delete]`/… verb — so it is resolved last,
     * alongside `target`, at render time. Anything the element already declares
     * wins over the defaults.
     */
    public function action(string $action, array $options = []): self
    {
        $defaults = $this->actionDefaults;

        // An explicit `method` option wins over the component's default
        // selector, which receives the action name and returns a verb.
        $method = $options['method'] ?? ($defaults['method'] ?? 'POST');

        if ($method instanceof Closure) {
            $method = $method($action);
        }

        $method = strtoupper(Html::stringify($method));

        if (preg_match('/^[A-Z]+$/', $method) !== 1) {
            throw new \InvalidArgumentException(sprintf('Illegal HTTP method "%s".', $method));
        }

        unset($options['method'], $defaults['method']);

        $url = $this->resolver !== null ? ($this->resolver)($action) : $action;

        $this->attrs['hx-' . strtolower($method)] = $url;

        foreach (array_merge($defaults, $options) as $name => $value) {
            $attr = 'hx-' . self::dasherize((string) $name);

            if ($value === false || $value === null) {
                unset($this->attrs[$attr]);
                continue;
            }

            // A Closure is resolved as late as possible — see render().
            $this->attrs[$attr] = $value;
        }

        return $this;
    }

    /**
     * Shortcut for a GET-flavoured action.
     */
    public function get(string $action, array $options = []): self
    {
        return $this->action($action, ['method' => 'GET'] + $options);
    }

    public function getTag(): string
    {
        return $this->tag;
    }

    public function getId(): ?string
    {
        return $this->id;
    }

    public function getAttr(string $name): mixed
    {
        return $this->attrs[$name] ?? null;
    }

    public function getAttrs(): array
    {
        return $this->attrs;
    }

    public function getChildren(): array
    {
        return $this->children;
    }

    public function isVoid(): bool
    {
        return in_array($this->tag, self::VOID_TAGS, true);
    }

    public function render(): string
    {
        $attrs = $this->attrs;

        if ($this->id !== null && !isset($attrs['id'])) {
            $attrs = ['id' => $this->id] + $attrs;
        }

        // Defaults may be closures — a component's own target id is only known
        // once its root element has been built, so resolve them last.
        foreach ($attrs as $name => $value) {
            if ($value instanceof Closure) {
                $attrs[$name] = Html::stringify($value());
            }
        }

        // A wired element that carries its own id aims its default target at
        // itself, which is what htmx would do anyway.
        if (
            isset($attrs['id'])
            && !isset($this->explicit['hx-target'])
            && $this->isWired()
        ) {
            $attrs['hx-target'] = '#' . $attrs['id'];
        }

        $open = '<' . $this->tag . Html::attrs($attrs) . '>';

        if ($this->isVoid()) {
            return $open;
        }

        return $open . $this->renderChildren() . '</' . $this->tag . '>';
    }

    /**
     * Does this element issue a request of its own accord?
     */
    private function isWired(): bool
    {
        foreach (['hx-get', 'hx-post', 'hx-put', 'hx-patch', 'hx-delete'] as $verb) {
            if (isset($this->attrs[$verb])) {
                return true;
            }
        }

        return false;
    }

    public function __toString(): string
    {
        return $this->render();
    }

    private function renderChildren(): string
    {
        $out = '';

        foreach ($this->children as $child) {
            $out .= $child instanceof Element || $child instanceof Raw
                ? (string) $child
                : $child;
        }

        return $out;
    }

    private function appendChild(mixed $child): void
    {
        if ($child === null || $child === false) {
            return;
        }

        if (is_array($child)) {
            foreach ($child as $nested) {
                $this->appendChild($nested);
            }

            return;
        }

        if ($this->isVoid()) {
            return;
        }

        $this->children[] = $child instanceof Element || $child instanceof Raw
            ? $child
            : Html::escape($child);
    }

    private static function normalizeTag(string $tag): string
    {
        $tag = strtolower(trim($tag));

        if (preg_match('/^[a-z][a-z0-9-]*$/', $tag) !== 1) {
            throw new \InvalidArgumentException(sprintf('Illegal HTML tag name "%s".', $tag));
        }

        return $tag;
    }

    private static function looksLikeId(mixed $value): bool
    {
        return is_string($value)
            && preg_match('/^#[A-Za-z][A-Za-z0-9_:.-]*$/', $value) === 1;
    }

    private static function dasherize(string $name): string
    {
        return strtolower((string) preg_replace('/(?<!^)[A-Z]/', '-$0', $name));
    }
}