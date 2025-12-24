# 6. HTTP Layer

## 6.1 Request
The Request object provides normalized access to query/body/JSON/files and headers.
Avoid direct superglobals (`$_GET`, `$_POST`, and especially `$_REQUEST`).

### Deterministic input assembly: all()
```php
public function all(): array
{
    $data = $this->psr->getQueryParams();
    if (!is_array($data)) {
        $data = [];
    }

    $parsed = $this->psr->getParsedBody();
    if (is_array($parsed) && $parsed !== []) {
        $data = array_replace($data, $parsed);
    }

    $contentType = strtolower($this->psr->getHeaderLine('Content-Type'));
    if (str_contains($contentType, 'application/json')) {
        $raw = (string) $this->psr->getBody();
        if ($raw !== '') {
            try {
                $json = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
                if (is_array($json) && $json !== []) {
                    $data = array_replace($data, $json);
                }
            } catch (JsonException $e) {
                // all(): best-effort; ignore invalid JSON
            }
        }
    }

    return $data;
}
```

### Validation with strict JSON handling: validate()
```php
public function validate(array $rules, array $messages = [], array $sanitizers = []): array
{
    $data = $this->psr->getQueryParams();
    if (!is_array($data)) $data = [];

    $parsed = $this->psr->getParsedBody();
    if (is_array($parsed) && $parsed !== []) {
        $data = array_replace($data, $parsed);
    }

    $contentType = strtolower($this->psr->getHeaderLine('Content-Type'));
    if (str_contains($contentType, 'application/json')) {
        $raw = (string) $this->psr->getBody();
        if ($raw !== '') {
            try {
                $json = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
                if (is_array($json) && $json !== []) {
                    $data = array_replace($data, $json);
                }
            } catch (JsonException $e) {
                throw new ValidationException(['_json' => ['Invalid JSON body.']]);
            }
        }
    }

    foreach ($this->allFiles() as $k => $f) {
        $data[$k] = $f;
    }

    if (!empty($sanitizers)) {
        $data = Sanitizer::clean($data, $sanitizers);
    }

    $v = Validator::make($data, $rules, $messages);
    if ($v->fails()) {
        throw new ValidationException($v->errors());
    }

    return $v->validated();
}
```
> **Tip:** Use `all()` for best-effort reads; use `validate()` for strict input contracts.


## 6.2 Response
Response encapsulates status, headers, and body output.

Preferred patterns:
- json($data, $status = 200)
- setStatus($code)
- header($name, $value)
- send()

### JSON response example
```php
return $response->json(['status' => 'ok'], 200);
```

### Avoid
- echo/print output from controllers/middleware
- setting headers manually outside Response
