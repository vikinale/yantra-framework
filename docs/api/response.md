# API Reference: Response

`System\Http\Response`

An **immutable** HTTP response value object holding a status code, reason phrase, protocol version, headers, and a body (or a file path for streamed downloads). It is `final`. Your controller builds one and returns it; the framework calls `emit()` on it. For a narrative guide, see [Responses](/essentials/responses).

::: warning Every `with*` method (and shortcut) returns a CLONE
The instance is never mutated in place. `withHeader()`, `withStatus()`, `html()`, `json()`, `redirect()`, `noStore()`, … all **return a new object**. You must use the return value:

```php
// WRONG — clone is discarded, original unchanged
$this->res()->withHeader('X-Api', '2');
return $this->res()->json($data);

// RIGHT — chain from one expression
return $this->res()->withHeader('X-Api', '2')->json($data);
```
:::

## Method Table

### Constructor

| Signature | Returns | Description |
| --- | --- | --- |
| `__construct(int $status = 200, array $headers = [], string $body = '')` | — | Header values may be strings or arrays of strings. |

### Status

| Signature | Returns | Description |
| --- | --- | --- |
| `withStatus(int $code, string $reasonPhrase = ''): self` | clone | Set status; phrase auto-derived via `HttpStatus::phrase()` when empty. |
| `statusWithTextHeaders(int $code, ?string $reasonPhrase = null): self` | clone | Set status **and** mirror it into `X-Status-Code`/`X-Status-Text` headers. |
| `getStatusCode(): int` | `int` | Current status code. |
| `getReasonPhrase(): string` | `string` | Current reason phrase. |

### Headers

| Signature | Returns | Description |
| --- | --- | --- |
| `withHeader(string $name, $value): self` | clone | Set/replace a header (string or array of values). |
| `withAddedHeader(string $name, $value): self` | clone | Append value(s), keeping existing ones. |
| `withoutHeader(string $name): self` | clone | Remove a header (case-insensitive). |
| `header(string $name, string $value): self` | clone | One-value alias for `withHeader()`. |
| `headers(array $headers): self` | clone | Apply a whole `name => value` map. |
| `hasHeader(string $name): bool` | `bool` | Case-insensitive presence check. |
| `getHeader(string $name): array` | `array` | Values for one header (`[]` if absent). |
| `getHeaderLine(string $name): string` | `string` | Header values joined with `, `. |
| `getHeaders(): array` | `array` | `['Name' => [values…], …]`. |

### Protocol & body primitives

| Signature | Returns | Description |
| --- | --- | --- |
| `withProtocolVersion(string $version): self` | clone | Set HTTP version (default `1.1`). |
| `getProtocolVersion(): string` | `string` | Current protocol version. |
| `withBody(string $body): self` | clone | Replace the body string. |
| `getBody(): string` | `string` | Current body (empty for file responses). |

### Body shortcuts (status + Content-Type + Content-Length + body)

| Signature | Returns | Description |
| --- | --- | --- |
| `html(string $html, int $status = 200): self` | clone | `text/html; charset=utf-8`. |
| `text(string $text, int $status = 200): self` | clone | `text/plain; charset=utf-8`. |
| `json(mixed $data, int $status = 200, int $jsonOptions = JSON_UNESCAPED_UNICODE): self` | clone | JSON-encode; **never throws** — degrades to 500 on encode failure. |

### Redirects

| Signature | Returns | Description |
| --- | --- | --- |
| `redirect(string $url, int $code = 302): self` | clone | `Location` redirect; strips CR/LF, rejects `javascript:`/`data:`. |
| `redirectSeeOther(string $url): self` | clone | `redirect($url, 303)` — correct after a `POST`/`PUT`/`PATCH`. |

### File downloads

| Signature | Returns | Description |
| --- | --- | --- |
| `file(string $filePath, ?string $downloadName = null, ?string $mime = null): self` | clone | Stream a file from disk (path stored, streamed at emit); throws if missing/unreadable. |
| `base64ToFileDownload(string $base64Data, string $filename): self` | clone | Decode a `data:<mime>;base64,…` URI into an attachment; extension inferred from MIME. |

### Views

| Signature | Returns | Description |
| --- | --- | --- |
| `view(string $name, array $data = [], ?string $layout = 'layouts/main', int $status = 200): self` | clone | Render a template through the renderer and return an HTML response; throws if no renderer attached. |
| `setViewRenderer(ViewRenderer $view): void` | `void` | Attach the renderer (framework does this at boot). |
| `getViewRenderer(): ?ViewRenderer` | `?ViewRenderer` | The attached renderer, if any. |

### Cache-Control

| Signature | Returns | Description |
| --- | --- | --- |
| `noStore(): self` | clone | `Cache-Control: no-store, no-cache, must-revalidate, private` (+ `Pragma`, `Expires`). |
| `cachePublic(int $seconds): self` | clone | `Cache-Control: public, max-age=<seconds>` (+ matching `Expires`; negatives clamped to 0). |

### Emitting

| Signature | Returns | Description |
| --- | --- | --- |
| `emit(): void` | `void` | Write status line, headers, then body (or `readfile()` for file responses). |
| `emitAndExit(): never` | never | `emit()` then `exit`. |

## Selected examples

### JSON never throws

`json()` encodes with `JSON_UNESCAPED_UNICODE` by default. If encoding fails (bad UTF-8, unencodable value), it does **not** raise — it responds `500` with a `{"error":"json_encode_failed","message":…}` body instead:

```php
return $response->json(['ok' => true]);
return $response->json($errors, 422);
```

### Redirects are hardened

```php
return $response->redirect('/dashboard');           // 302
return $response->redirectSeeOther('/orders/42');    // 303 (use after POST)
```

CR/LF are stripped (blocks response-splitting), and a `javascript:`/`data:` target throws `RuntimeException`. Relative URLs without a scheme get a leading `/`.

### File responses hold a path, not bytes

`file()` stores the path and streams it with `readfile()` at emit time, so large files never load into memory. `getBody()` on such a response returns `''`. A given `$downloadName` produces a sanitized `Content-Disposition: attachment`.

```php
return $response->file('/storage/report.pdf', 'Q3-Report.pdf');
```

### Emitting is usually the framework's job

```php
// Only outside the normal request lifecycle (e.g. a maintenance script):
(new Response())->text('Unavailable', 503)->emitAndExit();
```

::: warning Gotchas
- **Capture the return value.** A bare `$response->withHeader(...)` statement does nothing observable — it clones and throws the clone away.
- **`view()` returns a fresh clone** built via `html()`; set headers within the same chain and return the final result.
- **`redirect()` throws on `javascript:`/`data:`.** For user-controlled targets, validate/allowlist first — open redirects to `https://evil.example` are still possible.
- **`getBody()` is empty on file responses**, and `withBody()` on such a clone won't override the stored file path at emit time.
- **`emitAndExit()` skips the rest of the pipeline** (logging, session write-back, downstream middleware). Prefer returning the response.
- **`view()` before a renderer is attached throws** — inside the framework this is already wired up.
:::

## Related

- [Responses guide](/essentials/responses)
- [Controllers](/essentials/controllers)
- [Views](/essentials/views)
- [API Reference: Request](/api/request)
