<?php

declare(strict_types=1);

namespace IamAdty\Empira\Testing;

use IamAdty\Empira\Http\Response;
use PHPUnit\Framework\Assert;

/**
 * The response to a test request, with assertions hanging off it.
 *
 * Assertions delegate to {@see Assert} rather than throwing by hand, so a
 * failure reads like any other PHPUnit failure and counts towards the suite the
 * way it should. They return `$this`, which makes a chain read as one
 * sentence about the response:
 *
 *     $client->get('/todos/7')->request()
 *         ->assertOk()
 *         ->assertSee('Buy milk')
 *         ->assertDontSee('Buy bread');
 *
 * Header lookups are case-insensitive, matching what a browser and the
 * framework's own {@see \IamAdty\Empira\Http\Request} do, so `assertHeader('hx-trigger',
 * ...)` does not depend on how the component happened to capitalise it.
 *
 * @see \IamAdty\Empira\Testing\TestClient
 */
final class TestResponse
{
    /** @var array<string, string> lower-cased header name => value */
    private array $index;

    public function __construct(private readonly Response $response)
    {
        $this->index = [];

        foreach ($response->headers() as $name => $value) {
            $this->index[strtolower((string) $name)] = (string) $value;
        }
    }

    /**
     * The framework response underneath, for anything the client does not wrap.
     */
    public function response(): Response
    {
        return $this->response;
    }

    public function status(): int
    {
        return $this->response->status();
    }

    public function body(): string
    {
        return $this->response->body();
    }

    public function header(string $name): ?string
    {
        return $this->index[strtolower($name)] ?? null;
    }

    /**
     * @return array<string, string>
     */
    public function headers(): array
    {
        return $this->response->headers();
    }

    // ---------------------------------------------------------------- status

    public function assertOk(): self
    {
        return $this->assertStatus(200);
    }

    public function assertNotFound(): self
    {
        return $this->assertStatus(404);
    }

    public function assertForbidden(): self
    {
        return $this->assertStatus(403);
    }

    public function assertStatus(int $expected): self
    {
        Assert::assertSame($expected, $this->status(), sprintf(
            'Expected status %d, got %d. Body: %s',
            $expected,
            $this->status(),
            $this->excerpt()
        ));

        return $this;
    }

    // ---------------------------------------------------------------- body

    public function assertSee(string $needle): self
    {
        Assert::assertStringContainsString($needle, $this->body(), sprintf(
            'Expected the body to contain "%s". Body: %s',
            $needle,
            $this->excerpt()
        ));

        return $this;
    }

    public function assertDontSee(string $needle): self
    {
        Assert::assertStringNotContainsString($needle, $this->body(), sprintf(
            'Expected the body not to contain "%s". Body: %s',
            $needle,
            $this->excerpt()
        ));

        return $this;
    }

    // ---------------------------------------------------------------- headers

    public function assertHeader(string $name, string $value): self
    {
        Assert::assertSame($value, $this->header($name), sprintf(
            'Expected header "%s" to be "%s", got %s. All headers: %s',
            $name,
            $value,
            var_export($this->header($name), true),
            $this->headers() === [] ? 'none' : json_encode($this->headers())
        ));

        return $this;
    }

    /**
     * Keep a failure message about markup readable instead of dumping a whole
     * page into the test output.
     */
    private function excerpt(): string
    {
        $body = $this->body();

        return strlen($body) > 500 ? substr($body, 0, 500) . '…' : $body;
    }
}