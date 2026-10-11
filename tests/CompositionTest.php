<?php

declare(strict_types=1);

namespace IamAdty\Empira\Tests;

use IamAdty\Empira\Application;
use IamAdty\Empira\State\NullState;
use IamAdty\Empira\Testing\TestClient;
use IamAdty\Empira\Tests\Fixtures\NestedChildFixture;
use IamAdty\Empira\Tests\Fixtures\NestedParentFixture;
use IamAdty\Empira\UnknownComponentException;
use PHPUnit\Framework\TestCase;

/**
 * Composite components: a component containing components.
 *
 * The point of the pattern is that a composite and a leaf are the same kind of
 * thing. There is one type, one render path, one token shape, one set of rules,
 * so nesting costs nothing structurally — and in particular a nested
 * component's action URL resolves without anybody having registered it, which
 * used to be the price of nesting and was paid for components whose only fault
 * was being forgotten.
 */
final class CompositionTest extends TestCase
{
    private const SECRET = 'a-secret-that-is-long-enough';
    private const FIXTURES = 'IamAdty\\Empira\\Tests\\Fixtures';

    protected function setUp(): void
    {
        $_SESSION = [];
    }

    /**
     * No component is registered by name. The namespace rule is the only thing
     * making any of these reachable, which is the arrangement under test.
     */
    private function app(): Application
    {
        return (new Application(self::SECRET, new NullState()))->components(self::FIXTURES);
    }

    private function render(): string
    {
        return (string) $this->app()->render(NestedParentFixture::class)->render();
    }

    // ------------------------------------------------------------- the tree

    public function testAChildComposesIntoItsParent(): void
    {
        $html = $this->render();

        self::assertStringContainsString('data-parent="yes"', $html);
        self::assertStringContainsString('data-child="first"', $html);
        self::assertStringContainsString('data-child="second"', $html);
    }

    public function testChildReturnsAComponentRatherThanMarkup(): void
    {
        $html = $this->render();

        // The parent calls `->id()` on what `child()` returned. That only
        // compiles — let alone produces a value — if it handed back a component
        // rather than a string of rendered HTML.
        preg_match('/data-child-ids="([^"]*)"/', $html, $matches);

        self::assertNotEmpty($matches[1] ?? '', 'The parent could not read an id off its child.');
        self::assertCount(2, explode(',', $matches[1]));
        self::assertNotSame($matches[1][0], $matches[1][1], 'Each child is its own instance.');
    }

    public function testChildrenReportsWhatWasComposedInOrder(): void
    {
        $html = $this->render();

        self::assertStringContainsString('data-composed="2"', $html);
        // Order is the order the parent composed them in.
        self::assertLessThan(
            strpos($html, 'data-child="second"'),
            strpos($html, 'data-child="first"'),
        );
    }

    public function testAComponentThatComposedNothingReportsNoChildren(): void
    {
        $html = $this->render();

        self::assertStringContainsString('data-own-children="0"', $html);
    }

    public function testRenderComponentIsShorthandForChildThenMarkup(): void
    {
        $app = $this->app();

        $composed = (string) $app->render(NestedParentFixture::class)->render();
        $leaf = (string) $app->render(NestedChildFixture::class)->render();

        // Same type, same entry point, same output shape — which is the whole
        // claim of the pattern: nothing marks a composite as a special case.
        self::assertStringContainsString('<div', $composed);
        self::assertStringContainsString('<div', $leaf);
        self::assertStringContainsString('data-own-children="0"', $leaf);
    }

    // ------------------------------------------------------- the actual bug

    public function testANestedComponentNeedsNoRegistrationOfItsOwn(): void
    {
        $app = $this->app();
        $html = (string) $app->render(NestedParentFixture::class)->render();

        $url = $this->urlFor($html, 'childaction');

        // The regression. Before `$app->components()`, this URL was minted
        // happily and resolved to `UnknownComponentException` the moment it was
        // pressed: the child rendered perfectly and then 404'd on its own button.
        $response = (new TestClient($app))->post($url)->request();

        self::assertSame(200, $response->status());
        self::assertStringContainsString('child ran', $response->body());
    }

    public function testTheNestedChildIsNotReachableWithoutTheNamespaceRule(): void
    {
        $app = new Application(self::SECRET, new NullState()); // no components() call

        $this->expectException(UnknownComponentException::class);

        $app->registry()->resolve(NestedChildFixture::class);
    }

    // -------------------------------------------------- rendering equivalence

    public function testAChildsStylesTravelInsideItsOwnSwapTarget(): void
    {
        $html = $this->render();

        // A stylesheet in <head> would be gone after the first swap. The child's
        // has to arrive inside the element htmx is about to replace.
        self::assertMatchesRegularExpression(
            '/<div[^>]*data-child[^>]*>\s*<style>/s',
            $html,
            "A child's styles must sit inside its own root element."
        );
    }

    public function testAChildsPropsArriveTheWayTheRouterWouldDeliverThem(): void
    {
        $html = $this->render();

        self::assertStringContainsString('data-child="first"', $html);
        self::assertStringContainsString('I am the child', $html);
        self::assertStringContainsString('child ran', $html);
    }

    private function urlFor(string $html, string $action): string
    {
        preg_match_all('/hx-post="([^"]+)"/', $html, $matches);

        foreach ($matches[1] ?? [] as $url) {
            if (str_ends_with(html_entity_decode($url), '/' . $action)) {
                return html_entity_decode($url);
            }
        }

        $this->fail(sprintf('No hx-post URL ending in "/%s" was rendered.', $action));
    }
}