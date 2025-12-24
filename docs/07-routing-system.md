# 7. Routing System

## 7.1 Concepts
Yantra routes map (HTTP method, path) to (handler, middleware).
For performance, routes are compiled into cache files per HTTP method.

## 7.2 Route cache layout
```text
storage/cache/routes/
|-- __index.php      # optional global index/meta
|-- GET.php          # compiled GET routes
|-- POST.php         # compiled POST routes
|-- PUT.php
|-- PATCH.php
|-- DELETE.php
`-- OPTIONS.php
```

## 7.3 Matching strategy (recommended)
- Match static routes first
- Match parameterized routes next
- Extract route parameters into request context
- Build middleware pipeline then dispatch controller

## 7.4 Route parameters
Example patterns:
- /users/{id}
- /posts/{slug}

Validate parameter types in controllers/services.

## 7.5 Security considerations
- Route cache directory must not be writable by untrusted users.
- Cache files must not be influenced by request input.

> **Warning:** If an attacker can write to route cache PHP files, it is equivalent to code execution.
