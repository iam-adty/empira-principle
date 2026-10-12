<?php

declare(strict_types=1);

namespace Empira\Attributes;

/**
 * Marks a property as component state that travels with the component.
 *
 * A `#[Prop]` property is hydrated from the incoming props before any component
 * code runs, and written back out whenever the component's identity is signed
 * into an action URL or saved to the state store. That lets a component read and
 * write state as an ordinary typed property instead of reaching for a props
 * array:
 *
 *     final class Counter extends Component
 *     {
 *         #[Prop] public int $count = 0;
 *
 *         public function increment(): void
 *         {
 *             $this->count += 5;
 *         }
 *     }
 *
 * The property is the source of truth. Writing to the same key through
 * `with()` has no effect once the property carries the `#[Prop]` attribute.
 *
 * Properties that the framework cannot write are ignored rather than fatal:
 * `private`, `static` and `readonly` ones are skipped, since state the
 * component cannot see is state the framework cannot round-trip.
 *
 * @see \Empira\State\PropBag for the reflection behind this
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
final class Prop
{
}