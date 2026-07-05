# Responses

Every Yantra controller action produces a `System\Http\Response` — an immutable value object holding a status code, headers, and a body. The framework constructs the initial response, threads it through the kernel and middleware into your controller, and calls `emit()` on whatever comes back, so your job is simply to *return* a response. You'll usually build one through `BaseController` helpers (`success()`, `error()`, `render()`, `redirect()`) or the fluent shortcuts on the response object itself.

```php
use System\Core\BaseController;
use System\Http\Response;

class PageController extends BaseController
{
    public function about(): Response
    {
        return $this->res()->html('<h1>About Us</h1>');
    }

    public function apiStatus(): Response
    {
        return $this->res()->json(['status' => 'ok', 'version' => '1.0']);
    }
}
```

## Immutability: `with*` Methods Return Clones

All `with*` methods — and every shortcut built on them — **clone the response** and apply the change to the copy. The original object is never mutated:

```php
$response = new Response();
$json = $response->withStatus(201);

$response->getStatusCode();   // still 200
$json->getStatusCode();       // 201
```

This means you **must use the return value**. The single most common mistake with this class:

```php
// WRONG — the modified clone is discarded
$this->res()->withHeader('X-Api-Version', '2');
return $this->res()->json($data);

// RIGHT — chain from the same expression
return $this->res()
    ->withHeader('X-Api-Version', '2')
    ->json($data);
```

Inside a controller, `$this->res()` (or `$this->response`) is the response instance injected by the framework. Helpers like `render()` in `BaseController` reassign `$this->response` with the new clone for you; when you call `with*` methods directly, capture the result yourself.

## Status

```php
$new = $response->withStatus(404);                    // reason phrase auto-derived
$new = $response->withStatus(404, 'Missing Thing');   // custom reason phrase

$response->getStatusCode();     // int, e.g. 200
$response->getReasonPhrase();   // 'OK'
```

`withStatus(int $code, string $reasonPhrase = ''): self` returns a clone with the new code. When the phrase argument is empty, it is looked up via `HttpStatus::phrase()`.

### `HttpStatus::phrase()`

`System\Http\HttpStatus::phrase(int $status): string` maps a status code to its standard reason phrase (`200 → 'OK'`, `404 → 'Not Found'`, ...) and returns `'Unknown Status'` for anything not in its table. The `Response` constructor and `withStatus()` use it internally; it's also handy for logging.

### `statusWithTextHeaders()`

```php
$new = $response->statusWithTextHeaders(422);
// Sets status 422 + headers X-Status-Code: 422 and X-Status-Text: Unprocessable Entity
```

`statusWithTextHeaders(int $code, ?string $reasonPhrase = null): self` sets the status and mirrors it into `X-Status-Code`/`X-Status-Text` headers. All the body shortcuts (`html()`, `text()`, `json()`, `redirect()`, `file()`) call this internally, which is why those headers appear on framework responses.

## Headers

Header names are matched case-insensitively but emitted with the casing you supplied.

```php
$new = $response->withHeader('X-Frame-Options', 'DENY');        // set/replace
$new = $response->withHeader('X-Ids', ['1', '2']);              // multiple values at once
$new = $response->withAddedHeader('Set-Cookie', 'b=2');         // append, keep existing values
$new = $response->withoutHeader('X-Powered-By');                // remove

$response->hasHeader('content-type');       // bool, case-insensitive
$response->getHeader('Content-Type');       // array of values, [] if absent
$response->getHeaderLine('Content-Type');   // values joined with ', ', '' if absent
$response->getHeaders();                    // ['Name' => [values...], ...]
```

Two convenience wrappers exist: `header(string $name, string $value): self` is a one-value alias for `withHeader()`, and `headers(array $headers): self` applies a whole `name => value` map in one call. Both return clones like everything else.

Protocol version is also immutable: `withProtocolVersion(string $version): self` / `getProtocolVersion(): string` (default `'1.1'`).

## Bodies

