<?php

declare(strict_types=1);

namespace IamAdty\Empira\View;

use Closure;
use IamAdty\Empira\Application;
use IamAdty\Empira\Element;
use IamAdty\Empira\ElementFactory;
use IamAdty\Empira\Http\Request;
use IamAdty\Empira\Http\Session;
use IamAdty\Empira\Raw;
use IamAdty\Empira\Security\Csrf;

/**
 * The page shell — the one place that looks like a template.
 *
 * A layout owns the document: `<html>`, `<head>`, the htmx script, the CSRF
 * header every descendant inherits. Everything inside it is a region, and a
 * region is the unit htmx swaps.
 *
 *     final class Layout extends BaseLayout
 *     {
 *         protected function regions(): array
 *         {
 *             return [
 *                 'main' => fn(Region $region) => $region->component(Dashboard::class),
 *             ];
 *         }
 *     }
 *
 * ## Full page or fragment
 *
 * The same route answers both. A normal visit renders the whole document; a
 * request carrying `HX-Request` renders only the region htmx asked for, which
 * is what makes {@see Application::handle()} work unchanged for both. A boosted
 * link gets the full page, because it is a real navigation.
 *
 * @see Region
 */
abstract class Layout
{
    protected ElementFactory $hx;

    /**
     * Regions in the order they appear, as `name => callable(Region): Element|Raw|null`.
     *
     * @return array<string, callable(Region): (Element|Raw|null)>
     */
    abstract protected function regions(): array;

    /**
     * @param array<string, mixed> $data
     */
    public function __construct(
        protected readonly Application $app,
        protected readonly Request $request,
        protected readonly array $data = [],
        protected readonly ?Session $session = null,
        protected readonly ?Csrf $csrf = null,
    ) {
        $this->hx = new ElementFactory();
    }

    public function name(): string
    {
        return static::class;
    }

    /**
     * Is this request swapping one region rather than loading a page?
     */
    public function isFragment(): bool
    {
        return $this->request->isHtmx() && !$this->request->isBoosted();
    }

    /**
     * Which region htmx asked for, if it named one.
     */
    public function fragment(): ?string
    {
        return $this->request->target() !== null && str_starts_with((string) $this->request->target(), 'region-')
            ? substr((string) $this->request->target(), strlen('region-'))
            : null;
    }

    /**
     * Render the response for this request: a document, or just a region.
     */
    public function render(): Element|Raw
    {
        return $this->isFragment() ? $this->renderRegion($this->fragment() ?? '') : $this->renderDocument();
    }

    /**
     * Render one region by name, or the first one when the name is unknown.
     *
     * An unknown region name is not an error worth a 500: htmx asked for
     * something that no longer exists, so falling back to the first region gives
     * a usable page instead of a stack trace.
     */
    public function renderRegion(?string $name = null): Element|Raw
    {
        $regions = $this->regions();

        $name ??= array_key_first($regions) ?? null;

        if ($name === null || !isset($regions[$name])) {
            $name = array_key_first($regions);
        }

        if ($name === null) {
            return Raw::of('');
        }

        $region = new Region($this->hx, $name, $this->data, $this->app);

        $content = ($regions[$name])($region);

        return $content ?? Raw::of('');
    }

    /**
     * @return list<string>
     */
    public function regionNames(): array
    {
        return array_keys($this->regions());
    }

    /**
     * The whole page.
     */
    protected function renderDocument(): Element
    {
        $body = $this->hx->body();

        // One `hx-headers` on <body> is inherited by everything below it: this
        // is how the CSRF token reaches every component action.
        if ($this->csrf !== null) {
            $body->attr('hx-headers', $this->csrf->headersAttribute());
        }

        $header = $this->header();

        if ($header !== null) {
            $body->append($header);
        }

        foreach ($this->regions() as $name => $render) {
            $region = new Region($this->hx, $name, $this->data, $this->app);

            $content = $render($region);

            if ($content !== null) {
                $body->append($content);
            }
        }

        if ($this->session !== null) {
            foreach ($this->session->flashed() as $message) {
                $body->attr('data-flash', is_scalar($message) ? (string) $message : '');
            }
        }

        return $this->hx->html()
            ->attr('lang', $this->lang())
            ->append($this->head())
            ->append($body);
    }

    protected function head(): Element
    {
        return $this->hx->head()
            ->append($this->hx->meta()->attr('charset', 'utf-8'))
            ->append($this->hx->meta()->attr('name', 'viewport')->attr('content', 'width=device-width, initial-scale=1'))
            ->append($this->hx->title($this->title()))
            ->append($this->hx->script()->attr('src', $this->htmxUrl())->attr('defer', true))
            ->append($this->styleElement($this->styles()));
    }

    /**
     * Put the page's styling inside a `<style>` element.
     *
     * A stylesheet returned as {@see Raw} is a stylesheet, not markup: wrapping
     * it is what stops a bare string of CSS landing in `<head>` as text a
     * browser will hoist away and discard. An {@see Element} is taken as-is, so
     * an app that wants a `<link rel="stylesheet">` — or two separate `<style>`
     * blocks — can still say so.
     */
    private function styleElement(Element|Raw $styles): Element|Raw|null
    {
        if ($styles instanceof Element) {
            return $styles;
        }

        $css = $styles->get();

        return $css === '' ? null : $this->hx->style()->raw($css);
    }

    /**
     * Markup placed above the regions — a heading, a nav bar. Return null for
     * no header at all.
     */
    protected function header(): Element|Raw|null
    {
        return null;
    }

    protected function lang(): string
    {
        return 'en';
    }

    protected function title(): string
    {
        $title = $this->data['title'] ?? null;

        return is_string($title) && $title !== '' ? $title : 'Empira';
    }

    protected function htmxUrl(): string
    {
        return 'https://unpkg.com/htmx.org@2.0.4';
    }

    /**
     * Page styling.
     *
     * Return the stylesheet as {@see Raw} — the text is wrapped in `<style>`
     * for you — or return an {@see Element} if you would rather emit the tag
     * yourself, as with a `<link rel="stylesheet">`. Returning an empty string
     * or a `Raw` of one emits no `<style>` at all.
     */
    protected function styles(): Element|Raw
    {
        return Raw::of('');
    }

    /**
     * Read a value from the layout's data.
     */
    protected function data(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }
}