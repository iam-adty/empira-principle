<?php

declare(strict_types=1);

namespace IamAdty\Empira\Tests\Fixtures;

use IamAdty\Empira\Attributes\Action;
use IamAdty\Empira\Attributes\Delete;
use IamAdty\Empira\Attributes\Get;
use IamAdty\Empira\Attributes\Patch;
use IamAdty\Empira\Attributes\Post;
use IamAdty\Empira\Attributes\Prop;
use IamAdty\Empira\Attributes\Put;
use IamAdty\Empira\Attributes\Verb;
use IamAdty\Empira\Component;
use IamAdty\Empira\Element;
use IamAdty\Empira\State\StateStore;

/**
 * A stateless counter: props travel inside the signed token.
 */
final class CounterFixture extends Component
{
    public function actions(): array
    {
        return ['increment', 'reset'];
    }

    public function increment(): void
    {
        $this->with('count', $this->intProp('count') + 1);
    }

    public function reset(): void
    {
        $this->with('count', 0);
    }

    protected function render(): Element
    {
        return $this->hx->div()
            ->class('counter')
            ->append($this->hx->button('+')->action('increment'))
            ->append($this->hx->span()->text((string) $this->intProp('count')))
            ->append($this->hx->button('reset')->action('reset'));
    }
}

/**
 * Names its own root element, so default targets must follow it.
 */
final class NamedRootFixture extends Component
{
    public function actions(): array
    {
        return ['ping'];
    }

    public function ping(): void
    {
        $this->with('pongs', $this->intProp('pongs') + 1);
    }

    protected function render(): Element
    {
        return $this->hx->div('#named-root')
            ->append($this->hx->button('ping')->action('ping'))
            ->append($this->hx->span()->text((string) $this->intProp('pongs')));
    }
}

/**
 * Keeps its props in the state store instead of the token.
 */
final class StatefulFixture extends Component
{
    public function actions(): array
    {
        return ['add'];
    }

    protected function stateful(): bool
    {
        return true;
    }

    public function add(): void
    {
        $items = $this->arrayProp('items');
        $items[] = $this->request()->string('name');

        $this->with('items', $items);
    }

    protected function render(): Element
    {
        return $this->hx->ul()->append(
            $this->hx->button('add')->action('add'),
            ...array_map(
                fn(string $item): Element => $this->hx->li()->text($item),
                $this->arrayProp('items')
            )
        );
    }
}

/**
 * Declares a private method as an action — must be refused by the router.
 */
final class BadActionFixture extends Component
{
    public function actions(): array
    {
        return ['secret', 'toHtml'];
    }

    private function secret(): void
    {
        $this->with('leaked', true);
    }

    public function secretProbe(): void
    {
    }

    protected function render(): Element
    {
        return $this->hx->div()
            ->append($this->hx->button('secret')->action('secret'))
            ->append($this->hx->button('render')->action('toHtml'));
    }
}

/**
 * Records that it was constructed, so we can prove nothing is instantiated
 * for an unregistered class.
 */
final class UnregisteredFixture extends Component
{
    protected function render(): Element
    {
        return $this->hx->div();
    }
}

/**
 * Emits response headers from inside an action.
 */
final class ChattyFixture extends Component
{
    public function actions(): array
    {
        return ['save'];
    }

    public function save(): void
    {
        $this->response()
            ->pushUrl('/saved')
            ->triggerAfterSwap('saved', ['id' => 42]);
    }

    protected function render(): Element
    {
        return $this->hx->div()
            ->append($this->hx->button('save')->action('save'))
            ->append('saved');
    }
}

/**
 * An in-memory store, so stateful tests do not depend on a session.
 */
final class MemoryStore implements StateStore
{
    /** @var array<string, array<string, mixed>> */
    public array $buckets = [];

    public function load(string $key): array
    {
        return $this->buckets[$key] ?? [];
    }

    public function save(string $key, array $props): void
    {
        $this->buckets[$key] = $props;
    }

    public function forget(string $key): void
    {
        unset($this->buckets[$key]);
    }
}

/**
 * State as ordinary typed properties instead of a props array.
 */
