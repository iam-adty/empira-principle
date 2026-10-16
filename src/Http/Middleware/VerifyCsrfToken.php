<?php

declare(strict_types=1);

namespace Empira\Http\Middleware;

use Empira\Security\RequestUser;
use Empira\Http\Format;
use Empira\Http\Middleware;
use Empira\Http\Request;
use Empira\Http\Response;
use Empira\Routing\RouteCollection;
use Empira\Security\Csrf;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Verifies the CSRF token on state-changing route requests.
 *
 * Component actions already verify themselves in
 * {@see \Empira\Component::beforeAction()}, so this is for ordinary
 * controllers — a form post, a fetch call — which have no component lifecycle
 * to hook into.
 *
 *     $app->use(VerifyCsrfToken::class);
 *
 * Safe methods pass straight through: a GET should never mutate, and a browser
 * cannot attach a custom header to a plain link, so refusing it would break
 * every shareable URL for no security gain.
 *
 * ## What is exempt, and why
 *
 * CSRF exists to defend **ambient** credentials — cookies the browser attaches
 * by itself, with no say from the page asking. Two kinds of request are
 * therefore not a CSRF problem:
 *
 * 1. **A request carrying a valid bearer token.** An `Authorization` header is
 *    not ambient: a cross-site page cannot set one without a CORS preflight
 *    that this server has to approve. Nothing to forge, nothing to protect.
 * 2. **A route that says so** with `#[Route(csrf: false)]`, for the cases a
 *    bearer guard does not cover.
 *
 * The route flag has to be read *here* rather than by a route middleware,
 * because this middleware runs outside it: by the time a route's own middleware
 * could report anything, this check has already refused. So this asks the
 * {@see RouteCollection} which route matched — the one already bound in the
 * container — rather than being told.
 *
 * Note what is *not* exempt: a request with a session cookie and no token.
 * That is the attack, and it stays refused.
 *
 * The refusal is rendered in the format this request negotiated, so a JSON
 * client receives the same error envelope as any other failure rather than an
 * HTML page it cannot parse.
 */
final class VerifyCsrfToken implements Middleware
{
    /** Methods that cannot change state and so are not checked. */
    private const SAFE = ['GET', 'HEAD', 'OPTIONS', 'TRACE'];

    public function __construct(
        private readonly Csrf $csrf,
        private readonly Format $format = Format::Html,
        private readonly ?RequestUser $auth = null,
        private readonly ?RouteCollection $routes = null,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        // Narrowed once, here, rather than at every use. The parameter is typed
        // as PSR requires — PHP will not let it be narrower — and this middleware
        // wants `method`, `csrfToken()` and `path()`, all of which are Empira's.
        $empira = Request::of($request);

        if (in_array(strtoupper($empira->method), self::SAFE, true)) {
            return $handler->handle($empira);
        }

        if ($this->csrf->isValid($empira->csrfToken())) {
            return $handler->handle($empira);
        }

        if ($this->routeOptsOut($empira) || $this->authenticatedByNonAmbientCredential($empira)) {
            return $handler->handle($empira);
        }

        return $this->refuse();
    }

    /**
     * Did the route this request matched ask not to be checked?
     *
     * A miss here is not a failure — it means the request matched no route at
     * all, which is a 404 further along and not this middleware's business.
     */
    private function routeOptsOut(Request $request): bool
    {
        if ($this->routes === null) {
            return false;
        }

        return $this->routes->match($request->method, $request->path())?->route->verifiesCsrf() === false;
    }

    /**
     * Was this request authenticated by a credential the browser will not send
     * on its own?
     *
     * Asked of the guards rather than sniffed from the header: a header that
     * merely *looks* like a bearer token is not proof of anything, and exempting
     * on the shape of a header would be a hole a page could open by sending a
     * useless one.
     */
    private function authenticatedByNonAmbientCredential(Request $request): bool
    {
        return $this->auth?->authenticatedByNonAmbientCredential() === true;
    }

    private function refuse(): Response
    {
        $message = 'CSRF token mismatch.';

        if ($this->format === Format::Json) {
            return Response::json(['error' => ['status' => 419, 'message' => $message]], 419);
        }

        return Response::html($message, 419);
    }
}