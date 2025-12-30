Below are practical “edge” rate-limit configurations for Nginx, Apache, and Cloudflare to protect your website login endpoint(s). These block brute-force attempts before PHP/Yantra runs.

Assumptions (adjust paths as needed):

Login page: GET /login

Login submit: POST /login

Optional additional endpoints: /admin/login, /auth/login

Recommended baseline:

5 requests/min per IP (sustained)

allow burst 10 (short spikes)

1) Nginx (reverse proxy or direct PHP-FPM)
A) Minimal, production-friendly configuration

Place limit_req_zone in the http {} block (global):

http {
  # 10MB shared memory ~ enough for tens of thousands of IPs
  limit_req_zone $binary_remote_addr zone=login_zone:10m rate=5r/m;

  server {
    # ...
  }
}


Then in your server {} block, add one of these patterns:

Option 1: exact /login (best if your route is fixed)
server {
  location = /login {
    limit_req zone=login_zone burst=10 nodelay;

    # your normal routing:
    # proxy_pass http://app_upstream;
    # or include fastcgi config for PHP-FPM
    try_files $uri /index.php?$query_string;
  }
}

Option 2: apply only for POST to /login (more precise)
map $request_method $is_login_post {
  default 0;
  POST 1;
}

server {
  location = /login {
    if ($is_login_post) {
      limit_req zone=login_zone burst=10 nodelay;
    }
    try_files $uri /index.php?$query_string;
  }
}

Option 3: multiple endpoints
server {
  location ~ ^/(login|admin/login|auth/login)$ {
    limit_req zone=login_zone burst=10 nodelay;
    try_files $uri /index.php?$query_string;
  }
}


Notes:

burst=10 allows a user retrying quickly.

nodelay rejects immediately once burst is exceeded. Remove nodelay if you prefer queuing instead of rejecting.

2) Apache

Apache rate limiting depends on modules available. The most common workable options are:

A) mod_evasive (classic anti-DoS / brute force)

Install/enable mod_evasive, then in Apache config:

<IfModule mod_evasive20.c>
  DOSHashTableSize    3097
  DOSPageCount        5
  DOSPageInterval     60
  DOSSiteCount        100
  DOSSiteInterval     60
  DOSBlockingPeriod   600
</IfModule>


To focus on /login, you typically rely on the “page count” behavior (it’s path-sensitive). With DOSPageCount 5 and DOSPageInterval 60, repeated hits to /login from the same IP get blocked for 10 minutes.

B) mod_security (WAF-style, strong if available)

If mod_security2 is enabled, you can add a simple rule to rate-limit login POSTs. Example conceptually (exact syntax varies by setup/CRS):

Track requests per IP to /login

Deny above threshold

If you’re using OWASP CRS, you’d typically enable/adjust the anomaly scoring and add a rule for /login. Because mod_security setups vary widely, the most reliable Apache-native approach for “all environments” is mod_evasive.

C) mod_ratelimit is bandwidth throttling, not request throttling

It limits transfer rate, not the number of requests. It’s not ideal for brute force.

Practical recommendation for Apache: use Cloudflare if possible, or mod_evasive on the server.

3) Cloudflare (best coverage, easiest management)

You have two main ways: WAF Custom Rule or Rate Limiting rule (depending on your plan).

A) Cloudflare Rate Limiting Rule (preferred)

Create a rate limiting rule:

Expression (example):

If http.request.uri.path eq "/login"

Threshold:

5 requests per 60 seconds (or 5 per 60 seconds is more strict than 5/min; pick your comfort)

or 10 requests per 60 seconds if you want looser

Action:

Block, or

Managed Challenge (recommended so real users can pass)

If you want to limit only login submits:

Add condition: http.request.method eq "POST"

B) WAF Custom Rule (fallback)

A WAF rule can block/JS challenge based on:

path /login

method POST

ASN, country, IP reputation, bot score, etc.

This is not true “rate limiting” unless combined with rate-limiting features, but it’s useful hardening.

Example WAF rule logic:

If path is /login AND method is POST AND Bot Score < X → Managed Challenge

Recommended real-world setup (website login)

Cloudflare: Managed Challenge for /login after 5–10 requests/min/IP

Nginx: limit_req on /login as a second edge layer (if you have it)

Yantra middleware: your sec.login_throttle for user+ip throttling + audit logs

This gives you 3 lines of defense:

Cloudflare blocks most bots globally

Nginx blocks what reaches your server

Yantra logs + blocks targeted attempts

Important operational notes

Do not rate-limit your entire site—only login endpoints.

Ensure your edge sees the real client IP:

Nginx behind Cloudflare must use real_ip_header CF-Connecting-IP; and trusted IP ranges.

Keep messages generic (“Too many attempts, try later.”)