final class TypedPropsFixture extends Component
{
    #[Prop] public int $count = 0;
    #[Prop] public string $label = 'hello';
    #[Prop] public array $items = [];
    #[Prop] public ?string $note = null;
    #[Prop] public float $ratio = 1.5;
    #[Prop] public bool $open = false;

    // Not round-trippable, so the framework must leave these alone.
    #[Prop] private string $hidden = 'private';
    #[Prop] public static string $shared = 'static';
    public string $unmarked = 'not state';

    public function actions(): array
    {
        return ['bump', 'relabel', 'stash', 'poke', 'toggle'];
    }

    public function bump(): void
    {
        $this->count += 2;
    }

    public function relabel(): void
    {
        $this->label = strtoupper($this->label);
    }

    public function stash(): void
    {
        $this->items[] = 'item-' . count($this->items);
    }

    public function poke(): void
    {
        // Writing the array behind a `#[Prop]` property must not win.
        $this->with('count', 999);
    }

    public function toggle(): void
    {
        $this->open = !$this->open;
    }

    protected function render(): Element
    {
        return $this->hx->div()
            ->class('typed')
            ->attr('data-count', (string) $this->count)
            ->attr('data-label', $this->label)
            ->attr('data-note', $this->note ?? 'null')
            ->attr('data-ratio', (string) $this->ratio)
            ->attr('data-open', $this->open ? 'yes' : 'no')
            ->attr('data-hidden', $this->hidden)
            ->attr('data-shared', self::$shared)
            ->attr('data-unmarked', $this->unmarked)
            ->attr('data-items', implode(',', $this->items))
            ->append($this->hx->button('bump')->action('bump'))
            ->append($this->hx->button('relabel')->action('relabel'))
            ->append($this->hx->button('stash')->action('stash'))
            ->append($this->hx->button('poke')->action('poke'))
            ->append($this->hx->button('toggle')->action('toggle'));
    }
}

/**
 * `#[Prop]` state held in the state store rather than in the token.
 */
final class TypedStatefulFixture extends Component
{
    #[Prop] public int $count = 0;

    protected function stateful(): bool
    {
        return true;
    }

    public function actions(): array
    {
        return ['bump'];
    }

    public function bump(): void
    {
        $this->count++;
    }

    protected function mount(): void
    {
        if ($this->count === 0) {
            $this->count = 100;
        }
    }

    protected function render(): Element
    {
        return $this->hx->div()
            ->class('typed-stateful')
            ->attr('data-count', (string) $this->count)
            ->append($this->hx->button('bump')->action('bump'));
    }
}

/**
 * An object-typed prop: props must never be able to construct a class.
 */
final class ObjectPropFixture extends Component
{
    #[Prop] public ?\DateTimeImmutable $when = null;

    public function actions(): array
    {
        return ['stamp'];
    }

    public function stamp(): void
    {
        $this->when = new \DateTimeImmutable('2026-01-01 00:00:00');
    }

    protected function render(): Element
    {
        return $this->hx->div()
            ->attr('data-when', $this->when?->format('Y-m-d') ?? 'null')
            ->append($this->hx->button('stamp')->action('stamp'));
    }
}
/**
 * Actions declared purely with attributes, one per verb.
 */
final class VerbsFixture extends Component
{
    #[Prop] public int $calls = 0;

    #[Get]
    public function peek(): void
    {
        $this->calls++;
    }

    #[Post]
    public function create(): void
    {
        $this->calls++;
    }

    #[Put]
    public function replace(): void
    {
        $this->calls++;
    }

    #[Patch]
    public function amend(): void
    {
        $this->calls++;
    }

    #[Delete]
    public function destroy(): void
    {
        $this->calls++;
    }

    protected function render(): Element
    {
        return $this->hx->div()
            ->attr('data-calls', (string) $this->calls)
            ->append($this->hx->button('peek')->action('peek'))
            ->append($this->hx->button('create')->action('create'))
            ->append($this->hx->button('replace')->action('replace'))
            ->append($this->hx->button('amend')->action('amend'))
            ->append($this->hx->button('destroy')->action('destroy'));
    }
}

/**
 * `#[Action]` on its own is a POST; `#[Action(verb: ...)]` overrides.
 */
final class ActionOverrideFixture extends Component
{
    #[Action]
    public function impliedPost(): void
    {
    }

