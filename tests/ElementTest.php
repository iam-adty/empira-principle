<?php

declare(strict_types=1);

namespace Empira\Tests;

use PHPUnit\Framework\TestCase;

final class ElementTest extends TestCase
{
    public function testRendersATagWithTextChildren(): void
    {
        $this->assertSame('<p>hello</p>', (new \Empira\Element('p', 'hello'))->render());
    }

    public function testLeadingHashArgumentBecomesTheId(): void
    {
        $this->assertSame(
            '<div id="counter">hi</div>',
            (new \Empira\Element('div', '#counter', 'hi'))->render()
        );
    }

    public function testATextNodeIsEscaped(): void
    {
        $this->assertSame(
            '<p>&lt;script&gt;alert(1)&lt;/script&gt;</p>',
            (new \Empira\Element('p', '<script>alert(1)</script>'))->render()
        );
    }

    public function testAttributeValuesAreEscaped(): void
    {
        $element = (new \Empira\Element('div'))->attr('title', 'a "quoted" & <dangerous> value');

        $this->assertSame(
            '<div title="a &quot;quoted&quot; &amp; &lt;dangerous&gt; value"></div>',
            $element->render()
        );
    }

    public function testIllegalAttributeNamesAreRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new \Empira\Element('div'))->attr('onclick"><script>alert(1)</script>', 'x');
    }

    public function testIllegalTagNamesAreRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new \Empira\Element('div><script>alert(1)</script>');
    }

    public function testBooleanAttributes(): void
    {
        $element = (new \Empira\Element('input'))
            ->attr('type', 'checkbox')
            ->attr('checked', true)
            ->attr('disabled', false);

        $this->assertSame('<input type="checkbox" checked>', $element->render());
    }

    public function testVoidElementsHaveNoClosingTag(): void
    {
        $this->assertSame('<br>', (new \Empira\Element('br'))->render());
    }

    public function testClassAppendsRatherThanReplaces(): void
    {
        $element = (new \Empira\Element('div'))->class('a')->class('b', 'c');

        $this->assertSame('<div class="a b c"></div>', $element->render());
    }

    public function testRawIsNotEscaped(): void
    {
        $element = (new \Empira\Element('div'))->raw('<b>bold</b>');

        $this->assertSame('<div><b>bold</b></div>', $element->render());
    }

    public function testNestedElementsRenderInOrder(): void
    {
        $list = (new \Empira\Element('ul'))->append(
            new \Empira\Element('li', 'one'),
            new \Empira\Element('li', 'two'),
        );

        $this->assertSame('<ul><li>one</li><li>two</li></ul>', $list->render());
    }

    public function testNullAndFalseChildrenAreDropped(): void
    {
        $element = (new \Empira\Element('div'))->append('a', null, false, 'b');

        $this->assertSame('<div>ab</div>', $element->render());
    }

    public function testHtmxAttributeHelpers(): void
    {
        $element = (new \Empira\Element('button', 'Load'))
            ->hxGet('/fragments')
            ->hxTarget('#list')
            ->hxSwap('innerHTML')
            ->hxTrigger('click')
            ->hxVals(['page' => 2])
            ->hxHeaders(['X-Key' => 'v'])
            ->hxIndicator('#spinner');

        $this->assertSame(
            '<button hx-get="/fragments" hx-target="#list" hx-swap="innerHTML"'
            . ' hx-trigger="click" hx-vals="{&quot;page&quot;:2}"'
            . ' hx-headers="{&quot;X-Key&quot;:&quot;v&quot;}" hx-indicator="#spinner">Load</button>',
            $element->render()
        );
    }

    public function testActionFallsBackToTheActionNameWithoutAResolver(): void
    {
        $element = (new \Empira\Element('button'))->action('save');

        $this->assertSame('<button hx-post="save"></button>', $element->render());
    }

    public function testActionUsesTheResolverAndMergesDefaults(): void
    {
        $element = (new \Empira\Element('button'))
            ->withResolver(fn(string $action): string => '/empira/tok.' . $action)
            ->withActionDefaults(['swap' => 'outerHTML', 'target' => '#root'])
            ->action('save', ['target' => '#other']);

        $this->assertSame(
            '<button hx-post="/empira/tok.save" hx-swap="outerHTML" hx-target="#other"></button>',
            $element->render()
        );
    }

    public function testActionMethodIsCaseInsensitiveInOutput(): void
    {
        $element = (new \Empira\Element('a'))->get('load');

        $this->assertSame('<a hx-get="load"></a>', $element->render());
    }

    public function testAWiredElementWithItsOwnIdTargetsItself(): void
    {
        $element = (new \Empira\Element('form', '#search'))
            ->withResolver(fn(): string => '/x')
            ->withActionDefaults(['swap' => 'outerHTML', 'target' => '#component-root'])
            ->action('search');

        $this->assertSame(
            '<form id="search" hx-post="/x" hx-swap="outerHTML" hx-target="#search"></form>',
            $element->render()
        );
    }

    public function testAClosureDefaultIsResolvedAtRenderTime(): void
    {
        $target = '#late';

        $element = (new \Empira\Element('div'))
            ->withResolver(fn(): string => '/x')
            ->withActionDefaults(['swap' => 'outerHTML', 'target' => function () use (&$target): string {
                return $target;
            }])
            ->append((new \Empira\Element('button'))
                ->withResolver(fn(): string => '/x')
                ->withActionDefaults(['swap' => 'outerHTML', 'target' => function () use (&$target): string {
                    return $target;
                }])
                ->action('go'));

        // Resolved late: the id is only known once the whole tree exists.
        $target = '#final';

        $this->assertSame(
            '<div><button hx-post="/x" hx-swap="outerHTML" hx-target="#final"></button></div>',
            $element->render()
        );
    }

    public function testAnUnwiredElementGetsNoImplicitTarget(): void
    {
        $element = (new \Empira\Element('div', '#wrapper'))
            ->withResolver(fn(): string => '/x')
            ->withActionDefaults(['swap' => 'outerHTML', 'target' => '#generated']);

        $this->assertSame('<div id="wrapper"></div>', $element->render());
    }

    public function testAnExplicitTargetBeatsTheDefault(): void
    {
        $element = (new \Empira\Element('div', '#search'))
            ->withResolver(fn(): string => '/x')
            ->withActionDefaults(['swap' => 'outerHTML', 'target' => '#generated']);

        $element->hxTarget('#elsewhere');

        $this->assertStringContainsString('hx-target="#elsewhere"', $element->render());
    }

    public function testAttributeValueCanBeRemovedWithNull(): void
    {
        $element = (new \Empira\Element('div'))->hxBoost(true)->hxBoost(false);

        $this->assertSame('<div></div>', $element->render());
    }
}