=================================================================
Below are ready-to-use edge configurations for exactly /login on Nginx, Apache, and Cloudflare. This covers both GET /login and POST /login (you can restrict to POST if you prefer).

1) Nginx (recommended server-side edge)
A) Global zone (put inside http {})
limit_req_zone $binary_remote_addr zone=login_zone:10m rate=5r/m;

B) Limit only /login (put inside server {})

Option 1: limit all methods to /login

location = /login {
  limit_req zone=login_zone burst=10 nodelay;
  try_files $uri /index.php?$query_string;
}


Option 2: limit only POST /login (better UX)

limit_req_zone $binary_remote_addr zone=login_post_zone:10m rate=5r/m;

server {
  location = /login {
    if ($request_method = POST) {
      limit_req zone=login_post_zone burst=10 nodelay;
    }
    try_files $uri /index.php?$query_string;
  }
}


Recommended: Option 2 (users can refresh the login page without being blocked).

2) Apache

Apache does not have a clean built-in “requests-per-minute per path” feature by default. The most common practical approach is mod_evasive.

A) Enable mod_evasive (if available)

In Apache config (global or vhost):

<IfModule mod_evasive20.c>
  DOSHashTableSize    3097
  DOSPageCount        5
  DOSPageInterval     60
  DOSBlockingPeriod   600
</IfModule>


This effectively blocks an IP that hits the same URL (like /login) more than ~5 times in 60 seconds, then blocks for 10 minutes.

If you want it a bit looser (avoid false positives):

DOSPageCount      10
DOSPageInterval   60
DOSBlockingPeriod 600


If mod_evasive is not available on your hosting, Cloudflare is the easiest reliable “edge” alternative.

3) Cloudflare (best global edge)
A) Rate Limiting Rule (preferred)

Create a rate limiting rule:

Expression:

http.request.uri.path eq "/login"

Or only on submits:

http.request.uri.path eq "/login" and http.request.method eq "POST"

Threshold:

5 requests per 60 seconds (strict)

or 10 requests per 60 seconds (safer for real users)

Action:

Managed Challenge (recommended)

or Block

B) WAF Custom Rule (optional extra hardening)

If you have Bot Management, you can do:

If /login and POST and Bot Score low → Managed Challenge

Strong recommended combo for /login

Cloudflare: rate limit POST /login → Managed Challenge after 10/min/IP

Nginx: limit_req POST /login → 5/min/IP burst 10

Yantra middleware: sec.login_throttle (IP+email) + audit logs

This gives you layered protection without DB/files.
=========================================================
Understood: Apache with /login.

Apache does not ship with a simple “requests-per-minute per URL” limiter by default, so the practical, widely-used choices are:

mod_evasive (simple, effective for brute-force)

mod_security2 + OWASP CRS (stronger, more complex)

fail2ban (server-level, excellent if you control the OS)

Since you asked for edge configuration, here are workable Apache setups.

Option 1 (most common): mod_evasive for /login
1) Enable the module

Debian/Ubuntu: a2enmod evasive

Or install package: libapache2-mod-evasive

Restart Apache.

2) Add config (global or vhost)

This blocks repeated hits to the same URL like /login.

<IfModule mod_evasive20.c>
  DOSHashTableSize    3097

  # If a single URL is requested more than N times within Interval seconds, block
  DOSPageCount        10
  DOSPageInterval     60

  # Block an IP for this many seconds
  DOSBlockingPeriod   600

  # Optional: avoid blocking internal/private ranges
  # DOSWhitelist 127.0.0.1
  # DOSWhitelist 192.168.0.*
</IfModule>


Recommended for /login:

DOSPageCount 10 and DOSPageInterval 60 (10/min) is safe for real users.

If attacks are heavy, drop to 5.

What happens: If an IP hits /login too many times, Apache returns 403 for 10 minutes.

Option 2 (stronger): mod_security2 + OWASP CRS

If your hosting supports it, this is closer to a WAF. It can add bot rules, reputation checks, and more.

High-level steps:

Enable mod_security2

Enable OWASP CRS

Add a rule for /login POST to enforce rate-like behavior (varies per CRS setup)

Because mod_security rules differ significantly across deployments, mod_evasive is the easiest “works everywhere” answer. If you want, share your mod_security status and I’ll provide a CRS-compatible rule snippet.

Option 3 (best if you control the server): fail2ban (true edge at OS)

This is not Apache-only, but it is very effective:

watches Apache access logs

bans IPs at firewall level after repeated /login attempts

This avoids PHP and blocks at the network layer. It does involve reading logs (server-side), but it does not require DB tables in your app.

Practical recommendation for Apache + Yantra

Use mod_evasive to limit /login abuse

Keep your Yantra middleware throttle for IP+email + audit logging

Keep error messages generic (“Invalid credentials”)

Suggested values (balanced)

DOSPageCount 10

DOSPageInterval 60

DOSBlockingPeriod 600
====================================================================