    #[Action(verb: Verb::Get)]
    public function forcedGet(): void
    {
    }

    protected function render(): Element
    {
        return $this->hx->div()
            ->append($this->hx->button('implied')->action('impliedPost'))
            ->append($this->hx->button('forced')->action('forcedGet'));
    }
}

/**
 * A component mid-migration: some actions in `actions()`, some attributed.
 */
final class MixedActionsFixture extends Component
{
    public function actions(): array
    {
        return ['legacy', 'shared'];
    }

    #[Delete]
    public function viaAttribute(): void
    {
    }

    public function legacy(): void
    {
    }

    #[Get]
    public function shared(): void
    {
    }

    protected function render(): Element
    {
        return $this->hx->div()
            ->append($this->hx->button('attr')->action('viaAttribute'))
            ->append($this->hx->button('legacy')->action('legacy'))
            ->append($this->hx->button('shared')->action('shared'));
    }
}

/**
 * Attributes must not resurrect a non-public or inherited method.
 */
final class UnsafeAttributeFixture extends Component
{
    #[Post]
    private function hidden(): void
    {
    }

    #[Post]
    protected function shielded(): void
    {
    }

    protected function render(): Element
    {
        return $this->hx->div();
    }
}

/**
 * A value that exists only so an action has something to ask the container for.
 */
final readonly class Clock
{
    public function __construct(public string $partOfDay = 'day')
    {
    }
}

/**
 * Something with no implementation and no binding, so it cannot be built.
 */
interface Greeter
{
    public function greeting(): string;
}

/**
 * An action that takes a service as a parameter.
 *
 * Every other fixture keeps its actions parameterless, so this is the one that
 * would notice if actions stopped being autowired.
 */
final class ClockFixture extends \IamAdty\Empira\Component
{
    #[\IamAdty\Empira\Attributes\Post]
    public function greet(Clock $clock): void
    {
        $this->with('greeting', 'good ' . $clock->partOfDay);
    }

    /**
     * Asks for something the container cannot build.
     *
     * An interface with no binding, so this is the one action guaranteed to be
     * unresolvable — which is what makes the loud-failure assertion possible.
     */
    #[\IamAdty\Empira\Attributes\Post]
    public function speak(Greeter $greeter): void
    {
        $this->with('greeting', $greeter->greeting());
    }

    protected function render(): \IamAdty\Empira\Element
    {
        return $this->hx->div()
            ->id($this->id())
            ->append($this->hx->p()->text($this->stringProp('greeting', 'unset')))
            ->append($this->hx->button()->text('greet')->action('greet'))
            ->append($this->hx->button()->text('speak')->action('speak'));
    }
}

/**
 * A component that brings its own stylesheet.
 *
 * The rule under test is *where* the `<style>` lands: inside the root element,
 * which is the swap target, so it comes back when htmx swaps this component
 * alone. A stylesheet in `<head>` would be gone by then.
 */
final class StyledFixture extends \IamAdty\Empira\Component
{
    #[\IamAdty\Empira\Attributes\Post]
    public function restyle(): void
    {
        $this->with('count', $this->intProp('count') + 1);
    }

    protected function styles(): \IamAdty\Empira\Raw
    {
        return \IamAdty\Empira\Raw::of('.styled-fixture { display: grid }');
    }

    protected function render(): \IamAdty\Empira\Element
    {
        return $this->hx->div()
            ->id($this->id())
            ->class('styled-fixture')
            ->append(
                $this->hx->p()->text('styled ' . $this->intProp('count')),
                $this->hx->button()->text('restyle')->action('restyle')
            );
    }
}

/**
 * The same, but rendering a {@see \IamAdty\Empira\Raw} instead of an element,
 * which has no root to prepend into.
 */
final class RawStyledFixture extends \IamAdty\Empira\Component
{
    protected function styles(): \IamAdty\Empira\Raw
    {
        return \IamAdty\Empira\Raw::of('.raw-styled-fixture { display: block }');
    }

    protected function render(): \IamAdty\Empira\Raw
    {
        return \IamAdty\Empira\Raw::of(sprintf('<p id="%s">raw</p>', $this->id()));
    }
}
