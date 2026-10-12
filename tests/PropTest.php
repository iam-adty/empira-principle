<?php

declare(strict_types=1);

namespace Empira\Tests;

use Empira\Application;
use Empira\Http\Request;
use Empira\Registry;
use Empira\State\NullState;
use Empira\State\PropBag;
use Empira\Tests\Fixtures\MemoryStore;
use Empira\Tests\Fixtures\ObjectPropFixture;
use Empira\Tests\Fixtures\TypedPropsFixture;
use Empira\Tests\Fixtures\TypedStatefulFixture;
use PHPUnit\Framework\TestCase;

/**
 * `#[Prop]` properties: typed state that round-trips through the signed token.
 */
final class PropTest extends TestCase
{
    private const SECRET = 'a-secret-that-is-long-enough';

    private Registry $registry;

    protected function setUp(): void
    {
        $this->registry = new Registry();
    }

    private function app(?MemoryStore $store = null): Application
    {
        $app = new Application(self::SECRET, $store ?? new NullState());

        $app->registry()
            ->alias('typed', TypedPropsFixture::class)
            ->alias('typed-stateful', TypedStatefulFixture::class)
            ->alias('object-prop', ObjectPropFixture::class);

        return $app;
    }

    private function attribute(string $html, string $name): ?string
    {
        return preg_match('/' . preg_quote($name, '/') . '="([^"]*)"/', $html, $matches) === 1
            ? html_entity_decode($matches[1], ENT_QUOTES)
            : null;
    }

    private function actionFor(string $html, string $action): string
    {
        foreach ($this->urls($html) as $url) {
            if (str_ends_with($url, '/' . $action)) {
                return $url;
            }
        }

        $this->fail('No action URL ending in "/' . $action . '" was rendered.');
    }

    /**
     * @return list<string>
     */
    private function urls(string $html): array
    {
        preg_match_all('/hx-post="([^"]+)"/', $html, $matches);

        return array_map('html_entity_decode', $matches[1] ?? []);
    }

    // ---------------------------------------------------------------- round trips

    public function testATypedPropertyMutatesAcrossRequests(): void
    {
        $app = $this->app();

        $page = (string) $app->render(TypedPropsFixture::class)->render();
        $this->assertSame('0', $this->attribute($page, 'data-count'));

        $fragment = $app->handle(new Request('POST', $this->actionFor($page, 'bump')))->body();
        $this->assertSame('2', $this->attribute($fragment, 'data-count'));

        $fragment = $app->handle(new Request('POST', $this->actionFor($fragment, 'bump')))->body();
        $this->assertSame('4', $this->attribute($fragment, 'data-count'));
    }

    public function testStringPropertiesRoundTrip(): void
    {
        $app = $this->app();

        $page = (string) $app->render(TypedPropsFixture::class)->render();
        $fragment = $app->handle(new Request('POST', $this->actionFor($page, 'relabel')))->body();

        $this->assertSame('HELLO', $this->attribute($fragment, 'data-label'));
    }

    public function testArrayPropertiesRoundTrip(): void
    {
        $app = $this->app();

        $page = (string) $app->render(TypedPropsFixture::class)->render();
        $fragment = $app->handle(new Request('POST', $this->actionFor($page, 'stash')))->body();

        $this->assertSame('item-0', $this->attribute($fragment, 'data-items'));

        $fragment = $app->handle(new Request('POST', $this->actionFor($fragment, 'stash')))->body();

        $this->assertSame('item-0,item-1', $this->attribute($fragment, 'data-items'));
    }

    public function testNullablePropertiesKeepNull(): void
    {
        $app = $this->app();

        $page = (string) $app->render(TypedPropsFixture::class)->render();
        $fragment = $app->handle(new Request('POST', $this->actionFor($page, 'bump')))->body();

        $this->assertSame('null', $this->attribute($fragment, 'data-note'));
    }

    public function testBooleanAndFloatPropertiesRoundTrip(): void
    {
        $app = $this->app();

        $page = (string) $app->render(TypedPropsFixture::class)->render();
        $this->assertSame('no', $this->attribute($page, 'data-open'));
        $this->assertSame('1.5', $this->attribute($page, 'data-ratio'));

        $fragment = $app->handle(new Request('POST', $this->actionFor($page, 'toggle')))->body();

        $this->assertSame('yes', $this->attribute($fragment, 'data-open'));
        $this->assertSame('1.5', $this->attribute($fragment, 'data-ratio'));
    }

    public function testThePropertyWinsOverThePropsArray(): void
    {
        $app = $this->app();

        $page = (string) $app->render(TypedPropsFixture::class)->render();
        $fragment = $app->handle(new Request('POST', $this->actionFor($page, 'poke')))->body();

        $this->assertSame('0', $this->attribute($fragment, 'data-count'), 'with() must not overwrite a #[Prop]');
    }

