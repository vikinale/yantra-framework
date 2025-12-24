# 14. Utilities & Helpers

## 14.1 JSON helper
Prefer JSON_THROW_ON_ERROR for explicit error handling:
```php
try {
    $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
} catch (JsonException $e) {
    // handle invalid JSON
}
```

## 14.2 File utilities
- Avoid @mkdir; check return values
- Normalize paths
- Do not trust user filenames for storage paths

## 14.3 Helper guidelines
- Prefer small, pure helpers
- Avoid global state
- Keep helpers framework-level only if broadly reusable
