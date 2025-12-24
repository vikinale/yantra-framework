# 1. Introduction

Yantra is a lightweight PHP framework focused on explicit control, low overhead, and security-conscious defaults.
It is intended to be a framework foundation—not a CMS—so application and domain concerns remain outside the core.

## 1.1 Goals
- Deterministic request lifecycle (bootstrap → routing → middleware → controller → response).
- Minimal “magic”; behavior is visible and traceable.
- Fast routing via compile-time caches.
- Safer defaults: avoid ambiguous superglobals (especially `$_REQUEST`), avoid unsafe deserialization, environment-aware error output.

## 1.2 What Yantra is
- A PHP framework suitable for APIs and web backends.
- A set of small, composable components: HTTP, routing, validation, sessions, cache, and database utilities.

## 1.3 What Yantra is not
- A CMS (no theme engine, no content admin, no media library).
- An ORM-heavy framework.
- A scaffold generator that assumes project conventions.

## 1.4 Architecture at a glance
```text
Request → Router → Middleware Pipeline → Controller → Response
```

## 1.5 Conventions
- Framework code lives under `System\...`.
- Application code lives under `App\...` (controllers, middleware, services).
- `storage/` holds writable runtime artifacts (logs, cache, sessions).

> **Tip:** Keep `public/` as the only web-accessible directory in production.
