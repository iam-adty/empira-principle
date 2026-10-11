<?php

declare(strict_types=1);

namespace IamAdty\Empira\Attributes;

/**
 * Declares that a class or method answers a URL.
 *
 * On a **Layout**, it means "this URL renders this page", which collapses the
 * route and the page into one file and lets `mount()` take the path parameters:
 *
 *     #[Route('/', name: 'home')]
 *     #[Route('/dashboard', name: 'dash')]
 *     final class HomeLayout extends Layout
 *     {
 *         protected function regions(): array { ... }
 *     }
 *
 * On a **controller method**, it is the conventional thing:
 *
 *     #[Route('/api/todos', methods: ['GET'], name: 'todos.index')]
 *     public function index(): Response { ... }
 *
 * The attribute is repeatable, so one class can serve several URLs, and `name`
 * is optional — omitted, it is derived from the class (`Pages\HomeLayout`
 * becomes `pages.home`), so `url()->to('pages.home')` works immediately.
 *
 * `methods` is separate from the {@see Get}/{@see Post} verb attributes on
 * purpose: those mark *component actions*, and one annotation should not grant
 * two different capabilities.
 *
 * @see Get
 * @see Post
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
final class Route
{
    /**
     * @param string|list<string> $methods an HTTP method, or several; defaults to GET
     * @param array<class-string> $middleware classes or callables, resolved from the container
     */
    public function __construct(
        public readonly string $pattern,
        public readonly string|array $methods = 'GET',
        public readonly ?string $name = null,
        public readonly array $middleware = [],
        public readonly ?int $priority = null,
        public readonly bool $csrf = true,
    ) {
    }

    /**
     * @return list<string>
     */
    public function verbs(): array
    {
        return array_values(array_unique(array_map(
            static fn(string $method): string => strtoupper($method),
            (array) $this->methods
        )));
    }
}