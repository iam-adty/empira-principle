<?php

declare(strict_types=1);

namespace Empira\View;

use Empira\Application;
use Empira\Element;
use Empira\ElementFactory;
use Empira\Raw;

/**
 * One swappable area of a page.
 *
 * A region is the thing htmx replaces. It gives its element a stable
 * `id="region-<name>"` so the generated `hx-*` attributes have a target, and it
 * offers the shorthands a page body actually needs — render a component, build
 * a list, mark a region as an out-of-band swap.
 *
 *     fn(Region $region) => $region->component(Dashboard::class)
 *                              ->append($region->list($todos, fn($todo) => $todo['title']));
 */
final class Region
{
    public function __construct(
        private readonly ElementFactory $hx,
        public readonly string $name,
        private readonly array $data = [],
        private readonly ?Application $app = null,
    ) {
    }

    /**
     * The region's element factory, for building markup beside a component.
     */
    public function hx(): ElementFactory
    {
        return $this->hx;
    }

    /**
     * The region's root element, carrying the id htmx swaps.
     */
    public function element(string $tag = 'div', mixed ...$children): Element
    {
        return $this->hx->tag($tag, ...$children)->id($this->id());
    }

    public function id(): string
    {
        return 'region-' . $this->name;
    }

    /**
     * Render a component inside this region.
     *
     * @param class-string $component
     */
    public function component(string $component, array $props = []): Element|Raw
    {
        if ($this->app === null) {
            throw new \LogicException('This region has no application and cannot render components.');
        }

        return $this->app->render($component, $props);
    }

    /**
     * A `<ul>` built from a list, skipping empty input rather than emitting an
     * empty element nobody asked for.
     */
    public function list(iterable $items, callable $row): Element
    {
        $list = $this->hx->ul();

        foreach ($items as $item) {
            $element = $row($item);

            if ($element instanceof Element || $element instanceof Raw) {
                $list->append($element);
            } else {
                $list->append($this->hx->li()->text((string) $element));
            }
        }

        return $list;
    }

    /**
     * Render this region as an out-of-band swap.
     *
     * htmx applies the response to elements outside the swap target, which is
     * how one action updates a badge and a list in a single response.
     */
    public function oob(Element|Raw $content, string|bool $swap = true): Element|Raw
    {
        $element = $content instanceof Element ? $content : null;

        if ($element === null) {
            return Raw::of('<div hx-swap-oob="true">' . $content->get() . '</div>');
        }

        return $element->attr('hx-swap-oob', $swap === true ? 'true' : $swap);
    }

    /**
     * Fetch this region's contents after the page settles.
     *
     * Useful for content that is slow or nobody is waiting for — a dashboard
     * panel, a suggestion list — without blocking the page that embeds it.
     */
    public function lazy(string $url, string $trigger = 'load'): Element
    {
        return $this->hx->div()->id($this->id())->attr('hx-get', $url)->attr('hx-trigger', $trigger);
    }

    public function data(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }
}