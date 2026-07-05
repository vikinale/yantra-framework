# Helpers

Yantra ships two layers of helper utilities. The first is a set of global procedural functions defined in `src/System/functions.php` — small, predictable wrappers for config access, path/URL building, escaping, responses, sessions, and more. The file is loaded automatically by `Application` during construction, and every function is wrapped in `function_exists()` so your application can override any of them by defining its own version first. The second layer is a set of static helper classes under the `System\Helpers` namespace for arrays, strings, URLs, HTML, forms, paths, dates, JSON, math, security, and file uploads.

```php
$url   = base_url('users/5');          // https://example.com/users/5
$title = e($post['title']);            // HTML-escaped output
$names = collect($users)->pluck('name')->unique()->sort()->values();
```

## Global Functions

### Config & Environment

```php
config('app.url');                  // get a config value
config('app.debug', false);         // with a default
env('APP_ENV', 'production');       // read an environment variable
```

| Function | Description |
| --- | --- |
| `config(string $key, mixed $default = null)` | Read a config value via `Config::get()` with dot notation. To *set* values, use `Config::set()` directly. |
| `env(string $key, mixed $default = null)` | Read an environment variable. The words `true`, `false`, `null`, and `empty` (optionally in parentheses) are normalized to their PHP equivalents — but `'0'` and `'1'` stay strings. |

### Paths

All path helpers accept an optional `$append` segment and return absolute filesystem paths.

```php
base_path('config/app.php');     // {project}/config/app.php
app_path('Controllers');         // {app}/Controllers
storage_path('logs/app.log');    // {project}/storage/logs/app.log
public_path('uploads');          // {project}/public/uploads
theme_path('views/home.php');    // {project}/themes/{active-theme}/views/home.php
path_to_url('/var/www/site/img/a.png');  // maps a filesystem path to a public URL (or null)
```

| Function | Description |
| --- | --- |
| `base_path(string $append = '')` | Path relative to the project root (`BASEPATH`). Absolute inputs (Unix, Windows drive, UNC) are returned as-is. |
| `app_path(string $append = '')` | Path relative to the application directory (`APPPATH`). |
| `storage_path(string $append = '')` | Shortcut for `base_path('storage')` + append. |
| `public_path(string $append = '')` | Shortcut for `base_path('public')` + append. |
| `theme_path(string $append = '')` | Filesystem path inside the active theme (`app.theme.active`, default `default`). |
| `path_to_url(string $path, ?string $baseUrl = null, ?string $publicRootPath = null)` | Convert an absolute filesystem path under the public root into a full URL; returns `null` if the path cannot be mapped. |

### URLs

```php
base_url('login');               // https://example.com/login
site_url('api/v1/users');        // alias for base_url()
assets('css/app.css');           // https://example.com/assets/css/app.css
theme_url('js/theme.js');        // https://example.com/themes/{active}/js/theme.js
current_url();                   // full URL of the current request
is_https();                      // true behind HTTPS (proxy-aware)
path_is('/dashboard');           // exact request-path match
path_starts('/admin');           // request-path prefix match
```

| Function | Description |
| --- | --- |
| `base_url(?string $append = '')` | Application base URL. Prefers `config('app.url')`; otherwise derives protocol/host from the request plus the `app.site` sub-directory. |
| `site_url(?string $append = '')` | Alias for `base_url()`. |
| `site_name(?string $append = '')` | Returns the configured `app.site` value (the sub-directory), optionally with an appended path. |
| `assets(?string $append = '')` | Public asset URL. Base segment comes from `config('app.assets.base')` (default `assets`). |
| `theme_url(?string $append = '')` | Web URL for active-theme assets, served from `/themes/{active}/...`. |
| `current_url()` | Full URL of the current request (host + `REQUEST_URI`). Never trusts `X-Forwarded-Host`. |
| `is_https()` | Detect HTTPS, honoring `X-Forwarded-Proto` from reverse proxies. |
| `path_is(string $needle)` | `true` if the current request path exactly equals `$needle` (trailing slashes ignored). |
| `path_starts(string $prefix)` | `true` if the current request path starts with `$prefix`. |

### Responses & Navigation

```php
return redirect('/dashboard');          // 302 redirect
return redirect('/moved', 301);
return back('/home');                   // redirect to HTTP referer, with fallback
return json(['ok' => true], 201);       // JSON response
abort(404);                             // throw an HttpException
abort(403, 'Forbidden');
```

