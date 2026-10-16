<?php

declare(strict_types=1);

namespace Empira\Tests;

use Empira\Http\Request;
use Empira\Http\Response;
use PHPUnit\Framework\TestCase;

final class HttpTest extends TestCase
{
    // ---------------------------------------------------------------- request

    public function testHtmxRequestHeadersAreExposed(): void
    {
        $request = new Request('GET', '/frag', headers: [
            'HX-Request' => 'true',
            'HX-Boosted' => 'true',
            'HX-Target' => 'list',
            'HX-Trigger' => 'btn-1',
            'HX-Trigger-Name' => 'refresh',
            'HX-Current-URL' => 'https://example.test/dash',
            'HX-Prompt' => 'yes',
            'HX-History-Restore-Request' => 'true',
        ]);

        $this->assertTrue($request->isHtmx());
        $this->assertTrue($request->isBoosted());
        $this->assertTrue($request->isHistoryRestore());
        $this->assertSame('list', $request->target());
        $this->assertSame('btn-1', $request->trigger());
        $this->assertSame('refresh', $request->triggerName());
        $this->assertSame('https://example.test/dash', $request->currentUrl());
        $this->assertSame('yes', $request->prompt());
    }

    public function testHeaderLookupIsCaseInsensitive(): void
    {
        $request = new Request('GET', '/', headers: ['HX-Request' => 'true']);

        $this->assertSame('true', $request->header('hx-REQUEST'));
        $this->assertFalse($request->isBoosted());
        $this->assertNull($request->target());
    }

    public function testInputPrefersTheBodyAndFallsBackToTheQueryString(): void
    {
        $request = new Request('POST', '/', query: ['only' => 'query'], body: ['both' => 'body']);

        $this->assertSame('body', $request->input('both'));
        $this->assertSame('query', $request->input('only'));
        $this->assertSame('default', $request->input('missing', 'default'));
    }

    public function testTypedInputCoercion(): void
    {
        $request = new Request('POST', '/', ['n' => '42', 'flag' => 'on', 'bad' => 'abc']);

        $this->assertSame(42, $request->int('n'));
        $this->assertTrue($request->bool('flag'));
        $this->assertSame(7, $request->int('missing', 7));
        $this->assertSame(0, $request->int('bad'));
        $this->assertSame('', $request->string('missing'));
    }

    public function testPathStripsTheQueryString(): void
    {
        $this->assertSame('/a/b', (new Request('GET', '/a/b?x=1#frag'))->path());
    }

    // ------------------------------------------------------- client address

    public function testThereIsNoClientAddressWhenTheRequestCarriesNone(): void
    {
        // The CLI has no REMOTE_ADDR, and that is the common case in the suite.
        // Returning null rather than a guess is the point: a wrong address in a
        // log is believed later.
        $this->assertNull((new Request('GET', '/'))->ip());
    }

    public function testTheSocketAddressIsUsedWhenThereIsOne(): void
    {
        $request = new Request('GET', '/', remoteAddress: '203.0.113.9');

        $this->assertSame('203.0.113.9', $request->ip());
    }

    public function testForwardedHeadersAreIgnoredUnlessProxiesAreTrusted(): void
    {
        // The security test. With nothing in front of the application,
        // X-Forwarded-For is a value the client chose, and a rate limiter built
        // on it would be evaded by rotating a header.
        $request = new Request(
            'GET',
            '/',
            headers: ['x-forwarded-for' => '198.51.100.7'],
            remoteAddress: '203.0.113.9',
        );

        $this->assertSame('203.0.113.9', $request->ip());
    }

    public function testForwardedHeadersAreUsedWhenProxiesAreTrusted(): void
    {
        $request = new Request(
            'GET',
            '/',
            headers: ['x-forwarded-for' => '198.51.100.7'],
            remoteAddress: '10.0.0.1',
            trustProxy: true,
        );

        $this->assertSame('198.51.100.7', $request->ip());
    }

    public function testTheClientIsTheHeadOfAForwardedChain(): void
    {
        // "client, proxy1, proxy2" — oldest first, so the client leads. Taking
        // the tail would record the nearest proxy every time.
        $request = new Request(
            'GET',
            '/',
            headers: ['x-forwarded-for' => ' 198.51.100.7 , 10.0.0.1 , 10.0.0.2'],
            remoteAddress: '10.0.0.1',
            trustProxy: true,
        );

        $this->assertSame('198.51.100.7', $request->ip());
    }

    public function testARealIpHeaderIsUsedWhenThereIsNoForwardedChain(): void
    {
        $request = new Request(
            'GET',
            '/',
            headers: ['x-real-ip' => '198.51.100.7'],
            remoteAddress: '10.0.0.1',
            trustProxy: true,
        );

        $this->assertSame('198.51.100.7', $request->ip());
    }

