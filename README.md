# Empira

A tiny PHP component framework where htmx lives in PHP classes, not in HTML. A
component declares its state as ordinary typed properties and its behaviour as
ordinary methods; the framework signs the component's identity into the action
URL, generates every `hx-*` attribute, and refuses any request that does not
match what the class actually declared. No `hx-post="/some/magic/path"` is ever
written by hand, and the client can never reach an action — or a verb — the
server did not publish.

## Requirements

PHP 8.4 or newer. No extensions are required. `Empira\Data\Connection` is a thin
PDO wrapper and additionally needs PDO plus a driver for your database.

## Installation

```bash
composer require iam-adty/empira-principle
```

## A component

State is a property. Behaviour is a method. That is the whole model.

```php
<?php

declare(strict_types=1);

namespace App\Components;

use Empira\Attributes\Post;
use Empira\Attributes\Prop;
use Empira\Component;
use Empira\Element;

final class Counter extends Component
{
    #[Prop]
    public int $count = 0;

    #[Post]
    public function increment(): void
    {
        $this->count++;
    }

    protected function render(): Element
    {
        return $this->hx->div()
            ->class('counter')
            ->append($this->hx->button('+')->action('increment'))
            ->append($this->hx->strong()->text((string) $this->count));
    }
}
```

`->action('increment')` is the only htmx in that class, and it is not an
attribute — it asks the component for the endpoint. What the framework renders:

```html
<div class="counter" id="e-84b3847a8ec2">
    <button hx-post="/empira/eyJ2IjoxLCJjIjoiY291bnRlciIsIm4iOiI3MGQxZGEyNzcyZDcxZTUxIiwicCI6eyJjb3VudCI6MH19.hUuQmWZFcKR2jvhQUYDAsZ7QtejdaskYpsSgi377KQc/increment"
            hx-swap="outerHTML" hx-target="#e-84b3847a8ec2">+</button>
    <strong>0</strong>
</div>
```

The target is the component's own root element, so the swap always lands
somewhere that exists. The token is `base64url(payload).base64url(hmac)`: it
carries the component, an instance nonce and the props, signed with your secret.
The count comes back from the client on the next click and is verified against
the signature, so editing a value in the URL fails the check rather than
promoting the value.

Use `#[Get]`, `#[Post]`, `#[Put]`, `#[Patch]` or `#[Delete]` to declare a verb.
The attribute decides both the generated `hx-*` attribute and the HTTP method
the router will accept, so the two cannot drift apart. Listing names in
`actions()` is still supported and means POST; the two styles may be mixed.

Props travel in the URL by default. Override `stateful()` to keep them on the
server in a `StateStore` (`SessionState` is one) instead — worth it when they
are large, sensitive, or simply too long for a URL.

## Wiring an application

```php
<?php

// app.php
declare(strict_types=1);

use App\Components\Counter;
use Empira\Application;
use Empira\Http\Request;

$app = new Application($_ENV['APP_SECRET']);

// Only registered classes can ever be resolved from a token.
$app->registry()->alias('counter', Counter::class);

// Anything that is not a component action.
$app->fallback(fn(Request $request): string => '<h1>Not found</h1>');

return $app;
```

```php
<?php

// public/index.php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

(require __DIR__ . '/../app.php')->send();
```

The secret must be at least 16 bytes; the token codec refuses anything
shorter. Action URLs live under `/empira` by default — that is the fourth
constructor argument if you need them somewhere else.

Render a component into a page with `$app->render(Counter::class)`. It returns
markup rather than a string, so it can be appended into a parent element
without being escaped.

## Routing

Routes are for the pages around your components. Declare them on the
application's route table; they are matched in declaration order, so a specific
pattern declared above a general one just wins.

```php
use App\Http\TodoController;
use Empira\Http\Response;

$app->routes()->get('/todos/new', fn(): Response => Response::html('new'), 'todos.new');
$app->routes()->get('/todos/{id}', [TodoController::class, 'show'], 'todos.show', defaults: ['id' => 0]);
$app->routes()->post('/todos', [TodoController::class, 'store']);
$app->routes()->get('/search', [TodoController::class, 'search'], 'todos.search');
```

- Parameters are typed by their defaults, so `defaults: ['id' => 0]` makes
  `show(int $id)` honest without a cast at every call site.
