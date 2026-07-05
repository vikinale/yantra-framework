# Hooks

`System\Hooks`

`Hooks` is a static facade over a shared `System\Hooks\HooksRepository` that implements a WordPress-style **actions and filters** system. Actions are fire-and-forget events (`do_action`); filters pipe a value through a chain of callbacks and return the result (`apply_filter`). Both support integer **priorities** (lower runs first, default `10`), an optional unique **name** for later removal/replacement, and an `accepted_args` count controlling how many arguments each callback receives. Most applications call the global [`add_action()` / `do_action()` / `add_filter()` / `apply_filters()` helpers](/api/helpers) rather than the class directly.

```php
use System\Hooks;

Hooks::add_action('user.registered', fn($user) => Mailer::welcome($user));
Hooks::do_action('user.registered', $user);

Hooks::add_filter('post.title', fn($t) => strtoupper($t));
$title = Hooks::apply_filter('post.title', $title);   // note: singular
```

## Methods

### Instance management

| Method | Returns | Description |
| --- | --- | --- |
| `getInstance()` | `HooksRepository` | Get (lazily creating) the shared backing repository. |
| `setInstance(HooksInterface $repo)` | `void` | Wire a specific `HooksRepository` (bootstrap / tests). Throws `InvalidArgumentException` if not a `HooksRepository`. |
| `reset()` | `void` | Clear all registered hooks and drop the shared instance. Intended for test isolation. |

### Actions

| Method | Returns | Description |
| --- | --- | --- |
| `add_action(string $hook, callable $callback, int $priority = 10, ?string $name = null, int $accepted_args = 1)` | `void` | Register an action listener. `$name` lets you remove/replace it later; `$accepted_args` caps how many `do_action` args reach the callback. |
| `do_action(string $hook, mixed ...$args)` | `void` | Fire every listener for `$hook`, in priority order, passing `$args`. |
| `remove_action_by_name(string $hook, string $name)` | `bool` | Remove a named action. Returns `true` if one was removed. |
| `has_action(string $hook)` | `bool` | Whether any action is registered for `$hook`. |

### Filters

| Method | Returns | Description |
| --- | --- | --- |
| `add_filter(string $hook, callable $callback, int $priority = 10, ?string $name = null, int $accepted_args = 1)` | `void` | Register a value filter. The first argument the callback receives is the value being filtered. |
| `apply_filter(string $hook, mixed $value, mixed ...$args)` | `mixed` | Pass `$value` through every registered filter (priority order) and return the final result. **Singular** name on the class. |
| `remove_filter_by_name(string $hook, string $name)` | `bool` | Remove a named filter. Returns `true` if one was removed. |
| `has_filter(string $hook)` | `bool` | Whether any filter is registered for `$hook`. |

## Global helper functions

Defined in `src/System/functions.php`, each delegating to the matching `Hooks` method. See the [Helpers API Reference](/api/helpers#hooks) for the full table.

| Function | Delegates to | Note |
| --- | --- | --- |
| `add_action(...)` | `Hooks::add_action()` | Same signature. |
| `do_action(string $hook, mixed ...$args)` | `Hooks::do_action()` | — |
| `add_filter(...)` | `Hooks::add_filter()` | Same signature. |
| `apply_filters(string $hook, mixed $value, mixed ...$args)` | `Hooks::apply_filter()` | **Plural** helper name — the canonical global function. |
| `apply_filter(string $hook, mixed $value, mixed ...$args)` | `apply_filters()` | Backward-compatible **singular** alias. |

## Examples

### Priorities and accepted args

```php
Hooks::add_filter('price', fn($p) => $p * 1.2, priority: 20);   // runs second
Hooks::add_filter('price', fn($p) => $p - 5, priority: 10);     // runs first

$final = Hooks::apply_filter('price', 100);   // (100 - 5) * 1.2 = 114
```

By default a filter callback receives only the value (`accepted_args = 1`). To receive extra context passed to `apply_filter`, raise `accepted_args`:

```php
Hooks::add_filter('greeting', fn($msg, $user) => "$msg, {$user->name}!", accepted_args: 2);
echo Hooks::apply_filter('greeting', 'Hello', $user);   // "Hello, Asha!"
```

### Named registration and removal

```php
Hooks::add_action('boot', $handler, name: 'analytics');
// ...later, e.g. to disable in tests:
Hooks::remove_action_by_name('boot', 'analytics');   // true
```

A name also lets a plugin *replace* a previously registered callback by re-adding under the same name.

::: warning Gotchas
- **The class method is `apply_filter` (singular); the canonical global helper is `apply_filters` (plural).** The global `apply_filter()` exists only as a backward-compatible alias. Mixing them up is harmless (they resolve to the same code) but the naming trips people up.
- **`do_action` returns nothing.** Use a filter (`apply_filter`) when you need a value back — an action cannot mutate and return data to the caller.
- **`accepted_args` defaults to `1`.** Extra arguments passed to `do_action` / `apply_filter` are silently dropped unless you raise it.
- **Names are required for removal.** A callback registered without a `$name` cannot be removed by `remove_action_by_name` / `remove_filter_by_name`.
:::

## Related

- [Hooks guide](/features/hooks)
- [Helpers API Reference](/api/helpers)
