<?php

declare(strict_types=1);

namespace IamAdty\Empira\Http;

/**
 * The representation a response should take for one request.
 *
 * Decided once per request from the `Accept` header, and used for **errors**.
 * A handler always chooses its own success body, so returning
 * {@see Response::json()} works whatever the client asked for.
 *
 * The default is HTML, and that default is deliberate. htmx never sends an
 * `Accept` header at all — it signals intent with `xhr.overrideMimeType('text/html')`
 * — so what the server actually receives is the browser's XHR default, `*&#47;*`.
 * Resolving a wildcard to JSON would break every fragment swap in every
 * application, so `*&#47;*` means HTML and JSON has to be asked for explicitly.
 */
enum Format: string
{
    case Html = 'text/html';
    case Json = 'application/json';

    /**
     * The content type a body of this format is served as.
     */
    public function contentType(): string
    {
        return $this->value;
    }
}