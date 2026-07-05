# Hooks (Event System)

Yantra provides a WordPress-like hooks system with two primitives: **actions** (fire-and-forget events — "something happened, react to it") and **filters** (data transformation pipelines — "here is a value, modify it and pass it on"). Hooks are registered with priorities, can be given names for later removal, and are available both through the static `System\Hooks` facade and through global helper functions.

```php
use System\Hooks;

// Action: notify listeners
Hooks::add_action('user.registered', function (User $user) {
    Logger::info("New user: {$user->email}");
});
Hooks::do_action('user.registered', $user);

// Filter: transform a value
Hooks::add_filter('post.title', fn (string $title) => ucfirst($title));
$title = Hooks::apply_filter('post.title', $rawTitle);
```

## Actions vs Filters

| | Actions | Filters |
|---|---|---|
| Purpose | Side effects (logging, emails, cache busting) | Transforming a value through a pipeline |
| Register | `add_action($hook, $cb, $priority, $name, $accepted_args)` | `add_filter($hook, $cb, $priority, $name, $accepted_args)` |
| Trigger | `do_action($hook, ...$args)` — returns nothing | `apply_filter($hook, $value, ...$args)` — returns the filtered value |
| Callback return | Ignored | Becomes the `$value` passed to the next callback |

## Actions

```php
use System\Hooks;

Hooks::add_action('user.registered', function (User $user) {
    $mail->sendWelcome($user);
}, priority: 10);

Hooks::add_action('user.registered', function (User $user) {
    Logger::info("New user: {$user->email}");
}, priority: 20);

// All listeners run, lowest priority number first
Hooks::do_action('user.registered', $user);
```

## Filters

Each callback receives the current value and returns the (possibly modified) value, which flows into the next callback:

```php
Hooks::add_filter('post.title', fn (string $title) => ucfirst($title));
Hooks::add_filter('post.title', fn (string $title) => strip_tags($title));

$title = Hooks::apply_filter('post.title', $rawTitle);
```

## Priorities

Both `add_action` and `add_filter` accept an integer `$priority` (default `10`). Callbacks execute in ascending priority order — priority `5` runs before priority `10`, which runs before priority `20`. Callbacks registered at the same priority run in registration order.

## The `accepted_args` Parameter

By default a callback receives **one** argument. If you fire a hook with extra arguments, raise `accepted_args`:

```php
Hooks::add_action('order.shipped', function (Order $order, Carrier $carrier) {
    // needs both arguments
}, priority: 10, name: null, accepted_args: 2);

Hooks::do_action('order.shipped', $order, $carrier);
```

For filters, the value being filtered counts as the **first** argument:

```php
Hooks::add_filter('price.display', function (float $price, string $currency) {
    return number_format($price, 2) . ' ' . $currency;
}, accepted_args: 2);

$display = Hooks::apply_filter('price.display', 19.5, 'EUR');
```

Setting `accepted_args: 0` invokes the callback with no arguments at all.

## Named Hooks & Removal

Pass a `name` when registering to be able to remove (or replace) the callback later:

```php
Hooks::add_action('boot', $callback, priority: 10, name: 'setup_database');

Hooks::remove_action_by_name('boot', 'setup_database');       // returns bool
Hooks::remove_filter_by_name('post.title', 'strip_tags_filter');
```

Registering a second callback with the **same name and priority** on the same hook replaces the first — useful for overriding default behavior.

## Introspection & Reset

```php
Hooks::has_action('user.registered');  // bool — any listener registered?
Hooks::has_filter('post.title');       // bool

Hooks::reset();  // clear ALL hooks — essential for test isolation
```

Under the hood the static facade delegates to a shared `System\Hooks\HooksRepository` instance; `Hooks::getInstance()` returns it and `Hooks::setInstance($repo)` swaps it (used by application bootstrap and tests). New code can also inject `System\Contracts\HooksInterface` via the container instead of calling statics.

## Global Helper Functions

Global functions (defined in `src/System/functions.php`) mirror the facade:

```php
// Actions
add_action('user.login', fn ($user) => logLogin($user));
do_action('user.login', $user);

// Filters
add_filter('page.title', fn ($title) => "My App - {$title}");
$title = apply_filters('page.title', $title);

// apply_filter (singular) is a backward-compatible alias of apply_filters
$title = apply_filter('page.title', $title);
```

All helpers accept the same `$priority`, `$name`, and `$accepted_args` parameters as the facade methods.

::: warning Gotchas
- Extra arguments beyond `accepted_args` are silently dropped — if a listener seems to receive `null`s or too few arguments, check its `accepted_args`.
- A filter callback **must return** a value; returning nothing (`null`) makes `null` the value for the rest of the pipeline.
- Named registrations are keyed per priority: the same name at two *different* priorities creates two entries, and `remove_*_by_name` removes named entries matching that name on the hook.
- Call `Hooks::reset()` in test teardown — the shared repository persists across tests otherwise.
:::

## Related

- [Hooks API reference](/api/hooks)
- [Application lifecycle](/guide/lifecycle) — where framework hooks fire during a request
- [Helpers](/features/helpers) — other global helper functions
- [Webhooks](/features/webhooks) — outbound HTTP event delivery