| Function | Description |
| --- | --- |
| `redirect(string $url, int $code = 302)` | Create a redirecting `System\Http\Response`. |
| `back(string $fallback = '/')` | Redirect to the `HTTP_REFERER`, or the fallback URL. |
| `abort(int $code, string $message = '')` | Set the response code and throw `System\Http\Exceptions\HttpException`. The message defaults to the standard status phrase. |
| `json(mixed $data, int $status = 200)` | Create a JSON `Response`. |

### Security & Escaping

```php
<h1><?= e($title) ?></h1>
<div data-id="<?= esc_attr($id) ?>">
<a href="<?= esc_url($link) ?>">Visit</a>

<form method="post">
    <?= csrf_field() ?>
</form>
```

| Function | Description |
| --- | --- |
| `e(?string $value)` | Escape for HTML text output (`ENT_QUOTES \| ENT_SUBSTITUTE`, UTF-8). `null` is treated as an empty string. |
| `esc_attr(?string $value)` | Escape for HTML attribute values (same flags as `e()`). |
| `esc_url(?string $url)` | Sanitize a URL for output: strips control characters and blocks `javascript:` / `data:` schemes, then HTML-escapes. |
| `csrf_token()` | Get the current CSRF token from `System\Security\Csrf`. |
| `csrf_field()` | Render a hidden `<input name="_csrf">` field with the token. |

### Session & Auth

```php
session('user.name');            // get
session('user.name', 'Asha');    // set (two arguments)
session();                       // the SessionStore instance
old('email');                    // repopulate form input after redirect
auth_user();                     // ['id' => ..., ...] or null
auth_check();                    // bool
```

| Function | Description |
| --- | --- |
| `session(?string $key = null, mixed $default = null)` | With no arguments, returns the `SessionStore` instance. One argument reads a value; two arguments (with a non-null value) writes it. |
| `old(string $key, mixed $default = '')` | Read flashed "old input" (`_old_input.*`) after a validation redirect. |
| `auth_user()` | The authenticated user's session data (the `auth` session key) as an array, or `null`. |
| `auth_check()` | `true` when a user is logged in. |

### Routing

```php
route('users.show', ['id' => 5]);                    // /users/5
route('search', [], ['q' => 'yantra', 'page' => 2]); // /search?q=yantra&page=2
```

| Function | Description |
| --- | --- |
| `route(string $name, array $params = [], array $query = [])` | Generate a URL for a named route via `UrlGenerator`, filling `{param}` placeholders and appending optional query parameters. |

### Hooks

WordPress-style actions and filters delegating to `System\Hooks`.

```php
add_action('user.registered', fn($user) => Mailer::welcome($user));
do_action('user.registered', $user);

add_filter('post.title', fn($title) => strtoupper($title));
$title = apply_filters('post.title', $title);
```

| Function | Description |
| --- | --- |
| `add_action(string $hook, callable $callback, int $priority = 10, ?string $name = null, int $accepted_args = 1)` | Register an action listener. |
| `do_action(string $hook, mixed ...$args)` | Fire all listeners for an action. |
| `add_filter(string $hook, callable $callback, int $priority = 10, ?string $name = null, int $accepted_args = 1)` | Register a value filter. |
| `apply_filters(string $hook, mixed $value, mixed ...$args)` | Pass a value through all registered filters. |
| `apply_filter(string $hook, mixed $value, mixed ...$args)` | Backward-compatible alias for `apply_filters()`. |

See [Hooks](/features/hooks) for the full system.

### Collections

```php
$total = collect($orders)->where('status', 'paid')->sum('amount');
```

| Function | Description |
| --- | --- |
| `collect(array\|Collection $items = [])` | Create a `System\Support\Collection`. See [Collections](/features/collections). |

### Utilities

```php
out('Migration complete');                    // write a line to STDOUT (CLI)
okFile($path);                                // file exists and is readable
dd($user, $roles);                            // dump and die (dev only)
dt($nullable);                                // escape value, or '-' when empty
now();                                        // '2026-07-05 14:30:00'
normalize_email(' John@Example.COM ');        // 'john@example.com' ('' if invalid)
pick_keys($input, ['name', 'email']);         // whitelist keys, trimming strings
cls($active, 'nav-active', 'nav-idle');       // conditional CSS class string
_include(theme_path('partials/nav.php'), ['items' => $items]);
```

