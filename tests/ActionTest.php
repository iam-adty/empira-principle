<?php

declare(strict_types=1);

namespace IamAdty\Empira\Tests;

use IamAdty\Empira\Application;
use IamAdty\Empira\Attributes\Verb;
use IamAdty\Empira\Container\ContainerException;
use IamAdty\Empira\Http\Request;
use IamAdty\Empira\State\NullState;
use IamAdty\Empira\Tests\Fixtures\ActionOverrideFixture;
use IamAdty\Empira\Tests\Fixtures\Clock;
use IamAdty\Empira\Tests\Fixtures\ClockFixture;
use IamAdty\Empira\Tests\Fixtures\MixedActionsFixture;
use IamAdty\Empira\Tests\Fixtures\UnsafeAttributeFixture;
use IamAdty\Empira\Tests\Fixtures\VerbsFixture;
use PHPUnit\Framework\TestCase;

/**
 * `#[Action]` and the verb attributes: what is reachable, and over which method.
 */
final class ActionTest extends TestCase
{
    private const SECRET = 'a-secret-that-is-long-enough';

    private function app(): Application
    {
        $app = new Application(self::SECRET, new NullState());

        $app->registry()
            ->alias('verbs', VerbsFixture::class)
            ->alias('override', ActionOverrideFixture::class)
            ->alias('mixed', MixedActionsFixture::class)
            ->alias('unsafe', UnsafeAttributeFixture::class);

        return $app;
    }

    /**
     * @param class-string $class
     */
    private function page(string $class): string
    {
        return (string) $this->app()->render($class)->render();
    }

    /**
     * The URL for one action, decoded from the markup.
     */
    private function urlFor(string $html, string $verb, string $action): string
    {
        preg_match_all('/hx-' . $verb . '="([^"]+)"/', $html, $matches);

        foreach ($matches[1] ?? [] as $url) {
            if (str_ends_with(html_entity_decode($url), '/' . $action)) {
                return html_entity_decode($url);
            }
        }

        $this->fail(sprintf('No hx-%s URL ending in "/%s" was rendered.', $verb, $action));
    }

    /**
     * @return list<string>
     */
    private function verbsIn(string $html): array
    {
        preg_match_all('/hx-(get|post|put|patch|delete)="/', $html, $matches);

        return array_values(array_unique($matches[1] ?? []));
    }

    // ---------------------------------------------------------------- injection

    public function testAnActionReceivesWhatItAsksForByType(): void
    {
        $app = $this->app();
        $app->container()->instance(Clock::class, new Clock('morning'));
        $app->registry()->alias('clock', ClockFixture::class);

        $html = (string) $app->render(ClockFixture::class)->render();
        $url = $this->urlFor($html, 'post', 'greet');

        $response = $app->handle(new Request('POST', $url, headers: ['HX-Request' => 'true']));

        // The action asked for a service by type and was handed one, rather than
        // reaching into the container itself or taking it as a prop.
        $this->assertSame(200, $response->status());
        $this->assertStringContainsString('morning', $response->body());
    }

    public function testAnActionThatAsksForNothingIsUnaffected(): void
    {
        $app = $this->app();

        $html = (string) $app->render(VerbsFixture::class)->render();
        $url = $this->urlFor($html, 'post', 'create');

        $this->assertSame(200, $app->handle(new Request('POST', $url, headers: ['HX-Request' => 'true']))->status());
    }

    public function testAnUnresolvableParameterIsRefusedRatherThanIgnored(): void
    {
        $app = $this->app();
        $app->registry()->alias('clock', ClockFixture::class);

        $html = (string) $app->render(ClockFixture::class)->render();
        $url = $this->urlFor($html, 'post', 'speak');

        // Nothing is bound to Greeter, so the container cannot build one. Saying
        // so beats calling the action with an argument silently missing.
        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage('$greeter');

        $app->handle(new Request('POST', $url, headers: ['HX-Request' => 'true']));
    }

    public function testInjectionDoesNotWidenWhatMayBeInvoked(): void
    {
        $app = $this->app();
        $app->registry()->alias('clock', ClockFixture::class);

        $html = (string) $app->render(ClockFixture::class)->render();
        $url = str_replace('/greet', '/undeclared', $this->urlFor($html, 'post', 'greet'));

        // The token still only names the component, and the action map still
        // decides what may be called. Autowiring changed what a declared action
        // is handed, not what can be reached — a refused action is still a 403.
        $this->assertSame(403, $app->handle(new Request('POST', $url, headers: ['HX-Request' => 'true']))->status());
    }

    // ---------------------------------------------------------------- generated markup

    public function testEachVerbAttributeEmitsItsOwnHtmxAttribute(): void
    {
        $html = $this->page(VerbsFixture::class);

        $this->assertStringContainsString('hx-get="', $html);
        $this->assertStringContainsString('hx-post="', $html);
        $this->assertStringContainsString('hx-put="', $html);
        $this->assertStringContainsString('hx-patch="', $html);
        $this->assertStringContainsString('hx-delete="', $html);
    }

    public function testTheDeclaredVerbIsTheOneRendered(): void
    {
        $app = $this->app();
        $html = (string) $app->render(VerbsFixture::class)->render();

        // Each action's URL appears exactly once, under its own verb.
        foreach (['peek' => 'get', 'create' => 'post', 'replace' => 'put', 'amend' => 'patch', 'destroy' => 'delete'] as $action => $verb) {
            $this->assertNotEmpty(
                $this->urlFor($html, $verb, $action),
                sprintf('%s should be wired with hx-%s', $action, $verb)
            );
        }
    }