The raw primitives are `withBody(string $body): self` and `getBody(): string`. In practice you'll use the typed shortcuts, each of which returns a clone with the status, `Content-Type`, `Content-Length`, and body all set:

```php
// HTML
return $response->html('<h1>Hello</h1>');            // html(string $html, int $status = 200)

// Plain text
return $response->text('pong');                      // text(string $text, int $status = 200)

// JSON
return $response->json(['ok' => true]);              // json(mixed $data, int $status = 200,
return $response->json($errors, 422);                //      int $jsonOptions = JSON_UNESCAPED_UNICODE)
```

- `html()` sets `Content-Type: text/html; charset=utf-8`.
- `text()` sets `Content-Type: text/plain; charset=utf-8`.
- `json()` encodes `$data` with `json_encode()`. If encoding fails (e.g. invalid UTF-8 or unencodable values), it does **not** throw — it responds with status `500` and a `{"error": "json_encode_failed", "message": ...}` body instead.

For structured API envelopes (`success`/`error`/`validation`), prefer the [`BaseController` helpers](/essentials/controllers), which wrap `json()` with a consistent payload shape.

## Redirects

```php
return $response->redirect('/dashboard');            // 302 Found
return $response->redirect('/login', 301);           // custom code
return $response->redirectSeeOther('/orders/42');    // 303 See Other
```

`redirect(string $url, int $code = 302): self` sets the status and `Location` header, with two safety measures baked in:

1. **CR/LF characters are stripped** from the URL, preventing header-injection (response-splitting) attacks.
2. **`javascript:` and `data:` schemes are rejected** with a `RuntimeException` — a redirect target of `javascript:alert(1)` is an XSS vector, so the framework refuses to emit it. Relative URLs that don't start with `http(s)://` or `/` are normalized to a leading `/`.

`redirectSeeOther(string $url): self` is shorthand for `redirect($url, 303)` — the correct code after a successful `POST`/`PUT`/`PATCH`, because it forces the browser to follow up with a `GET` (avoiding the "resubmit form?" prompt on refresh). `BaseController::redirectAfterPost()` uses it for exactly that purpose.

## File Downloads

### `file()` — stream a file from disk

```php
return $response->file('/storage/exports/report.pdf');                       // inline, MIME auto-detected
return $response->file('/storage/exports/report.pdf', 'Q3-Report.pdf');      // force download
return $response->file($path, 'data.bin', 'application/octet-stream');       // explicit MIME
```

`file(string $filePath, ?string $downloadName = null, ?string $mime = null): self` throws a `RuntimeException` if the file doesn't exist or isn't readable. It sets `Content-Type` (auto-detected via `mime_content_type()` when `$mime` is null, falling back to `application/octet-stream`) and `Content-Length`, but does **not** load the file into memory — the path is stored on the response and streamed with `readfile()` at emit time, so large files are safe.

When `$downloadName` is given, a `Content-Disposition: attachment` header is added. The name is passed through `basename()` (stripping any directory components) and scrubbed of control characters, quotes, and backslashes — so a hostile name can't break out of the header or point elsewhere on disk.

### `base64ToFileDownload()` — download from a data URI

```php
$dataUri = 'data:application/pdf;base64,JVBERi0xLjQK...';
return $response->base64ToFileDownload($dataUri, 'invoice');   // → invoice.pdf
```

`base64ToFileDownload(string $base64Data, string $filename): self` accepts a `data:<mime>;base64,<payload>` string, decodes it (throwing `RuntimeException` on a malformed prefix or failed decode), derives the file extension from the MIME type (jpg, png, gif, pdf, zip, txt, doc/docx, xls/xlsx — anything else gets `.bin`), and returns an attachment response with the binary body. The final filename is sanitized the same way as in `file()`.

## Rendering Views

```php
return $response->view('users/profile', ['user' => $user]);
return $response->view('auth/login', [], null);              // no layout
return $response->view('errors/404', [], 'layouts/main', 404);
```

