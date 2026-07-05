# Themes

Yantra's theming layer (`System\Theme`) lets you package views into swappable theme folders under `BASEPATH/themes/`, with parent-theme inheritance and safe, isolated PHP template rendering. Theming is **opt-in**: it is disabled unless `app.theme.enabled` is `true`, and normal apps rendering from `app/Views` never touch it. The entry point is the `ThemeManager` singleton, which reads the active theme from config, resolves view names through the theme (and its parents), and renders them.

```php
use System\Theme\ThemeManager;

$tm = ThemeManager::instance();

if ($tm->hasView('home')) {
    $html = $tm->render('home', ['title' => 'Welcome'], layout: 'index');
}
```

## Enabling theming

Two config keys under `app.theme` control everything (read once in the `ThemeManager` constructor):

```php
// config/app.php
return [
    // ...
    'theme' => [
        'enabled' => true,      // opt in — default is false
        'active'  => 'default', // slug = folder name under BASEPATH/themes/
    ],
];
```

There is no runtime `setTheme()` — the active theme is fixed by configuration. To switch themes, change `app.theme.active`. `boot()` throws a `RuntimeException` if theming is enabled but the active slug is empty or the theme folder is not installed.

## Directory layout and manifest

Themes live in `BASEPATH/themes/<slug>/`. A directory only counts as an installed theme when it contains a `theme.json` manifest — folders without one are skipped as "not installed". The folder name is the canonical slug.

```
themes/
├── default/
│   ├── theme.json
│   ├── index.php        <- layout view "index"
│   ├── home.php         <- view "home"
│   └── partials/
│       └── nav.php      <- view "partials/nav"
└── dark/
    ├── theme.json       <- { "parent": "default" }
    └── home.php         <- overrides default's home.php
```

`theme.json` must be a valid JSON object. The only key the loader (`ThemeRegistry`) currently reads is `parent`:

```json
{
    "parent": "default"
}
```

`parent` (optional string) names another installed theme's slug. On load the registry validates that every parent exists and that the parent chain contains no cycles — either problem throws a `RuntimeException`.

View files are plain `.php` templates resolved **relative to the theme folder root** — the view name `partials/nav` maps to `themes/<slug>/partials/nav.php`. View names are normalized to strip `..` so they cannot escape the theme directory.

## ThemeManager

`ThemeManager` is a singleton — construct it via `ThemeManager::instance()`.

| Method | Behavior |
| --- | --- |
| `instance(): self` | Returns the shared instance (created on first call). Requires the `BASEPATH` constant to be defined. |
| `boot(): void` | Idempotent. No-op when theming is disabled; otherwise loads the registry and pins the active theme. Called lazily by the methods below. |
| `resolve(string $view): string` | Returns the absolute file path for a view, searching the active theme first, then each parent up the chain. Throws `RuntimeException` if theming is disabled or the view is missing. |
| `render(string $view, array $data = [], ?string $layout = null): string` | Resolves and evaluates the view; when `$layout` is given, the layout is resolved the same way and receives the view's output as `$content` (plus all of `$data`). |
| `hasView(string $view): bool` | `true` when the view exists in the active theme or a parent. Returns `false` (never throws) when theming is disabled — this makes it a safe feature probe. |

There is no `listThemes()` on the manager. Enumerating installed themes is the registry's job:

```php
use System\Theme\ThemeRegistry;

$registry = new ThemeRegistry(base_path('themes'));
$registry->load();

$registry->all();          // array<string, Theme> keyed by slug
$registry->has('dark');    // bool
$registry->get('dark');    // Theme (name, rootPath, parent) — throws if missing
```

### View shadowing (child overrides parent)

`resolve()` walks the parent chain: the active theme's folder is searched first, then its parent, then the grandparent, and so on. A child theme therefore only needs to contain the files it overrides — everything else falls through to the parent. In the layout above, the `dark` theme overrides `home` but inherits `index` and `partials/nav` from `default`.

Theme rendering is separate from the app's default `ViewRenderer` (which serves `app/Views`). The framework itself uses the probe-then-render pattern — for example `BaseController::notfound()` checks `ThemeManager::instance()->hasView('404')` and renders the themed 404 page when the theme provides one, falling back to a plain response otherwise. To make theme views shadow *app* views wholesale, the app's `ViewRenderer` supports path stacking: `prependPath()` puts a theme directory ahead of `app/Views` in the lookup order, and `withPaths()` returns a clone with an entirely new path stack, so the first path containing the file wins.

### Rendering behavior

Templates are evaluated in an isolated closure scope (`$this` and manager internals do not leak in), and `$data` keys are `extract()`ed as local variables. Warnings and notices inside a template are converted to `ErrorException` so failures point at the exact template line. Every render error is logged via `error_log()`; when the app is in debug mode (the `APP_DEBUG` constant, or `app.debug` config, or `app.env === 'development'`) the original throwable is rethrown so the error page shows the real file and line — otherwise a wrapping `RuntimeException` is thrown with the original as its previous exception.

## Path and URL helpers

Two global helpers work off `app.theme.active` (falling back to `'default'`), independently of whether the manager has booted:

```php
theme_path();               // {BASEPATH}/themes/default
theme_path('assets/app.css'); // {BASEPATH}/themes/default/assets/app.css   (filesystem)

theme_url();                // {base_url}/themes/default
theme_url('assets/app.css');  // {base_url}/themes/default/assets/app.css  (web URL)
```

`theme_url()` assumes theme folders are web-servable under `/themes/{active}/...`. For non-theme public assets, the `assets()` helper (base configurable via `app.assets.base`) is the right tool.

There is no asset manager (no enqueue/render pipeline) in the theme subsystem — reference stylesheets and scripts directly in your layout with `theme_url()`.

::: warning Gotchas
- **No manifest, no theme.** A theme folder without a readable `theme.json` is silently treated as not installed — `boot()` will then fail with "Active theme slug not installed".
- **Config is read at construction.** `ThemeManager` captures `app.theme.enabled` / `app.theme.active` when the singleton is first created; changing config later in the same request has no effect.
- **`resolve()`/`render()` throw when theming is disabled**, while `hasView()` just returns `false`. Guard optional theme features with `hasView()`.
- **Views sit at the theme root**, not in a `views/` subfolder — `render('home')` looks for `themes/<slug>/home.php`.
- **`theme_path()`/`theme_url()` don't validate anything.** They build paths from config alone and will happily point at a theme that isn't installed.
:::

## Related

- [Views](/essentials/views)
- [Configuration](/guide/configuration)
- [Directory structure](/guide/directory-structure)
- [Helpers](/features/helpers)
