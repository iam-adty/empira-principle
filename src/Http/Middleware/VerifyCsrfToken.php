<?php

declare(strict_types=1);

namespace IamAdty\Empira\Http\Middleware;

use Closure;
use IamAdty\Empira\Http\Format;
use IamAdty\Empira\Http\Middleware;
use IamAdty\Empira\Http\Request;
use IamAdty\Empira\Http\Response;
use IamAdty\Empira\Html;
use IamAdty\Empira\Security\Csrf;

/**
 * Verifies the CSRF token on state-changing route requests.
 *
 * Component actions already verify themselves in
 * {@see \IamAdty\Empira\Component::beforeAction()}, so this is for ordinary
 * controllers — a form post, a fetch call — which have no component lifecycle
 * to hook into.
 *
 *     $app->use(VerifyCsrfToken::class);
 *
 * Safe methods pass straight through: a GET should never mutate, and a browser
 * cannot attach a custom header to a plain link, so refusing it would break
 * every shareable URL for no security gain.
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
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        if (!in_array(strtoupper($request->method), self::SAFE, true) && !$this->csrf->isValid($request->csrfToken())) {
            return $this->refuse();
        }

        return $next($request);
    }

    private function refuse(): Response
    {
        $message = 'CSRF token mismatch.';

        if ($this->format === Format::Json) {
            return (new Response())
                ->withHeader('Content-Type', 'application/json')
                ->setStatus(419)
                ->setBody(Html::json(['error' => ['status' => 419, 'message' => $message]]));
        }

        return Response::html($message, 419);
    }
}