`view(string $name, array $data = [], ?string $layout = 'layouts/main', int $status = 200): self` renders the template through the `ViewRenderer` and returns an HTML response via `html()`. The renderer must have been attached with `setViewRenderer(ViewRenderer $view): void` first — the framework does this during boot (`Application::run()`), so inside controllers it's always ready; a `RuntimeException` is thrown if it's missing. `getViewRenderer(): ?ViewRenderer` exposes the attached renderer.

In controllers, prefer `$this->render($view, $data, $layout)` from `BaseController`, which calls `view()` and keeps `$this->response` in sync. See [Views](/essentials/views) for template syntax and layouts.

## Cache-Control Helpers

```php
// Sensitive pages: forbid all caching
return $response->noStore()->html($accountPage);

// Static-ish content: allow shared caches for an hour
return $response->cachePublic(3600)->json($catalog);
```

- `noStore(): self` sets `Cache-Control: no-store, no-cache, must-revalidate, private`, plus `Pragma: no-cache` and `Expires: 0` for legacy agents.
- `cachePublic(int $seconds): self` sets `Cache-Control: public, max-age=<seconds>` and a matching `Expires` date. Negative values are clamped to `0`.

## Emitting: `emit()` and `emitAndExit()`

```php
$response->emit();          // send status line + headers + body to the client
$response->emitAndExit();   // emit, then terminate the script (return type: never)
```

`emit(): void` writes the HTTP status line, all headers (guarded by `headers_sent()`), and then the body — or, for file responses, streams the file with `readfile()`.

**You normally never call these.** The application's `run()` method emits the response your controller returns. Manual emission is for the rare cases where you must respond outside the normal lifecycle — for example, terminating early from a bootstrap script, or sending a redirect from a legacy entry point:

```php
// e.g. in a standalone maintenance script
(new Response())
    ->text('Service temporarily unavailable', 503)
    ->emitAndExit();
```

`emitAndExit(): never` guarantees nothing runs afterwards. Avoid it inside controllers and middleware — exiting mid-pipeline skips downstream middleware (logging, session write-back, etc.). Return the response instead.

## Constructing Responses Directly

The constructor signature is `__construct(int $status = 200, array $headers = [], string $body = '')`. Header values may be strings or arrays of strings. Direct construction is mostly useful in tests and middleware; in controllers, chain off the injected instance so the view renderer stays attached.

::: warning Gotchas
- **`with*` methods (and all shortcuts) return clones — capture the return value.** `$response->withHeader(...)` on its own line does nothing to `$response`. Chain calls or reassign.
- **`view()` replaces the response** created by `html()` internally — a fresh clone is returned. If you had set headers first, chain them *before or through* the same chain that renders, and return the final result.
- **`redirect()` throws on `javascript:`/`data:` URLs.** If redirect targets come from user input (`?return_to=...`), catch the `RuntimeException` or validate the URL first — and ideally allowlist redirect destinations anyway (open redirects are still possible with `https://evil.example`).
- **`json()` never throws on encoding failure** — it degrades to a 500 with an error payload. If a JSON error would indicate a bug you want to catch in development, check your data before encoding.
- **File responses hold a path, not content.** `getBody()` on a `file()` response returns an empty string; the file is streamed only at `emit()` time. Note that `withBody()` on such a clone won't remove the file path — the file still wins at emit time.
- **`emitAndExit()` bypasses the rest of the pipeline.** Downstream middleware never sees the response. Only use it outside the normal request lifecycle.
- **Calling `view()` before the renderer is attached throws.** In custom scripts, call `setViewRenderer()` first; inside the framework lifecycle this is already done.
:::

## Related

- [Requests](/essentials/requests)
- [Controllers](/essentials/controllers)
- [Views](/essentials/views)
- [Middleware](/essentials/middleware)
- [Error Handling](/essentials/error-handling)
- [HTTP Tests](/testing/http-tests)
- [Response API Reference](/api/response)