    public function testAMalformedForwardedHeaderFallsBackToTheSocket(): void
    {
        // "not-an-ip" must not reach a log. Returning the socket address is the
        // honest answer; returning nothing would hide a real request.
        $request = new Request(
            'GET',
            '/',
            headers: ['x-forwarded-for' => 'not-an-ip'],
            remoteAddress: '203.0.113.9',
            trustProxy: true,
        );

        $this->assertSame('203.0.113.9', $request->ip());
    }

    public function testAUnixSocketRemoteAddressIsNotAnAddress(): void
    {
        // php-fpm over a unix socket puts a filesystem path in REMOTE_ADDR.
        $request = new Request('GET', '/', remoteAddress: '/var/run/php-fpm.sock');

        $this->assertNull($request->ip());
    }

    public function testIpv6IsAnAddress(): void
    {
        $request = new Request('GET', '/', remoteAddress: '2001:db8::1');

        $this->assertSame('2001:db8::1', $request->ip());
    }

    public function testCaptureReadsTheSocketAddressAndOnlyTrustsProxiesWhenAsked(): void
    {
        // `capture()` rather than the constructor, because this is the function
        // `Application` threads its setting into — every address the framework
        // reports in production is decided by that argument arriving correctly.
        $previousAddress = $_SERVER['REMOTE_ADDR'] ?? null;
        $_SERVER['REMOTE_ADDR'] = '10.0.0.1';
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.7';

        try {
            $this->assertSame('10.0.0.1', Request::capture()->ip());
            $this->assertSame('198.51.100.7', Request::capture(true)->ip());
        } finally {
            unset($_SERVER['HTTP_X_FORWARDED_FOR']);

            if ($previousAddress === null) {
                unset($_SERVER['REMOTE_ADDR']);
            } else {
                $_SERVER['REMOTE_ADDR'] = $previousAddress;
            }
        }
    }

    public function testCsrfTokenComesFromTheHeaderOrTheBody(): void
    {
        $fromHeader = new Request('POST', '/', headers: ['X-CSRF-Token' => 'abc']);
        $fromBody = new Request('POST', '/', ['_csrf' => 'abc']);

        $this->assertSame('abc', $fromHeader->csrfToken());
        $this->assertSame('abc', $fromBody->csrfToken());
    }

    // ---------------------------------------------------------------- response

    public function testHtmxResponseHeaders(): void
    {
        $response = new Response('', 200)
            ->pushUrl('/a')
            ->replaceUrl(false)
            ->redirect('/b')
            ->location(['path' => '/c', 'target' => '#main'])
            ->reswap('outerHTML')
            ->retarget('#elsewhere')
            ->reselect('#part')
            ->refresh();

        $headers = $response->headers();

        $this->assertSame('/a', $headers['HX-Push-Url']);
        $this->assertSame('false', $headers['HX-Replace-Url']);
        $this->assertSame('/b', $headers['HX-Redirect']);
        $this->assertSame('{"path":"/c","target":"#main"}', $headers['HX-Location']);
        $this->assertSame('outerHTML', $headers['HX-Reswap']);
        $this->assertSame('#elsewhere', $headers['HX-Retarget']);
        $this->assertSame('#part', $headers['HX-Reselect']);
        $this->assertSame('true', $headers['HX-Refresh']);
    }

    public function testTriggerHeadersAreMergedIntoOneJsonObject(): void
    {
        $response = (new Response())
            ->trigger('one')
            ->trigger('two', ['n' => 2])
            ->triggerAfterSwap('three')
            ->triggerAfterSettle('four', ['ok' => true]);

        $headers = $response->headers();

        $this->assertSame('{"one":null,"two":{"n":2}}', $headers['HX-Trigger']);
        $this->assertSame('{"three":null}', $headers['HX-Trigger-After-Swap']);
        $this->assertSame('{"four":{"ok":true}}', $headers['HX-Trigger-After-Settle']);
    }

    public function testTriggerDetailIsDistinguishableFromNoDetail(): void
    {
        $response = (new Response())->trigger('evt', ['a' => 1]);

        $this->assertSame('{"evt":{"a":1}}', $response->headers()['HX-Trigger']);
    }

    public function testStopPollingUsesStatus286(): void
    {
        // htmx halts polling when a response comes back with this status.
        $this->assertSame(286, Response::stopPolling()->status());
    }

    public function testHeaderInjectionIsStrippedFromComponentHeaderValues(): void
    {
        $response = (new Response())->retarget("#a\r\nX-Injected: 1");

        // A component handing us user-influenced input must not be able to
        // smuggle a second header out through a retarget selector.
        $this->assertSame('#aX-Injected: 1', $response->headers()['HX-Retarget']);
    }

    public function testBodyAndStatus(): void
    {
        $response = Response::html('<p>hi</p>', 201);

        $this->assertSame('<p>hi</p>', $response->body());
        $this->assertSame(201, $response->status());
    }

    public function testJsonResponseSetsTheContentType(): void
    {
        $response = Response::json(['ok' => true]);

        $this->assertSame('{"ok":true}', $response->body());
        $this->assertSame('application/json', $response->headers()['Content-Type']);
    }
}