<?php

declare(strict_types=1);

namespace IamAdty\Empira\Tests\Fixtures;

use IamAdty\Empira\Attributes\Post;
use IamAdty\Empira\Component;
use IamAdty\Empira\Element;
use IamAdty\Empira\Raw;

/**
 * A component with actions, used as a child.
 *
 * Carries its own stylesheet so that composition bringing the child's styles
 * with it can be asserted — a sheet in the document head would survive neither a
 * swap nor a fragment. Renders its own child count so that `children()` can be
 * checked without a test reaching inside the object.
 */
final class NestedChildFixture extends Component
{
    #[Post]
    public function childaction(): void
    {
        $this->with('ran', true);
    }

    protected function styles(): Raw
    {
        return Raw::of('[data-child] { color: rebeccapurple; }');
    }

    protected function render(): Element
    {
        return $this->hx->div()
            ->attr('data-child', $this->stringProp('label', 'default'))
            // A leaf composes nothing, so this is the empty-tree case.
            ->attr('data-own-children', (string) count($this->children()))
            ->append(
                $this->hx->button('go')->action('childaction'),
                $this->hx->span()->text($this->boolProp('ran') ? 'child ran' : 'I am the child'),
            );
    }
}

/**
 * A composite: children, and nothing a leaf could not also do.
 *
 * Renders what `child()` handed back — calling `->id()` on it only compiles if
 * it is a component and not markup — and what `children()` reports, so both are
 * asserted through the public render path rather than by constructing fixtures
 * with a Context the framework does not hand out.
 */
final class NestedParentFixture extends Component
{
    protected function styles(): Raw
    {
        return Raw::of('[data-parent] { display: block; }');
    }

    protected function render(): Element
    {
        $children = [
            $this->child(NestedChildFixture::class, ['label' => 'first']),
            $this->child(NestedChildFixture::class, ['label' => 'second', 'ran' => true]),
        ];

        return $this->hx->div()
            ->attr('data-parent', 'yes')
            ->attr('data-composed', (string) count($this->children()))
            ->attr('data-child-ids', implode(',', array_map(
                static fn(Component $child): string => $child->id(),
                $children,
            )))
            ->append(...array_map(
                static fn(Component $child): Element|Raw => $child->markup(),
                $children,
            ));
    }
}