<?php

declare(strict_types=1);

namespace IamAdty\Empira\Routing;

use IamAdty\Empira\Application;
use IamAdty\Empira\Container\Container;
use IamAdty\Empira\Element;
use IamAdty\Empira\Http\Request;
use IamAdty\Empira\Http\Response;
use IamAdty\Empira\Raw;

/**
 * The base class for ordinary page controllers.
 *
 * Components handle their own actions; a controller handles everything else —
 * the full page, redirects, JSON, downloads. It is deliberately thin: it holds
 * the container so a controller method can ask for what it needs by type.
 *
 *     final class TodoController extends Controller
 *     {
 *         public function show(int $id): Response
 *         {
 *             return $this->view(TodoPage::class, ['id' => $id]);
 *         }
 *     }
 */
abstract class Controller
{
    public function __construct(
        protected readonly Application $app,
        protected readonly Container $container,
    ) {
    }

    protected function view(string $component, array $props = []): Response
    {
        return Response::html((string) $this->app->render($component, $props)->render());
    }

    /**
     * Render a component without wrapping it in a response.
     *
     * @param class-string $component
     */
    protected function component(string $component, array $props = []): Element|Raw
    {
        return $this->app->render($component, $props);
    }

    protected function redirect(string $url, int $status = 302): Response
    {
        return (new Response())->setStatus($status)->withHeader('Location', $url);
    }

    protected function json(mixed $data, int $status = 200): Response
    {
        return Response::json($data, $status);
    }

    /**
     * Stop and answer with a status code.
     *
     * @throws HttpException
     */
    protected function abort(int $status, string $message = ''): never
    {
        throw new HttpException($status, $message !== '' ? $message : HttpException::defaultMessage($status));
    }

    protected function notFound(string $message = ''): never
    {
        $this->abort(404, $message);
    }

    protected function request(): Request
    {
        return $this->container->get(Request::class);
    }

    protected function response(): Response
    {
        return $this->container->get(Response::class);
    }

    /**
     * Build a URL for a named route.
     *
     * @param array<string, mixed> $parameters
     */
    protected function url(string $name, array $parameters = []): string
    {
        return $this->app->url()->to($name, $parameters);
    }
}