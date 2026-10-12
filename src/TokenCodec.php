<?php

declare(strict_types=1);

namespace Empira;

/**
 * Signs component identity into an opaque, tamper-proof token.
 *
 * The token is `base64url(payload).base64url(hmac)` where the payload carries
 * the component class, the instance nonce and — unless the component keeps its
 * state on the server — its props. Because the signature covers the props,
 * a client cannot promote itself by editing values in the URL.
 */
final class TokenCodec
{
    private const VERSION = 1;

    public function __construct(private readonly string $secret)
    {
        if (strlen($secret) < 16) {
            throw new \InvalidArgumentException('The token secret must be at least 16 bytes.');
        }
    }

    /**
     * @param array<string, mixed> $props
     */
    public function encode(string $class, string $nonce, array $props = []): string
    {
        $payload = Html::json([
            'v' => self::VERSION,
            'c' => $class,
            'n' => $nonce,
            'p' => $props,
        ]);

        $encoded = self::base64UrlEncode($payload);

        return $encoded . '.' . self::base64UrlEncode($this->sign($encoded));
    }

    /**
     * @return array{class: string, nonce: string, props: array<string, mixed>}
     *
     * @throws InvalidTokenException
     */
    public function decode(string $token): array
    {
        $parts = explode('.', $token);

        if (count($parts) !== 2) {
            throw new InvalidTokenException('Malformed component token.');
        }

        [$encoded, $signature] = $parts;

        $expected = self::base64UrlEncode($this->sign($encoded));

        if (!hash_equals($expected, $signature)) {
            throw new InvalidTokenException('Component token signature mismatch.');
        }

        $payload = json_decode(self::base64UrlDecode($encoded), true);

        if (!is_array($payload) || ($payload['v'] ?? null) !== self::VERSION) {
            throw new InvalidTokenException('Unsupported component token payload.');
        }

        if (!is_string($payload['c'] ?? null) || !is_string($payload['n'] ?? null)) {
            throw new InvalidTokenException('Malformed component token payload.');
        }

        return [
            'class' => $payload['c'],
            'nonce' => $payload['n'],
            'props' => is_array($payload['p'] ?? null) ? $payload['p'] : [],
        ];
    }

    private function sign(string $payload): string
    {
        return hash_hmac('sha256', $payload, $this->secret, true);
    }

    private static function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $value): string
    {
        $decoded = base64_decode(strtr($value, '-_', '+/'), true);

        if ($decoded === false) {
            throw new InvalidTokenException('Malformed component token.');
        }

        return $decoded;
    }
}