| Function | Description |
| --- | --- |
| `out(string $msg)` | Write a message plus newline to `STDOUT` (CLI helper). |
| `okFile(string $file)` | `true` when a file exists and is readable. |
| `dd(mixed ...$vars)` | Dump variables and exit. Throws a `RuntimeException` when the environment is `production` instead of leaking data. |
| `dt(?string $v)` | Escape a nullable string for display, returning `'-'` when empty. |
| `now()` | Current datetime string in `Y-m-d H:i:s` format. |
| `normalize_email(string $email)` | Trim, lowercase, and validate an email; returns `''` when invalid. |
| `pick_keys(array $arr, array $keys)` | Return only the listed keys, trimming string values. |
| `cls(bool $cond, string $true, string $false = '')` | Ternary shorthand for building class attribute strings. |
| `_include(string $filePath, array $variables = [], bool $print = true)` | Render a template file in an isolated variable scope (`EXTR_SKIP` prevents variable injection). Returns the output; prints it by default. |

## Static Helper Classes

The `System\Helpers` namespace contains focused utility classes. All methods are static unless noted otherwise.

### ArrayHelper

Dot-notation access and functional utilities for plain arrays.

Representative methods: `get()`, `set()`, `forget()`, `flatten()`, `first()`.

```php
use System\Helpers\ArrayHelper;

ArrayHelper::get($config, 'db.mysql.host', 'localhost');
ArrayHelper::set($config, 'cache.ttl', 3600);
ArrayHelper::flatten(['a' => ['b' => 1]]);   // ['a.b' => 1]
```

### StringHelper

UTF-8-safe, null-safe string utilities: case conversion, slugs, and inspection.

Representative methods: `contains()`, `startsWith()`, `slug()`, `limit()`, `snake()` / `camel()` / `studly()`.

```php
use System\Helpers\StringHelper;

StringHelper::slug('Hello World!');       // 'hello-world'
StringHelper::limit($body, 80);           // truncate with '...'
StringHelper::studly('user_profile');     // 'UserProfile'
```

### UrlHelper

Building and manipulating URLs, including query-string surgery and external-link detection.

Representative methods: `baseUrl()`, `asset()`, `current()`, `withQuery()`, `isExternal()`.

```php
use System\Helpers\UrlHelper;

UrlHelper::withQuery('/search?q=a', ['page' => 2]);  // /search?q=a&page=2
UrlHelper::removeQuery($url, ['utm_source']);
UrlHelper::isExternal('https://evil.example');       // true
```

### HtmlHelper

Generates small, safely escaped HTML snippets (not a templating engine).

Representative methods: `attrs()`, `tag()`, `a()`, `script()`, `classList()`.

```php
use System\Helpers\HtmlHelper;

echo HtmlHelper::a('Profile', '/me', ['class' => 'link']);
echo HtmlHelper::script(assets('js/app.js'));       // defer added by default
echo HtmlHelper::classList(['btn', $active ? 'on' : null]);
```

### FormHelper

Form tag generation with method spoofing, TTL-based one-time CSRF tokens, and safe "old input" flashing (sensitive keys such as passwords and tokens are excluded by default).

Representative methods: `open()` / `close()`, `csrfField()`, `input()`, `select()`, `errors()`.

```php
use System\Helpers\FormHelper;

echo FormHelper::open('/users/5', 'PUT');   // adds hidden _method field
echo FormHelper::csrfField();
echo FormHelper::input('email', FormHelper::old('email'));
echo FormHelper::close();
```

### FormIntegrationTrait

A controller trait that connects `FormHelper` to the request cycle: `validateCsrfOrThrow()`, `flashOldFromRequest()`, `failValidationAndReturn()`, and `redirectBackWithErrors()` (which blocks open redirects via `UrlHelper::isExternal()`).

```php
class UserController extends BaseController
{
    use \System\Helpers\FormIntegrationTrait;

    public function store(): Response
    {
        $this->validateCsrfOrThrow();
        // ...
    }
}
```

### PathHelper

Safe, cross-platform filesystem path manipulation.

Representative methods: `join()`, `normalize()`, `isAbsolute()`, `safePath()`, `extension()`.

