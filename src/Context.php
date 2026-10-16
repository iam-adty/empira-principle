<?php

declare(strict_types=1);

namespace Empira;

use Empira\Container\Container;
use Empira\Http\Request;
use Empira\Http\Response;
use Empira\Security\Csrf;
use Empira\State\StateStore;

/**
 * The per-request services handed to every component.
 *
 * Keeping these in one object is what lets a component stay a plain class:
 * it receives a `Context` and never has to reach for globals or a container.
 */
final class Context
{
    public function __construct(
        public readonly Request $request,
        /**
         * The response being built, replaceable.
         *
         * Every other field here is `readonly` because nothing legitimately
         * changes mid-request. This one is the exception, and it has to be: a
         * {@see Response} is immutable — `pushUrl()` and friends return a
         * modified copy — so something has to hold the replacement for the router
         * to return later.
         *
         * A {@see Component} that queues a header does not keep the copy to
         * itself; it puts it here, and this stays the single answer.
         */
        public Response $response,
        public readonly Registry $registry,
        public readonly TokenCodec $tokens,
        public readonly StateStore $state,
        public readonly string $baseUrl,
        public readonly ?Csrf $csrf = null,
        public readonly ?Container $container = null,
    ) {
    }
}