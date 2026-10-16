<?php

declare(strict_types=1);

namespace Empira;

use Empira\Attributes\Verb;
use Empira\Http\Request;
use Empira\Http\Response;
use ReflectionMethod;

/**
 * Resolves an action URL back to a component and runs the action.
 *
 * Every request that hits an action URL takes the same path: verify the token,
 * resolve the class through the {@see Registry} allow-list, refuse the action
 * unless it is both public and declared in `actions()`, run it, then re-render
 * the component so htmx has fresh HTML to swap in.
 */
final class Router
{
    public function __construct(private readonly string $baseUrl = '/empira')
    {
    }

    public function baseUrl(): string
    {
        return rtrim($this->baseUrl, '/');
    }

    /**
     * Does this request target a component action?
     *
     * @return array{token: string, action: string}|null
     */
    public function match(Request $request): ?array
    {
        $prefix = $this->baseUrl() . '/';

        if (!str_starts_with($request->path(), $prefix)) {
            return null;
        }

        $segments = explode('/', rawurldecode(substr($request->path(), strlen($prefix))));

        if (count($segments) !== 2 || $segments[0] === '' || $segments[1] === '') {
            return null;
        }

        [$token, $action] = $segments;

        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $action) !== 1) {
            return null;
        }

        return ['token' => $token, 'action' => $action];
    }

    /**
     * @param array{token: string, action: string} $match
     *
     * @throws InvalidTokenException
     * @throws UnknownComponentException
     * @throws ForbiddenActionException
     * @throws MethodNotAllowedException
     */
    public function dispatch(Context $context, array $match): Response
    {
        $identity = $context->tokens->decode($match['token']);
        $class = $context->registry->resolve($identity['class']);

        /** @var Component $component */
        $component = new $class($context, $identity['props'], $identity['nonce']);
        $component->boot();

        $this->guardAction($component, $class, $match['action'], $context);

        $action = $match['action'];

        $component->beforeAction($action);
        $this->invoke($context, $component, $action);
        $component->afterAction($action);

        return $context->response
            ->withStatus(200)->withHtml($component->toHtml());
    }

    /**
     * Call a declared action with its parameters resolved by type.
     *
     * An action is a method like any other, so it takes what it needs the way a
     * controller does — `login(Auth $auth, UserRepository $users)` — rather
     * than reaching into the container itself. An action that asks for nothing
     * is unaffected: there is simply nothing to resolve.
     *
     * Note the action's **return value is discarded**; a component responds by
     * mutating its props and the response, which is what the swap is built from.
     */
    private function invoke(Context $context, Component $component, string $action): void
    {
        if ($context->container === null) {
            $component->{$action}();

            return;
        }

        $context->container->call([$component, $action]);
    }

    /**
     * @param class-string<Component> $class
     *
     * @throws ForbiddenActionException
     * @throws MethodNotAllowedException
     */
    private function guardAction(Component $component, string $class, string $action, Context $context): void
    {
        $map = $component->actionMap();

        if (!isset($map[$action])) {
            throw new ForbiddenActionException(sprintf(
                'Action "%s" is not declared by %s.',
                $action,
                $class
            ));
        }

        if (!method_exists($component, $action)) {
            throw new ForbiddenActionException(sprintf(
                'Action "%s" is declared by %s but no such method exists.',
                $action,
                $class
            ));
        }

        $method = new ReflectionMethod($component, $action);

        // Inherited component actions are fine; the framework's own internals
        // are not, so a careless `actions()` return cannot expose them.
        if (!$method->isPublic() || $method->getDeclaringClass()->getName() === Component::class) {
            throw new ForbiddenActionException(sprintf(
                'Action "%s" is not callable on %s.',
                $action,
                $class
            ));
        }

        $this->guardVerb($context->request, $class, $action, $map[$action]);
    }

    /**
     * An action must be reached with the verb it declared.
     *
     * @param class-string<Component> $class
     *
     * @throws MethodNotAllowedException
     */
    private function guardVerb(Request $request, string $class, string $action, Verb $verb): void
    {
        if (strtoupper($request->method) !== $verb->value) {
            throw new MethodNotAllowedException(sprintf(
                'Action "%s" on %s expects %s, got %s.',
                $action,
                $class,
                $verb->value,
                strtoupper($request->method)
            ), [$verb->value]);
        }
    }
}