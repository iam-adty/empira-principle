<?php

declare(strict_types=1);

namespace IamAdty\Empira\Tests;

use IamAdty\Empira\Application;
use IamAdty\Empira\Attributes\Post;
use IamAdty\Empira\Attributes\Prop;
use IamAdty\Empira\Component;
use IamAdty\Empira\Element;
use IamAdty\Empira\Http\Request;
use IamAdty\Empira\State\NullState;
use PHPUnit\Framework\TestCase;

/**
 * The conveniences a component gets for free: validation, flash, OOB and lazy.
 */
final class ComponentConveniencesTest extends TestCase
{
    private const SECRET = 'a-secret-that-is-long-enough';

    private function app(): Application
    {
        $app = new Application(self::SECRET, new NullState());
        $app->registry()->alias('guestbook', GuestbookFixture::class);

        return $app;
    }

    /**
     * @return array{url: string, body: string}
     */
    private function action(string $name, array $body = []): array
    {
        $app = $this->app();
        $html = (string) $app->render(GuestbookFixture::class)->render();

        preg_match('/hx-post="([^"]*' . preg_quote($name, '/') . ')"/', $html, $matches);

        $url = html_entity_decode($matches[1] ?? '', ENT_QUOTES);
        $this->assertNotSame('', $url, 'action ' . $name . ' should be wired');

        return ['url' => $url, 'body' => $app->handle(new Request(
            'POST',
            $url,
            body: $body,
            headers: ['HX-Request' => 'true']
        ))->body()];
    }

    private function response(string $name, array $body = [])
    {
        $app = $this->app();
        $html = (string) $app->render(GuestbookFixture::class)->render();

        preg_match('/hx-post="([^"]*' . preg_quote($name, '/') . ')"/', $html, $matches);

        return $app->handle(new Request(
            'POST',
            html_entity_decode($matches[1] ?? '', ENT_QUOTES),
            body: $body,
            headers: ['HX-Request' => 'true']
        ));
    }

    // ---------------------------------------------------------------- validation

    public function testValidInputIsAccepted(): void
    {
        ['body' => $body] = $this->action('sign', ['name' => 'Ada']);

        $this->assertStringContainsString('Ada', $body);
    }

    public function testInvalidInputFiresAnEventAndIsNotStored(): void
    {
        $response = $this->response('sign', ['name' => '']);

        $this->assertStringNotContainsString('Ada', $response->body());

        $events = json_decode($response->headers()['HX-Trigger-After-Swap'] ?? '{}', true);

        $key = $this->invalidEventKey($events);

        $this->assertNotNull($key, 'a rejected action should fire an invalid event');
        $this->assertArrayHasKey('name', $events[$key]);
    }

    public function testValidationMessagesAreAttachedToTheRightField(): void
    {
        $response = $this->response('sign', ['name' => '']);

        $events = json_decode($response->headers()['HX-Trigger-After-Swap'], true);
        $key = $this->invalidEventKey($events);

        $this->assertArrayHasKey('name', $events[$key]);
        $this->assertNotEmpty($events[$key]['name']);
    }

    public function testValidationIsSkippedWhenRulesPass(): void
    {
        $response = $this->response('sign', ['name' => 'Grace']);

        $this->assertSame('', $response->headers()['HX-Trigger-After-Swap'] ?? '');
    }

    /**
     * The event is namespaced per component instance, so the key is matched by
     * its suffix rather than assumed.
     *
     * @param array<string, mixed> $events
     */
    private function invalidEventKey(array $events): ?string
    {
        foreach (array_keys($events) as $key) {
            if (str_ends_with($key, ':invalid')) {
                return $key;
            }
        }

        return null;
    }

    // ---------------------------------------------------------------- out of band

    public function testAnOutOfBandElementIsMarkedForSwap(): void
    {
        ['body' => $body] = $this->action('sign', ['name' => 'Ada']);

        $this->assertStringContainsString('hx-swap-oob="true"', $body);
    }

    // ---------------------------------------------------------------- lazy

    public function testALazyRegionPointsAtItsOwnEndpoint(): void
    {
        $app = $this->app();
        $html = (string) $app->render(LazyFixture::class)->render();

        $this->assertMatchesRegularExpression('/hx-get="[^"]*\/load"/', $html);
        $this->assertMatchesRegularExpression('/hx-trigger="load"/', $html);
    }
}

/**
 * A component that validates, flashes and swaps out of band.
 */
final class GuestbookFixture extends Component
{
    #[Prop] public array $entries = [];

    #[Post]
    public function sign(): void
    {
        if ($this->validate(['name' => 'required|max:50'])->any()) {
            return;
        }

        $this->entries[] = $this->request()->string('name');
        $this->flash('notice', 'Signed.');

        $this->response()->pushUrl('/guestbook');
    }

    protected function render(): Element
    {
        return $this->hx->div()
            ->attr('data-entries', implode(',', $this->entries))
            ->append(
                $this->outOfBand($this->hx->span()->class('count')->text((string) count($this->entries))),
                $this->hx->input()->attr('name', 'name'),
                $this->hx->button('sign')->action('sign'),
                $this->hx->ul()->append(...array_map(
                    fn(string $entry): Element => $this->hx->li()->text($entry),
                    $this->entries
                ))
            );
    }
}

/**
 * A component whose content is fetched after the page settles.
 */
final class LazyFixture extends Component
{
    #[Get]
    public function load(): void
    {
    }

    protected function render(): Element
    {
        return $this->lazy('load');
    }
}