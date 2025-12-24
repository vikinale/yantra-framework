# 13. Caching

## 13.1 Backends
- File cache
- Redis cache

## 13.2 Serialization safety
Avoid unsafe unserialize usage. If unavoidable:
```php
unserialize($data, ['allowed_classes' => false]);
```

Prefer JSON for arrays/scalars.

## 13.3 TTL and invalidation
- Use finite TTL for most keys
- Version keys (v1:, v2:) when schema changes
- Invalidate on write when possible

## 13.4 Key strategy
Examples:
- app:users:123
- app:routes:v1:GET
