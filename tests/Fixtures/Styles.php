<?php

declare(strict_types=1);

namespace IamAdty\Empira\Tests\Fixtures;

use IamAdty\Empira\Attributes\Get;
use IamAdty\Empira\Component;
use IamAdty\Empira\Element;
use IamAdty\Empira\Raw;
use IamAdty\Empira\StyleOrder;

/**
 * A styled child, and the baseline every aggregation test measures against.
 *
 * Its stylesheet is distinctive enough to be counted for in a page full of other
 * rules, and identical across instances so that deduplication has something
 * real to collapse.
 */
final class StyledChildFixture extends Component
{
    protected function styles(): Raw
    {
        return Raw::of('.styled-child { color: teal; }');
    }

    protected function render(): Element
    {
        return $this->hx->div()
            ->id($this->id())
            ->class('styled-child')
            ->text($this->stringProp('label', 'child'));
    }
}

/**
 * A child that is swapped on its own, so it keeps its sheet even inside an
 * aggregating parent.
 */
final class SelfSwappingChildFixture extends Component
{
    protected function styles(): Raw
    {
        return Raw::of('.self-swapping { color: crimson; }');
    }

    protected function swapsIndependently(): bool
    {
        return true;
    }

    protected function render(): Element
    {
        return $this->hx->div()->id($this->id())->class('self-swapping')->text('swaps alone');
    }
}

/**
 * A child that renders raw markup, where there is no root element to prepend a
 * `<style>` to — the case that made a child's styling an awkward special case.
 */
final class RawStyledChildFixture extends Component
{
    protected function styles(): Raw
    {
        return Raw::of('.raw-child { color: olive; }');
    }

    protected function render(): Raw
    {
        return Raw::of(sprintf('<p class="raw-child">%s</p>', htmlspecialchars($this->stringProp('label', 'raw'), ENT_QUOTES)));
    }
}

/**
 * Gathers its subtree's styles into one sheet and wins the tie — the composite
 * whose whole purpose is to dress its children.
 */
final class AggregateParentFixture extends Component
{
    protected function aggregateStyles(): ?StyleOrder
    {
        return StyleOrder::SelfLast;
    }

    protected function styles(): Raw
    {
        return Raw::of('.aggregate-parent { border: 0; }');
    }

    protected function render(): Element
    {
        return $this->hx->div()
            ->id($this->id())
            ->class('aggregate-parent')
            ->append(
                $this->child(StyledChildFixture::class, ['label' => 'one'])->markup(),
                $this->child(StyledChildFixture::class, ['label' => 'two'])->markup(),
            );
    }
}

/**
 * The same, keeping the order the framework produced before aggregation existed
 * — its own rules first, so its children still win a tie.
 */
final class AggregateFirstParentFixture extends Component
{
    protected function aggregateStyles(): ?StyleOrder
    {
        return StyleOrder::SelfFirst;
    }

    protected function styles(): Raw
    {
        return Raw::of('.aggregate-first-parent { margin: 0; }');
    }

    protected function render(): Element
    {
        return $this->hx->div()
            ->id($this->id())
            ->append($this->child(StyledChildFixture::class)->markup());
    }
}

/**
 * Aggregates, but one child declares it swaps on its own and keeps its sheet.
 */
final class MixedParentFixture extends Component
{
    protected function aggregateStyles(): ?StyleOrder
    {
        return StyleOrder::SelfLast;
    }

    protected function styles(): Raw
    {
        return Raw::of('.mixed-parent { padding: 0; }');
    }

    protected function render(): Element
    {
        return $this->hx->div()
            ->id($this->id())
            ->append(
                $this->child(StyledChildFixture::class)->markup(),
                $this->child(SelfSwappingChildFixture::class)->markup(),
            );
    }
}

/** Aggregates, with a child that renders raw markup. */
final class RawChildParentFixture extends Component
{
    protected function aggregateStyles(): ?StyleOrder
    {
        return StyleOrder::SelfLast;
    }

    protected function styles(): Raw
    {
        return Raw::of('.raw-child-parent { display: block; }');
    }

    protected function render(): Element
    {
        return $this->hx->div()
            ->id($this->id())
            ->append($this->child(RawStyledChildFixture::class)->markup());
    }
}

/**
 * Loads a child through an action, so the child arrives on its own.
 *
 * The case that has to keep working: anything fetched alone has to stand alone,
 * because there is no aggregating parent in that render pass. The child is still
 * hoisted — by *this* component, which is now the root — so it must still come
 * with its stylesheet rather than arriving bare.
 */
final class LazyParentFixture extends Component
{
    #[Get]
    public function loadchild(): void
    {
        $this->with('loaded', true);
    }

    protected function render(): Element
    {
        if ($this->boolProp('loaded')) {
            return $this->hx->div()
                ->id($this->id())
                ->append($this->child(StyledChildFixture::class)->markup());
        }

        return $this->lazy('loadchild');
    }
}