<?php

declare(strict_types=1);

namespace IamAdty\Empira\Http;

/**
 * A thin, namespaced wrapper over the PHP session.
 *
 * Everything lives under one namespace key, so a component's server-side state
 * and the framework's flash messages cannot collide with an application's own
 * session variables.
 *
 *     $session->flash('success', 'Saved.');
 *     // ... on the next request:
 *     $session->flashed();   // ['success' => 'Saved.'], and forgotten
 *
 * Flash messages are the natural companion to htmx: an action can queue one and
 * swap a "saved" banner in without a full page reload.
 *
 * Under the CLI — which is how the test suite runs — there is no session to
 * start, so an in-process array stands in. Requests share it for the life of the
 * process, which is exactly the behaviour a test wants.
 */
final class Session
{
    private const FLASH_NEXT = '_flash_next';
    private const FLASH_CURRENT = '_flash';

    private bool $begun = false;

    public function __construct(
        private readonly string $namespace = 'empira',
        private readonly bool $start = true,
    ) {
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $this->begin();

        return $_SESSION[$this->namespace][$key] ?? $default;
    }

    public function put(string $key, mixed $value): void
    {
        $this->begin();

        $_SESSION[$this->namespace][$key] = $value;
    }

    public function has(string $key): bool
    {
        $this->begin();

        return isset($_SESSION[$this->namespace][$key]);
    }

    public function forget(string $key): void
    {
        $this->begin();

        unset($_SESSION[$this->namespace][$key]);
    }

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        $this->begin();

        return $_SESSION[$this->namespace];
    }

    public function clear(): void
    {
        $this->begin();

        $_SESSION[$this->namespace] = [];
    }

    /**
     * Queue a value for the *next* request only.
     */
    public function flash(string $key, mixed $value): void
    {
        $this->begin();

        $_SESSION[$this->namespace][self::FLASH_NEXT][$key] = $value;
    }

    /**
     * Read and forget everything that was flashed for this request.
     *
     * @return array<string, mixed>
     */
    public function flashed(): array
    {
        $this->begin();

        $flashed = $_SESSION[$this->namespace][self::FLASH_CURRENT] ?? [];

        unset($_SESSION[$this->namespace][self::FLASH_CURRENT]);

        return is_array($flashed) ? $flashed : [];
    }

    /**
     * A session id that survives a login, defeating session fixation.
     */
    public function regenerate(): void
    {
        $this->begin();

        if (PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }

    private function begin(): void
    {
        // The session is prepared once per instance — and an instance is
        // per request — so rotating the flash buckets more than once would
        // discard this request's own messages.
        if ($this->begun) {
            return;
        }

        $this->begun = true;

        if ($this->start && session_status() === PHP_SESSION_NONE && PHP_SAPI !== 'cli') {
            @session_start();
        }

        if (!isset($_SESSION) || !is_array($_SESSION)) {
            $_SESSION = [];
        }

        if (!isset($_SESSION[$this->namespace]) || !is_array($_SESSION[$this->namespace])) {
            $_SESSION[$this->namespace] = [];
        }

        $this->ageFlashes();
    }

    /**
     * Rotate the flash buckets, so last request's messages disappear and this
     * request's queued ones become readable.
     */
    private function ageFlashes(): void
    {
        $bucket = &$_SESSION[$this->namespace];

        $current = $bucket[self::FLASH_NEXT] ?? [];

        unset($bucket[self::FLASH_CURRENT], $bucket[self::FLASH_NEXT]);

        if ($current !== []) {
            $bucket[self::FLASH_CURRENT] = $current;
        }
    }
}