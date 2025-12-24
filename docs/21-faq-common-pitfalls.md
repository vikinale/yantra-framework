# 21. FAQ & Common Pitfalls

## 21.1 Routes not matching
- Ensure route cache exists and is updated.
- Verify rewrite rules route requests to index.php.

## 21.2 JSON body missing
- Ensure Content-Type: application/json
- Buffer body if middleware consumes stream

## 21.3 Headers already sent
- Start sessions before output
- Avoid echo before response emission

## 21.4 Cache issues
- Verify directory permissions
- Avoid unserialize-based cache formats in shared environments