- Write a segment as `{page?}` to make it optional, or `{id:[0-9]+}` to
  constrain it.
- A handler may be a closure, `[Controller::class, 'method']`, or a bare
  class-string when the controller is invokable. Dependencies are autowired, so
  a handler can type-hint `Request`, `Response` or anything else the container
  holds.
- Controllers extend `Empira\Routing\Controller`, which gives you `view()`,
  `redirect()`, `json()`, `abort()` and `url()`.
- An unmatched path is a 404; a path that matches with the wrong method is a
  405 with an `Allow` header.
- Component action URLs are matched before any route, so both kinds of traffic
  share one endpoint without stepping on each other.

### Named routes

```php
$app->url()->to('todos.show', ['id' => 7]);       // '/todos/7'
$app->url()->to('todos.search', ['q' => 'milk']); // '/search?q=milk'
```

Parameters that are not in the pattern become the query string, which is what
makes building a filtered list a one-liner.

### Groups

```php
$app->routes()->group('/api', function (RouteCollection $routes): void {
    $routes->get('/users', [UserController::class, 'index']);
}, middleware: [RequireAuth::class]);
```

Groups nest, and share a prefix, parameter defaults and middleware.

## Middleware

```php
use Closure;
use Empira\Http\Middleware;
use Empira\Http\Request;
use Empira\Http\Response;

final class RequireHtmx implements Middleware
{
    public function handle(Request $request, Closure $next): Response
    {
        return $request->isHtmx()
            ? $next($request)
            : Response::html('htmx only')->setStatus(406);
    }
}
```

`$app->use(RequireHtmx::class)` wraps every request. Middleware passed to
`group()` applies to that group only. Layers are onion-shaped, so calling
`$next($request)` and then touching the response on the way back out is enough
to measure a request or add a header to it.

## CSRF

htmx does not submit a CSRF field with a bare `hx-post` on a `<div>`, so the
page's token is forwarded as a header, once, on `<body>` — where `hx-headers`
is inherited by every element below it.

```php
$csrf = new Empira\Security\Csrf();

$app = new Application($_ENV['APP_SECRET'], csrf: $csrf);
```

```html
<body hx-headers='{"X-CSRF-Token":"<?= $csrf->token() ?>"}'>
```

`$csrf->headersAttribute()` returns that attribute value ready-made, which is
what `hx()->body()->attr('hx-headers', ...)` wants when the page is built with
elements rather than a template.

Every component action verifies the token before it dispatches, whatever verb
the action declares. A missing or wrong token is a 403.

## Testing

`Empira\Testing\TestClient` drives an application the way a browser would, so a
test can make a request in one line and assert in the next.

```php
use Empira\Testing\TestClient;

$client = new TestClient($app);

$client->get('/todos')->request()
    ->assertOk()
    ->assertSee('Buy milk')
    ->assertDontSee('Buy bread');

$client->post('/todos', ['title' => 'Buy bread'])->request()->assertOk();
$client->get('/missing')->request()->assertNotFound();
```

`get()`, `post()`, `put()`, `patch()` and `delete()` stage a request and return
the client; `request()` sends it and returns a `TestResponse`. Query strings go
in the URI, and `withHeader()` or `asHtmx()` add headers to the next request.

Component actions are reached by URL, so render the component first and pull the
endpoint out of its markup:

```php
preg_match('/hx-post="([^"]*increment)"/', $page->body(), $matches);

$client->post(html_entity_decode($matches[1], ENT_QUOTES))->request()
    ->assertOk()
    ->assertSee('<strong>1</strong>');
```

The response exposes `status()`, `body()`, `header()` and `headers()`, plus
`assertOk()`, `assertNotFound()`, `assertForbidden()`, `assertStatus()`,
`assertSee()`, `assertDontSee()` and `assertHeader()`. Assertions return the
response, so they chain.

When the application was built with a CSRF guard, a valid token is attached to
every outgoing request automatically — a test can post an action without
restating the header the real page forwards. A token you set yourself is never
overwritten, which is how you test that a bad one is rejected.

## Running the tests

```bash
composer install
composer test
```

The console comes with the package:

```bash
vendor/bin/empira list
vendor/bin/empira make:component Counter
```

A worked application lives in [`../app`](../app) — start there if you would
rather read a real app than a reference component.

## License

MIT.