<?php

declare(strict_types=1);

namespace IamAdty\Empira\Tests;

use IamAdty\Empira\Application;
use IamAdty\Empira\Security\Csrf;
use IamAdty\Empira\State\NullState;
use IamAdty\Empira\Testing\TestClient;
use IamAdty\Empira\Tests\Fixtures\AggregateFirstParentFixture;
use IamAdty\Empira\Tests\Fixtures\AggregateParentFixture;
use IamAdty\Empira\Tests\Fixtures\LazyParentFixture;
use IamAdty\Empira\Tests\Fixtures\MixedParentFixture;
use IamAdty\Empira\Tests\Fixtures\RawChildParentFixture;
use IamAdty\Empira\Tests\Fixtures\StyledChildFixture;
use PHPUnit\Framework\TestCase;

/**
 * Aggregated stylesheets.
 *
 * Every component carries its own sheet inside its own root, because the root is
 * the swap target and a sheet in `<head>` does not travel with a swap. That is
 * repetitive for a composite, and it leaves the parent no say in the order its
 * rules landed in — an order nobody chose.
 *
 * Opting in gathers the subtree's CSS at the composite's root, once per distinct
 * rulesheet. The tests below also pin what it must *not* do: a child that swaps
 * on its own keeps its sheet, and a component fetched alone still stands alone.
 */
final class StyleAggregationTest extends TestCase
{
    private const SECRET = 'a-secret-that-is-long-enough';
    private const FIXTURES = 'IamAdty\\Empira\\Tests\\Fixtures';

    private const CHILD_CSS = '.styled-child { color: teal; }';
    private const PARENT_CSS = '.aggregate-parent { border: 0; }';
    private const SELF_SWAPPING_CSS = '.self-swapping { color: crimson; }';
    private const RAW_CSS = '.raw-child { color: olive; }';

    private function app(): Application
    {
        return (new Application(self::SECRET, new NullState()))->components(self::FIXTURES);
    }

    private function guardedApp(): Application
    {
        return (new Application(self::SECRET, new NullState(), '/empira', new Csrf()))
            ->components(self::FIXTURES);
    }

    private function render(string $class): string
    {
        return (string) $this->app()->render($class)->render();
    }

    private static function sheets(string $html): int
    {
        return substr_count($html, '<style>');
    }

    // ------------------------------------------------- unchanged by default

    public function testAComponentThatDoesNotOptInIsUnchanged(): void
    {
        // The regression that matters most: a sheet stays inside the component it
        // belongs to, so every swap still brings it.
        $html = $this->render(StyledChildFixture::class);

        self::assertSame(1, self::sheets($html));
        self::assertMatchesRegularExpression('/<div[^>]*>\s*<style>/s', $html);
    }

    public function testAChildKeepsItsOwnSheetInsideAnUnaggregatingParent(): void
    {
        $html = $this->render(AggregateFirstParentFixture::class);

        // Opted in, so this one *is* aggregated — the parent-only case is covered
        // by the fixture above rendering a bare child.
        self::assertSame(1, self::sheets($html));
    }

    // ------------------------------------------------------------ aggregating

    public function testAnAggregatingParentEmitsOneSheetForTheWholeSubtree(): void
    {
        $html = $this->render(AggregateParentFixture::class);

        self::assertSame(1, self::sheets($html));
        self::assertStringContainsString(self::PARENT_CSS, $html);
        self::assertStringContainsString(self::CHILD_CSS, $html);
    }

    public function testAHoistedChildCarriesNoSheetOfItsOwn(): void
    {
        $html = $this->render(AggregateParentFixture::class);

        // The child element exists; the sheet inside it does not.
        self::assertStringContainsString('class="styled-child"', $html);
        self::assertDoesNotMatchRegularExpression(
            '/class="styled-child"[^>]*>\s*<style>/s',
            $html,
            'A hoisted child must not also carry its own stylesheet.'
        );
    }