    public function testActionAloneMeansPost(): void
    {
        $html = $this->page(ActionOverrideFixture::class);

        $this->assertNotEmpty($this->urlFor($html, 'post', 'impliedPost'));
    }

    public function testActionCanOverrideItsVerb(): void
    {
        $html = $this->page(ActionOverrideFixture::class);

        $this->assertNotEmpty($this->urlFor($html, 'get', 'forcedGet'));

        // The override changed only the forced action, not its sibling.
        preg_match('/hx-post="[^"]*forcedGet"/', $html, $matches);
        $this->assertSame([], $matches);
    }

    // ---------------------------------------------------------------- dispatch

    public function testEachActionRunsOverItsOwnVerb(): void
    {
        $app = $this->app();
        $html = (string) $app->render(VerbsFixture::class)->render();

        foreach ([['peek', 'GET'], ['create', 'POST'], ['replace', 'PUT'], ['amend', 'PATCH'], ['destroy', 'DELETE']] as [$action, $verb]) {
            $url = $this->urlFor($html, strtolower($verb), $action);

            $response = $app->handle(new Request($verb, $url, headers: ['HX-Request' => 'true']));

            $this->assertSame(200, $response->status(), $action . ' over ' . $verb);
            $this->assertStringContainsString('data-calls="1"', $response->body());
        }
    }

    public function testTheWrongVerbIsRefusedWith405(): void
    {
        $app = $this->app();
        $html = (string) $app->render(VerbsFixture::class)->render();

        // `destroy` is #[Delete]; reach it over POST.
        $response = $app->handle(new Request(
            'POST',
            $this->urlFor($html, 'delete', 'destroy'),
            headers: ['HX-Request' => 'true']
        ));

        $this->assertSame(405, $response->status());
        $this->assertStringContainsString('DELETE', $response->headers()['Allow']);
    }

    public function testEveryVerbMismatchIsRefused(): void
    {
        $app = $this->app();
        $html = (string) $app->render(VerbsFixture::class)->render();

        $wrong = [
            ['peek', 'GET', 'POST'],
            ['create', 'POST', 'GET'],
            ['replace', 'PUT', 'PATCH'],
            ['amend', 'PATCH', 'PUT'],
            ['destroy', 'DELETE', 'POST'],
        ];

        foreach ($wrong as [$action, $declared, $used]) {
            $url = $this->urlFor($html, strtolower($declared), $action);

            $response = $app->handle(new Request($used, $url, headers: ['HX-Request' => 'true']));

            $this->assertSame(405, $response->status(), sprintf('%s should refuse %s', $action, $used));
        }
    }

    // ---------------------------------------------------------------- the allow-list

    public function testAnUnattributedMethodIsStillRefused(): void
    {
        $app = $this->app();
        $html = (string) $app->render(VerbsFixture::class)->render();

        $url = $this->urlFor($html, 'post', 'create');

        // Swap the action segment for one that was never declared.
        $forged = preg_replace('/\/create$/', '/render', $url);

        $response = $app->handle(new Request('POST', (string) $forged, headers: ['HX-Request' => 'true']));

        $this->assertSame(403, $response->status());
    }

    public function testAttributesCannotExposePrivateOrProtectedMethods(): void
    {
        $app = $this->app();

        $component = new UnsafeAttributeFixture($app->makeContext());

        $this->assertSame([], $component->actionMap());
    }

    public function testAttributesCannotExposeFrameworkInternals(): void
    {
        $app = $this->app();
        $html = (string) $app->render(VerbsFixture::class)->render();

        $url = $this->urlFor($html, 'post', 'create');

        foreach (['toHtml', 'render', 'with'] as $frameworkMethod) {
            $forged = preg_replace('/\/create$/', '/' . $frameworkMethod, $url);

            $response = $app->handle(new Request('POST', (string) $forged, headers: ['HX-Request' => 'true']));

            $this->assertSame(403, $response->status(), $frameworkMethod . ' must stay private');
        }
    }

    // ---------------------------------------------------------------- migration

    public function testAttributesAndTheActionsListAreUnioned(): void
    {
        $app = $this->app();
        $component = new MixedActionsFixture($app->makeContext());

        $map = $component->actionMap();

        // Declared in actions().
        $this->assertArrayHasKey('legacy', $map);
        $this->assertSame(Verb::Post, $map['legacy']);

        // Declared with an attribute.
        $this->assertSame(Verb::Delete, $map['viaAttribute']);

        // Declared both ways: the attribute decides the verb.
        $this->assertSame(Verb::Get, $map['shared']);
    }

    public function testAMixedComponentStillRendersAndDispatches(): void
    {
        $app = $this->app();
        $html = (string) $app->render(MixedActionsFixture::class)->render();

        $this->assertNotEmpty($this->urlFor($html, 'delete', 'viaAttribute'));
        $this->assertNotEmpty($this->urlFor($html, 'post', 'legacy'));
        $this->assertNotEmpty($this->urlFor($html, 'get', 'shared'));

        $response = $app->handle(new Request('GET', $this->urlFor($html, 'get', 'shared')));

        $this->assertSame(200, $response->status());
    }

    public function testVerbForDefaultsToPostForUnknownActions(): void
    {
        $app = $this->app();
        $component = new MixedActionsFixture($app->makeContext());

        $this->assertSame(Verb::Post, $component->verbFor('not-declared'));
    }
}