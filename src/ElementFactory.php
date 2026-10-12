<?php

declare(strict_types=1);

namespace Empira;

use Closure;

/**
 * Creates elements, optionally pre-wired to a component's action resolver.
 *
 * Inside a component use `$this->hx->button('Save')->action('save')`; outside
 * one, the global `hx()` factory returns a resolver-less instance where
 * `action()` is not available.
 */
final class ElementFactory
{
    private static ?self $default = null;

    private ?Closure $resolver;
    private array $actionDefaults;

    public function __construct(?Closure $resolver = null, array $actionDefaults = [])
    {
        $this->resolver = $resolver;
        $this->actionDefaults = $actionDefaults;
    }

    /**
     * The resolver-less factory backing the global `hx()` helper.
     */
    public static function default(): self
    {
        return self::$default ??= new self();
    }

    public function withResolver(?Closure $resolver, array $actionDefaults = []): self
    {
        return new self($resolver, $actionDefaults);
    }

    public function tag(string $tag, mixed ...$children): Element
    {
        $element = new Element($tag, ...$children);

        if ($this->resolver !== null) {
            $element->withResolver($this->resolver)->withActionDefaults($this->actionDefaults);
        }

        return $element;
    }

    /**
     * `hx()->div()`, `hx()->button('Save')`, `hx()->input()` …
     *
     * @param array<array-key, mixed> $children
     */
    public function __call(string $tag, array $children): Element
    {
        return $this->tag($tag, ...$children);
    }
}