# Recipes

> **Status: describes shipped code.** Copy, paste, adapt.

Each recipe here has a matching agent skill in `.opencode/skills/`, so the same
guidance serves a person and an assistant.

---

## Add a component

```bash
php bin/empira make:component TodoList
```

Then edit the generated file:

```php
<?php

declare(strict_types=1);

namespace App\Components;

use IamAdty\Empira\Attributes\Delete;
use IamAdty\Empira\Attributes\Post;
use IamAdty\Empira\Attributes\Prop;
use IamAdty\Empira\Component;
use IamAdty\Empira\Element;

final class TodoList extends Component
{
    #[Prop] public array $items = [];
    #[Prop] public string $draft = '';

    public function actions(): array { return ['add', 'remove']; }

    #[Post] public function add(): void
    {
        $title = trim($this->request()->string('title'));

        if ($title === '') {
            return;
        }

        $this->items[] = ['id' => bin2hex(random_bytes(4)), 'title' => $title];
    }

    #[Delete] public function remove(string $id): void
    {
        $this->items = array_values(array_filter(
            $this->items,
            static fn(array $item): bool => $item['id'] !== $id
        ));
    }

    protected function render(): Element
    {
        return $this->hx->div('#todos')
            ->append(
                $this->hx->form()
                    ->action('add')
                    ->append(
                        $this->hx->input()->attr('name', 'title'),
                        $this->hx->button('Add'),
                    )
            )
            ->append(
                $this->hx->ul()->append(...array_map(
                    fn(array $item): Element => $this->hx->li()
                        ->text($item['title'])
                        ->append($this->hx->button('x')->action('remove', ['vals' => ['id' => $item['id']]])),
                    $this->items
                ))
            );
    }
}
```

**Register it.** A token cannot resolve an unregistered class:

```php
$app->registry()->alias('todos', TodoList::class);
```

**Then test it.** Render, follow the signed URL, assert on the fragment:

```php
$html = (string) $app->render(TodoList::class)->render();
preg_match('/hx-post="([^"]*add)"/', $html, $m);
$add = html_entity_decode($m[1], ENT_QUOTES);

$fragment = $app->handle(new Request('POST', $add, body: ['title' => 'walk dog']))->body();

$this->assertStringContainsString('walk dog', $fragment);
```

Gotchas: `render()` must return `Element|Raw` (a bare string gets escaped);
`->action()` takes an **action name**, not a URL; every action needs a verb.

---

## Add a route

The usual case — a controller method declares its own URL:

```php
final class TodoController extends Controller
{
    #[Route('/todos', name: 'todos.index')]
    public function index(): Response
    {
        return $this->view(TodoLayout::class);
    }

    #[Route('/todos/{id}', name: 'todos.show')]
    public function show(int $id): Response      // typed by the signature
    {
        return $this->view(TodoLayout::class, ['id' => $id]);
    }
}
```

Enable it once, in the application:

```php
$app->discover(__DIR__, __NAMESPACE__);   // src/
```

A page declares its URL the same way, on the layout itself:

```php
#[Route('/', name: 'home')]
final class HomeLayout extends Layout { /* … */ }
```

When a URL needs a typed parameter without a controller — a page, say — take it
in `mount()` instead:

```php
#[Route('/todos/{id}')]
final class TodoLayout extends Layout
{
    public function mount(TodoRepository $todos, int $id): void
    {
        $this->todo = $todos->findOrFail($id);
    }
}
```

Register by hand when it is not a page — an endpoint somebody else calls, a
group, or shared middleware:

```php
$app->registrar(Routing\AppRoutes::class);   // a RouteRegistrar
```

```php
$routes->group('/admin', function (RouteCollection $r): void {
    $r->get('/stats', [StatsController::class, 'show']);
}, middleware: [RequireAuth::class]);
```

**In a hand-written table, order matters:** `/todos/new` must be declared before
`/todos/{id}`. Discovered routes are sorted by specificity, so they do not.

---

## Add a page region

```php
protected function regions(): array
{
    return [
        'todos' => fn(Region $region): mixed => $region
            ->element('section')
            ->class('card')
            ->append(
                $region->hx()->h2('Todos'),
                $region->component(TodoList::class)
            ),
    ];
}
```

Every region gets `id="region-<name>"`, which is what htmx targets. A component
rendered inside one swaps in place.

Out-of-band — update somewhere else in the page with the same response:

