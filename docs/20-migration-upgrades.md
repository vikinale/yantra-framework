# 20. Migration & Upgrade Notes

## 20.1 Versioning
Use semantic versioning and maintain a CHANGELOG.

## 20.2 Breaking changes checklist
- Namespace changes
- Request/Response API changes
- Router cache format changes
- Config format changes

## 20.3 Upgrade procedure
1. Update dependencies
2. Clear caches (storage/cache/*)
3. Recompile routes
4. Run smoke tests
