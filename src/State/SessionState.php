<?php

declare(strict_types=1);

namespace IamAdty\Empira\State;

/**
 * Keeps component props in the PHP session.
 *
 * Use this when props are large or sensitive enough that you would rather not
 * put them in the URL, and you are content with tying the component to one
 * user's session.
 */
final class SessionState implements StateStore
{
    public function __construct(
        private readonly string $namespace = 'empira',
        private readonly int $ttl = 86400,
    ) {
    }

    public function load(string $key): array
    {
        $this->start();

        $bucket = $_SESSION[$this->namespace][$key] ?? null;

        if (!is_array($bucket)) {
            return [];
        }

        if (isset($bucket['expires']) && $bucket['expires'] < time()) {
            $this->forget($key);

            return [];
        }

        return is_array($bucket['props'] ?? null) ? $bucket['props'] : [];
    }

    public function save(string $key, array $props): void
    {
        $this->start();

        $_SESSION[$this->namespace][$key] = [
            'props' => $props,
            'expires' => time() + $this->ttl,
        ];
    }

    public function forget(string $key): void
    {
        $this->start();

        unset($_SESSION[$this->namespace][$key]);
    }

    private function start(): void
    {
        if (session_status() === PHP_SESSION_NONE && PHP_SAPI !== 'cli' && session_id() === '') {
            @session_start();
        }

        if (!isset($_SESSION) || !is_array($_SESSION)) {
            // In tests and CLI there is no session; fall back to an in-process
            // bucket so components remain functional.
            $_SESSION = [];
        }

        if (!isset($_SESSION[$this->namespace]) || !is_array($_SESSION[$this->namespace])) {
            $_SESSION[$this->namespace] = [];
        }
    }
}