```php
->append($region->oob($this->hx->span()->id('count')->text('3')))
```

Lazy — do not block the page on it:

```php
'analytics' => fn(Region $r) => $r->lazy('/analytics/panel'),
```

---

## Style a page

```php
protected function styles(): Element|Raw
{
    return Raw::of('body { font: 16px system-ui; }');   // wrapped in <style>
}
```

Return an `Element` to control the tag yourself:

```php
return $this->hx->link()->attr('rel', 'stylesheet')->attr('href', '/app.css');
```

Empty string → no element at all.

---

## Validate input in an action

```php
#[Post]
public function add(): void
{
    if ($this->validate(['title' => 'required|max:200'])->any()) {
        return;                       // re-renders; the bag fired an event
    }

    $this->items[] = $this->request()->string('title');
}
```

Rules: `required`, `nullable`, `string`, `integer`, `numeric`, `boolean`, `array`,
`email`, `min:N`, `max:N`, `in:a,b,c`, `regex:/…/`, `confirmed`, `same:field`.
An unknown rule throws — a typo cannot silently pass.

On failure an `HX-Trigger-After-Swap` event fires carrying the messages, so a
form can show them without the component knowing the page around it.

---

## Flash a message

```php
$this->flash('success', 'Saved.');
```

Read it in the layout:

```php
foreach ($app->session()->flashed() as $message) { … }
```

`flashed()` reads **and forgets** — a message is delivered once.

---

## Record what happened

The framework logs through [PSR-3](https://www.php-fig.org/psr/psr-3/), not
through an interface of its own. Hand it one during boot:

```php
$app->log(new Monolog\Logger('app'));
```

and anything else — a component action, a repository — can ask for the same one
by type, the way it asks for `Auth`:

```php
public function register(Auth $auth, LoggerInterface $log): void
{
    $log->info('registration', ['email' => $email]);
}
```

Until you hand one over, everything is discarded. That default is the point: a
framework that starts writing to a file nobody configured is a framework nobody
trusts in production.

The framework itself logs one thing: **an exception that ended a request**. A
bug is re-thrown rather than dressed up as a tidy 500, and the re-throw goes
wherever PHP has been told to put errors — which is nowhere, by default, in most
production configurations. So the exception is recorded *before* it is re-thrown,
with the method and path attached, and a 404 or a validation failure is not
recorded at all, because a client making a mistake is not an incident.

---

## Add a console command

The framework's commands are its own. Yours go in the application, which
registers them during boot:

```php
// src/Console/Seed.php
final class Seed implements \IamAdty\Empira\Console\Command
{
    public function __construct(private readonly UserRepository $users) {}

    public function name(): string { return 'seed'; }
    public function description(): string { return 'Put the demo account in.'; }
    public function arguments(): array { return ['[--email=address]']; }

    public function handle(array $options, Output $output): int
    {
        $this->users->seed((string) ($options['email'] ?? 'ada@example.com'));
        $output->line('  seeded');

        return 0;
    }
}
```

```php
$app->command('seed', new Seed($app->container()->get(UserRepository::class)));
```

For the console to find that, it needs a bootstrap it can run:

```php
// bootstrap/app.php
require __DIR__ . '/../vendor/autoload.php';

return App::boot();
```

`bin/empira` looks for `bootstrap/app.php` in the working directory, or wherever
`EMPIRA_BOOTSTRAP` points. It is **optional** — `make:component` and `route:list`
work in a checkout with no application at all.

Project commands are marked `(project)` in `list`, and cannot take the name of a
framework command. Two `migrate`s where the winner depends on registration order
is not a surprise a console should have.

---

## Verify before you commit

```bash
composer test
find src tests -name '*.php' -print0 | xargs -0 -n1 php -l
```

For a framework change, also boot it:

```bash
php -S 127.0.0.1:8000 -t examples/public examples/public/index.php &
curl -s localhost:8000 | head -20
```

Then prove the test can fail:

```bash
git stash && vendor/bin/phpunit tests/YourTest.php   # red
git stash pop && vendor/bin/phpunit tests/YourTest.php   # green
```

---

## Further reading

- [components.md](components.md) — the component API in full
- [routing.md](routing.md) — the routing API
- [views.md](views.md) — layouts and regions
- [testing.md](testing.md) — testing conventions
- [../SECURITY.md](../SECURITY.md) — before touching anything security-adjacent
- [../AGENTS.md](../AGENTS.md) — known traps