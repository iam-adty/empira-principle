<?php

declare(strict_types=1);

namespace Empira\Attributes;

/**
 * Exposes a component method to htmx as an action.
 *
 * This is the attribute form of declaring an action — the counterpart to
 * listing the method in `actions()`. Whichever the component uses, the router
 * only ever calls a method that is public, declared here or in `actions()`,
 * and never one inherited from the framework itself.
 *
 *     final class TodoList extends Component
 *     {
 *         #[Delete]
 *         public function remove(): void { /* ... *\/ }
 *     }
 *
 * `#[Action]` with no verb behaves exactly like `->action('remove')`: POST.
 * Pair it with a verb attribute — or use the verb attribute alone, which
 * implies `#[Action]` — to make the framework emit the matching `hx-*`
 * attribute and reject the action over any other HTTP method.
 *
 * @see Verb for the verb attributes
 */
#[\Attribute(\Attribute::TARGET_METHOD)]
final class Action
{
    public function __construct(
        /**
         * Overrides the HTTP verb. Usually set by applying `#[Get]`, `#[Post]`,
         * and friends instead; this exists for dynamically chosen verbs.
         */
        public readonly ?Verb $verb = null,
    ) {
    }
}