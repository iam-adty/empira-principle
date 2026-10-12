<?php

declare(strict_types=1);

namespace Empira\Security;

use Empira\ForbiddenActionException;
use Empira\Http\Request;

/**
 * Session-backed CSRF protection for component actions.
 *
 * htmx does not submit a CSRF field with a bare `hx-post` on a `<div>`, so the
 * page's token is forwarded as an `X-CSRF-Token` header. Wire that once, in
 * the layout:
 *
 *     <body hx-headers='{"X-CSRF-Token": "<?= $csrf->token() ?>"}'>
 *
 * `hx-headers` is inherited by descendants, which covers every element in the
 * document in a single declaration.
 */
final class Csrf
{
    public function __construct(
        private readonly string $sessionKey = '_csrf',
        private readonly string $header = 'X-CSRF-Token',
    ) {
    }

    public function token(): string
    {
        $this->start();

        if (!isset($_SESSION[$this->sessionKey]) || !is_string($_SESSION[$this->sessionKey])) {
            $_SESSION[$this->sessionKey] = bin2hex(random_bytes(32));
        }

        return $_SESSION[$this->sessionKey];
    }

    public function isValid(?string $candidate): bool
    {
        if ($candidate === null) {
            return false;
        }

        $this->start();

        $expected = $_SESSION[$this->sessionKey] ?? null;

        return is_string($expected) && hash_equals($expected, $candidate);
    }

    /**
     * @throws ForbiddenActionException
     */
    public function verify(Request $request): void
    {
        if (!$this->isValid($request->csrfToken())) {
            throw new ForbiddenActionException('CSRF token mismatch.');
        }
    }

    /**
     * A ready-to-use `hx-headers` attribute that forwards the token.
     */
    public function headersAttribute(): string
    {
        return json_encode([$this->header => $this->token()], JSON_UNESCAPED_SLASHES) ?: '{}';
    }

    private function start(): void
    {
        if (session_status() === PHP_SESSION_NONE && PHP_SAPI !== 'cli' && session_id() === '') {
            @session_start();
        }

        if (!isset($_SESSION) || !is_array($_SESSION)) {
            $_SESSION = [];
        }
    }
}