```php
use System\Helpers\PathHelper;

PathHelper::join(storage_path(), 'uploads', $name);
PathHelper::safePath($userInput);      // strips '..' traversal segments
PathHelper::ensureDirectory($dir);     // mkdir -p equivalent
```

### DateHelper

Timezone-aware date/time utilities built on `DateTimeImmutable`.

Representative methods: `now()`, `parse()`, `format()`, `addDays()`, `diffHuman()`.

```php
use System\Helpers\DateHelper;

DateHelper::format($row['created_at'], 'd M Y');
DateHelper::addDays(null, 7);                     // one week from now
DateHelper::diffHuman($row['created_at']);        // '2 days ago'
```

### JsonHelper

Predictable JSON encode/decode with strong defaults and a clear split between throwing and non-throwing APIs.

Representative methods: `encode()`, `decode()`, `tryDecode()`, `pretty()`, `isValid()`.

```php
use System\Helpers\JsonHelper;

$data = JsonHelper::tryDecode($raw, true, []);   // never throws
$json = JsonHelper::encode($payload);            // unescaped slashes/unicode
JsonHelper::isValid($input);                     // bool
```

### MathHelper

Static numeric utilities used across the framework.

Representative methods: `percentage()`, `average()`, `median()`, `clamp()`, `roundTo()`.

```php
use System\Helpers\MathHelper;

MathHelper::percentage(25, 200);     // 12.5
MathHelper::clamp($page, 1, $max);
```

### Math

An *instance-based* calculator class (`factorial()`, `power()`, `squareRoot()`, `mean()`, `variance()`, `standardDeviation()`, and more). Note that `Math::evaluate()` is intentionally disabled and throws a `RuntimeException` — arbitrary expression evaluation via `eval()` is a security risk.

```php
$math = new \System\Helpers\Math();
$math->factorial(5);            // 120
$math->standardDeviation([2, 4, 4, 4, 5, 5, 7, 9]);
```

### SecurityHelper

Hashing, random tokens, escaping, and constant-time comparison primitives.

Representative methods: `hash()`, `verifyHash()`, `uuid()`, `hmac()`, `constantTimeEquals()`.

```php
use System\Helpers\SecurityHelper;

$hash = SecurityHelper::hash($password, 'argon2id');
SecurityHelper::verifyHash($password, $hash);    // auto-detects bcrypt/argon2
SecurityHelper::uuid();                          // RFC 4122 v4
```

### OriginHelper

Single-purpose class: `isSameOrigin(string $candidate, string $siteOrigin)` compares a candidate URL's origin against the site origin using constant-time comparison — useful for CSRF `Origin`/`Referer` checks.

### UploadHelper

Secure storage of uploaded files with extension whitelists, real-MIME verification via `finfo`, collision-safe naming, and chunked-upload support.

Representative methods: `store()`, `resolveUniquePath()`, `saveChunk()`, `assembleChunks()`.

```php
use System\Helpers\UploadHelper;

$result = UploadHelper::store($_FILES['avatar'], public_path('uploads'), ['png', 'jpg', 'webp'], 2_000_000);
if ($result['ok']) {
    echo $result['url'];
}
```

See [File Uploads](/cookbook/file-uploads) for a complete walkthrough.

::: warning Gotchas
- **Two CSRF token systems exist.** The global `csrf_token()` / `csrf_field()` functions use `System\Security\Csrf` and a field named `_csrf`, while `FormHelper` maintains its own TTL-based one-time token bag with a field named `_csrf_token`. Pick one per form and validate with the matching mechanism.
- `env()` converts the strings `true`/`false`/`null`/`empty` to real types, but `'0'` and `'1'` remain strings — cast explicitly for booleans stored as numbers.
- `session('key', $value)` only *sets* when the second argument is non-null; `session('key', null)` is a read.
- `dd()` throws a `RuntimeException` in production rather than dumping — do not rely on it for production logging.
- The global `old()` reads the `_old_input.*` flash keys, whereas `FormHelper::old()` reads FormHelper's own `_yantra_old_input` session bag — they are not interchangeable.
- `Math` methods are instance methods (`(new Math())->factorial(5)`), unlike every other helper class which is static.
:::

## Related

- [Collections](/features/collections)
- [Hooks](/features/hooks)
- [CSRF Protection](/security/csrf)
- [Session](/essentials/session)
- [Responses](/essentials/responses)
- [Helpers API Reference](/api/helpers)