    // ---------------------------------------------------------------- what is ignored

    public function testPrivateStaticAndUnmarkedPropertiesAreIgnored(): void
    {
        $app = $this->app();

        $page = (string) $app->render(TypedPropsFixture::class)->render();

        $this->assertSame('private', $this->attribute($page, 'data-hidden'));
        $this->assertSame('static', $this->attribute($page, 'data-shared'));
        $this->assertSame('not state', $this->attribute($page, 'data-unmarked'));

        // None of them may leak into the signed token.
        $token = explode('/', $this->actionFor($page, 'bump'))[2] ?? '';
        $payload = json_decode(
            (string) base64_decode(strtr(explode('.', $token)[0] ?? '', '-_', '+/'), true),
            true
        );

        $this->assertArrayNotHasKey('hidden', $payload['p'] ?? []);
        $this->assertArrayNotHasKey('shared', $payload['p'] ?? []);
        $this->assertArrayNotHasKey('unmarked', $payload['p'] ?? []);
    }

    // ---------------------------------------------------------------- typing

    public function testPropsAreCoercedToTheDeclaredType(): void
    {
        // A token written before the property became an int still round-trips.
        $app = $this->app();

        $fragment = $app->handle(new Request(
            'POST',
            $this->actionFor((string) $app->render(TypedPropsFixture::class, [
                'count' => '7',
                'open' => '1',
                'ratio' => '2',
            ])->render(), 'bump')
        ))->body();

        $this->assertSame('9', $this->attribute($fragment, 'data-count'));
        $this->assertSame('yes', $this->attribute($fragment, 'data-open'));
        $this->assertSame('2', $this->attribute($fragment, 'data-ratio'));
    }

    public function testAValueThatCannotCoerceFallsBackToTheDefault(): void
    {
        $app = $this->app();

        $page = (string) $app->render(TypedPropsFixture::class, [
            'count' => ['not', 'a', 'number'],
            'label' => ['also', 'not', 'a', 'string'],
        ])->render();

        $fragment = $app->handle(new Request('POST', $this->actionFor($page, 'bump')))->body();

        // Both fell back to their defaults: 0 + 2, and the untouched label.
        $this->assertSame('2', $this->attribute($fragment, 'data-count'));
        $this->assertSame('hello', $this->attribute($fragment, 'data-label'));

        $fragment = $app->handle(new Request('POST', $this->actionFor($fragment, 'relabel')))->body();

        $this->assertSame('HELLO', $this->attribute($fragment, 'data-label'));
    }

    public function testAnObjectPropNeverConstructsAClassFromTheToken(): void
    {
        $app = $this->app();

        $page = (string) $app->render(ObjectPropFixture::class, ['when' => 'DateTimeImmutable'])->render();

        $this->assertSame('null', $this->attribute($page, 'data-when'));

        $fragment = $app->handle(new Request('POST', $this->actionFor($page, 'stamp')))->body();

        $this->assertSame('2026-01-01', $this->attribute($fragment, 'data-when'));
    }

    // ---------------------------------------------------------------- stateful

    public function testStatefulComponentsPersistTypedProperties(): void
    {
        $store = new MemoryStore();
        $app = $this->app($store);

        $page = (string) $app->render(TypedStatefulFixture::class)->render();

        // `mount()` seeds the state the first time only.
        $this->assertSame('100', $this->attribute($page, 'data-count'));

        $fragment = $app->handle(new Request('POST', $this->actionFor($page, 'bump')))->body();

        $this->assertSame('101', $this->attribute($fragment, 'data-count'));
    }

    // ---------------------------------------------------------------- PropBag directly

    public function testPropBagIgnoresPropertiesItCannotWrite(): void
    {
        $component = new TypedPropsFixture(
            new \Empira\Context(
                request: new Request('GET', '/'),
                response: new \Empira\Http\Response(),
                registry: $this->registry,
                tokens: new \Empira\TokenCodec(self::SECRET),
                state: new NullState(),
                baseUrl: '/empira',
            )
        );

        $names = array_keys(PropBag::properties($component));

        $this->assertContains('count', $names);
        $this->assertContains('note', $names);
        $this->assertNotContains('hidden', $names, 'private');
        $this->assertNotContains('shared', $names, 'static');
        $this->assertNotContains('unmarked', $names, 'no attribute');
    }

    public function testPropBagFlushLeavesUninitialisedPropertiesOut(): void
    {
        $subject = new class () {
            #[\Empira\Attributes\Prop]
            public array $untouched;

            #[\Empira\Attributes\Prop]
            public string $named = 'value';
        };

        $flushed = PropBag::flush($subject, ['kept' => true]);

        $this->assertSame(['kept' => true, 'named' => 'value'], $flushed);
    }
}