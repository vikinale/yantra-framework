# 16. Security Practices

## 16.1 Input trust boundaries
- Do not merge $_REQUEST (GET/POST/COOKIE ambiguity).
- Prefer deterministic input assembly (query → body → json → files).

## 16.2 Session security
- Secure cookies, SameSite
- Regenerate IDs after login
- Keep session storage protected

## 16.3 Cache and route cache hardening
- Avoid PHP unserialize for cache
- Keep route cache outside web root
- Make caches read-only in production when possible

## 16.4 File upload security
- Validate MIME type and extension
- Store outside web root
- Generate random filenames

## 16.5 Deployment checklist
- Web root: public/ only
- display_errors Off in production
- storage/ not publicly served
- strict permissions
