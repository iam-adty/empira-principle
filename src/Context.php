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
        public readonly Response $response,
        public readonly Registry $registry,
        public readonly TokenCodec $tokens,
        public readonly StateStore $state,
        public readonly string $baseUrl,
        public readonly ?Csrf $csrf = null,
        public readonly ?Container $container = null,
    ) {
    }
}