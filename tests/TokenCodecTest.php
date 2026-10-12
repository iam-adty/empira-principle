<?php

declare(strict_types=1);

namespace Empira\Tests;

use Empira\InvalidTokenException;
use Empira\TokenCodec;
use PHPUnit\Framework\TestCase;

final class TokenCodecTest extends TestCase
{
    private const SECRET = 'a-secret-that-is-long-enough';

    public function testRoundTripsIdentityAndProps(): void
    {
        $codec = new TokenCodec(self::SECRET);

        $token = $codec->encode('App\\Counter', 'abc123', ['count' => 7]);

        $this->assertSame(
            ['class' => 'App\\Counter', 'nonce' => 'abc123', 'props' => ['count' => 7]],
            $codec->decode($token)
        );
    }

    public function testTokensAreUrlSafe(): void
    {
        $codec = new TokenCodec(self::SECRET);

        $token = $codec->encode('App\\Counter', 'abc123', ['q' => 'a b&c=d/e?f']);

        $this->assertSame($token, rawurlencode($token));
    }

    public function testTamperedPropsAreRejected(): void
    {
        $codec = new TokenCodec(self::SECRET);

        [, $signature] = explode('.', $codec->encode('App\\Counter', 'abc123', ['role' => 'user']));

        // Same signature, admin privileges in the payload.
        $forged = rtrim(strtr(base64_encode((string) json_encode([
            'v' => 1,
            'c' => 'App\\Counter',
            'n' => 'abc123',
            'p' => ['role' => 'admin'],
        ])), '+/', '-_'), '=');

        $this->expectException(InvalidTokenException::class);

        $codec->decode($forged . '.' . $signature);
    }

    public function testTamperedSignatureIsRejected(): void
    {
        $codec = new TokenCodec(self::SECRET);

        $token = $codec->encode('App\\Counter', 'abc123');
        $tampered = substr($token, 0, -1) . (str_ends_with($token, 'A') ? 'B' : 'A');

        $this->expectException(InvalidTokenException::class);

        $codec->decode($tampered);
    }

    public function testATokenFromAnotherSecretIsRejected(): void
    {
        $mine = new TokenCodec(self::SECRET);
        $theirs = new TokenCodec('a-completely-different-secret');

        $this->expectException(InvalidTokenException::class);

        $mine->decode($theirs->encode('App\\Counter', 'abc123'));
    }

    public function testMalformedTokensAreRejected(): void
    {
        $codec = new TokenCodec(self::SECRET);

        $this->expectException(InvalidTokenException::class);

        $codec->decode('not-a-token');
    }

    public function testShortSecretsAreRefusedAtConstruction(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new TokenCodec('too-short');
    }
}