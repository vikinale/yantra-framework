# 11. Sessions

## 11.1 Overview
Sessions must be initialized early (before output). Prefer secure cookie settings and consistent storage behavior.

## 11.2 Recommended cookie settings
- httponly = true
- secure = true (HTTPS only)
- samesite = Lax or Strict

## 11.3 Regeneration
Regenerate session IDs on privilege changes (e.g., after login).

## 11.4 Destroying sessions
- clear session data
- invalidate cookie when headers are not sent
- session_destroy()

> **Tip:** Do not rely on legacy cookie clearing when headers are already sent.
