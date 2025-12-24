# 17. Performance & Optimization

## 17.1 Route caching
Compile routes per method for fast dispatch.

## 17.2 Opcache
Enable OPCache in production and tune memory settings (deployment-specific).

## 17.3 Composer autoload optimization
```bash
composer dump-autoload -o
```

## 17.4 Avoiding hotspots
- Buffer JSON body if read multiple times
- Keep middleware light
- Cache expensive reads