    public function testIdenticalRulesAreEmittedOnceNotOncePerInstance(): void
    {
        // Two children of the same class, so the same rulesheet twice.
        $html = $this->render(AggregateParentFixture::class);

        self::assertSame(1, self::sheets($html));
        self::assertSame(1, substr_count($html, self::CHILD_CSS));
        self::assertStringContainsString('>one<', $html);
        self::assertStringContainsString('>two<', $html);
    }

    // --------------------------------------------------------------- ordering

    public function testSelfLastPutsTheCompositeRulesAfterItsChildren(): void
    {
        $html = $this->render(AggregateParentFixture::class);

        self::assertLessThan(
            strpos($html, self::PARENT_CSS),
            strpos($html, self::CHILD_CSS),
            'SelfLast must place the composite\'s own rules after its children\'s.'
        );
    }

    public function testSelfFirstKeepsTheOrderTheFrameworkProducedBefore(): void
    {
        $html = $this->render(AggregateFirstParentFixture::class);

        // Children win a tie, which is what positional insertion always gave and
        // what an application silently depended on.
        self::assertLessThan(
            strpos($html, self::CHILD_CSS),
            strpos($html, '.aggregate-first-parent { margin: 0; }'),
            'SelfFirst must leave the composite\'s rules ahead of its children\'s.'
        );
    }

    // ---------------------------------------------------------- escape hatch

    public function testAChildThatSwapsOnItsOwnKeepsItsSheet(): void
    {
        $html = $this->render(MixedParentFixture::class);

        self::assertMatchesRegularExpression(
            '/class="self-swapping"[^>]*>\s*<style>/s',
            $html,
            'A child that swaps on its own must carry its rules with it.'
        );
        self::assertStringContainsString(self::SELF_SWAPPING_CSS, $html);
    }

    public function testTheSelfSwappingChildsRulesAppearExactlyOnce(): void
    {
        // Collected as well as kept, and the rules are on the page twice — which
        // is exactly what the escape hatch must avoid.
        $html = $this->render(MixedParentFixture::class);

        self::assertSame(1, substr_count($html, self::SELF_SWAPPING_CSS));
    }

    public function testTheAggregatedSheetStillCarriesTheHoistedChildrenOnly(): void
    {
        $html = $this->render(MixedParentFixture::class);

        // The parent's sheet has its own rules and the ordinary child's, not the
        // self-swapping one's — that one stayed where it was.
        self::assertStringContainsString('.mixed-parent { padding: 0; }', $html);
        self::assertStringContainsString(self::CHILD_CSS, $html);

        // Two sheets: the parent's aggregate, and the one the self-swapping child
        // kept for itself. A third would mean it was collected as well.
        self::assertSame(2, self::sheets($html));
    }

    // ------------------------------------------------------------ raw markup

    public function testARawRenderingChildStillReachesTheParentSheet(): void
    {
        // No root element to prepend to, which is why a child's styling was an
        // awkward special case in the first place.
        $html = $this->render(RawChildParentFixture::class);

        self::assertSame(1, self::sheets($html));
        self::assertStringContainsString(self::RAW_CSS, $html);
    }

    // ------------------------------------------------- fetched on its own

    public function testAComponentFetchedByAnActionStandsAloneWithItsSheet(): void
    {
        $app = $this->guardedApp();

        $parent = (string) $app->render(LazyParentFixture::class)->render();
        preg_match('/hx-get="([^"]+)"/', $parent, $matches);
        self::assertNotEmpty($matches[1] ?? '', 'The lazy fixture must render a fetchable URL.');

        $response = (new TestClient($app))->get(html_entity_decode($matches[1]))->request();

        // No aggregating parent in this render pass, so the child must carry its
        // own sheet — otherwise it arrives on the page unstyled.
        self::assertSame(200, $response->status());
        self::assertStringContainsString(self::CHILD_CSS, $response->body());
        self::assertSame(1, self::sheets($response->body()));
    }
}