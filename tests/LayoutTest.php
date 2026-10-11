<?php

declare(strict_types=1);

namespace IamAdty\Empira\Tests;

use IamAdty\Empira\Application;
use IamAdty\Empira\Element;
use IamAdty\Empira\Http\Request;
use IamAdty\Empira\Raw;
use IamAdty\Empira\State\NullState;
use IamAdty\Empira\View\Layout;
use IamAdty\Empira\View\Region;
use PHPUnit\Framework\TestCase;

/**
 * The page shell: what a full document contains, and what a fragment does not.
 */
final class LayoutTest extends TestCase
{
    private const SECRET = 'a-secret-that-is-long-enough';

    /**
     * @param class-string<Layout> $layout
     */
    private function render(string $layout, Request $request): string
    {
        $app = new Application(self::SECRET, new NullState());

        return $app->view($layout, [], $request)->body();
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function page(string $class = PlainLayout::class): array
    {
        $html = $this->render($class, new Request('GET', '/'));

        return [$html, '<html lang="en">'];
    }

    // ---------------------------------------------------------------- styles

    public function testRawStylesAreWrappedInAStyleElement(): void
    {
        [$html] = $this->page(StyledLayout::class);

        // The bug this file exists for: bare CSS used to land in <head> as text.
        $this->assertStringContainsString('<style>body{color:red}</style>', $html);
    }

    public function testRawStylesAreNotAppendedAsBareText(): void
    {
        [$html] = $this->page(StyledLayout::class);

        $this->assertStringNotContainsString('</script>body{color:red}', $html);
    }

    public function testAnElementStyleIsLeftAlone(): void
    {
        [$html] = $this->page(LinkStyleLayout::class);

        $this->assertStringContainsString('<link rel="stylesheet" href="/app.css">', $html);
        $this->assertStringNotContainsString('<style>', $html, 'an Element must not be re-wrapped');
    }

    public function testEmptyStylesEmitNoStyleElement(): void
    {
        [$html] = $this->page();

        $this->assertStringNotContainsString('<style', $html);
    }

    public function testStyleContentIsNotEscaped(): void
    {
        $html = $this->render(TrickyStyleLayout::class, new Request('GET', '/'));

        // A stylesheet is full of characters that HTML escaping would destroy.
        $this->assertStringContainsString('a > b', $html);
        $this->assertStringContainsString('&', $html);
        $this->assertStringNotContainsString('&gt;', $html);
    }

    // ---------------------------------------------------------------- document

    public function testAFullPageRendersTheWholeDocument(): void
    {
        [$html, $expected] = $this->page();

        $this->assertStringContainsString($expected, $html);
        $this->assertStringContainsString('<head>', $html);
        $this->assertStringContainsString('<body>', $html);
        $this->assertStringContainsString('<title>', $html);
        $this->assertStringContainsString('unpkg.com/htmx.org', $html);
    }

    public function testRegionsAreRenderedWithTheirIds(): void
    {
        [$html] = $this->page();

        $this->assertStringContainsString('id="region-main"', $html);
        $this->assertStringContainsString('id="region-side"', $html);
    }

    public function testAHeaderIsRenderedAboveTheRegions(): void
    {
        [$html] = $this->page(HeaderLayout::class);

        $this->assertStringContainsString('<h1>Page title</h1>', $html);
        $this->assertLessThan(
            strpos($html, 'id="region-main"'),
            strpos($html, 'Page title'),
            'the header should come before the regions'
        );
    }

    public function testALayoutWithoutAHeaderEmitsNone(): void
    {
        [$html] = $this->page();

        $this->assertStringNotContainsString('<h1>', $html);
    }

    // ---------------------------------------------------------------- fragments

    public function testAnHtmxRequestGetsOnlyTheNamedRegion(): void
    {
        $html = $this->render(PlainLayout::class, new Request(
            'GET',
            '/',
            headers: ['HX-Request' => 'true', 'HX-Target' => 'region-side']
        ));

        $this->assertStringNotContainsString('<html', $html);
        $this->assertStringNotContainsString('<head', $html);
        $this->assertStringContainsString('id="region-side"', $html);
        $this->assertStringNotContainsString('id="region-main"', $html);
    }

    public function testAnHtmxRequestWithNoTargetGetsTheFirstRegion(): void
    {
        $html = $this->render(PlainLayout::class, new Request('GET', '/', headers: ['HX-Request' => 'true']));

        $this->assertStringContainsString('id="region-main"', $html);
        $this->assertStringNotContainsString('id="region-side"', $html);
    }

    public function testABoostedRequestStillGetsTheWholePage(): void
    {
        // A boosted link is a real navigation, so it must receive a document.
        $html = $this->render(PlainLayout::class, new Request('GET', '/', headers: [
            'HX-Request' => 'true',
            'HX-Boosted' => 'true',
            'HX-Target' => 'region-side',
        ]));

        $this->assertStringContainsString('<html', $html);
        $this->assertStringContainsString('id="region-side"', $html);
    }

    public function testAnUnknownRegionFallsBackRatherThanFailing(): void
    {
        $html = $this->render(PlainLayout::class, new Request(
            'GET',
            '/',
            headers: ['HX-Request' => 'true', 'HX-Target' => 'region-gone']
        ));

        $this->assertStringContainsString('id="region-main"', $html);
    }

    public function testRegionNamesAreExposed(): void
    {
        $app = new Application(self::SECRET, new NullState());

        $layout = new PlainLayout($app, new Request('GET', '/'));

        $this->assertSame(['main', 'side'], $layout->regionNames());
    }
}

// ------------------------------------------------------------------ fixtures

/**
 * Two plain regions, shared so each fixture below can vary only what it tests.
 */
trait PlainRegions
{
    protected function regions(): array
    {
        return [
            'main' => fn(Region $r): Element => $r->element('div')->text('main content'),
            'side' => fn(Region $r): Element => $r->element('aside')->text('side content'),
        ];
    }
}

final class PlainLayout extends Layout
{
    use PlainRegions;
}

final class HeaderLayout extends Layout
{
    use PlainRegions;

    protected function header(): Element
    {
        return $this->hx->h1('Page title');
    }
}

final class StyledLayout extends Layout
{
    use PlainRegions;

    protected function styles(): Element|Raw
    {
        return Raw::of('body{color:red}');
    }
}

final class LinkStyleLayout extends Layout
{
    use PlainRegions;

    protected function styles(): Element|Raw
    {
        return $this->hx->link()->attr('rel', 'stylesheet')->attr('href', '/app.css');
    }
}

final class TrickyStyleLayout extends Layout
{
    use PlainRegions;

    protected function styles(): Element|Raw
    {
        return Raw::of('a > b { content: "&"; }');
    }
}