---
layout: home

hero:
  name: Yantra
  text: The zero-dependency PHP framework
  tagline: An elegant MVC framework with a powerful ORM, WordPress-like hooks, 60+ validation rules, and security by default — requiring nothing but PHP 8 and PDO.
  actions:
    - theme: brand
      text: Get Started
      link: /guide/introduction
    - theme: alt
      text: Build a Blog (Tutorial)
      link: /tutorial/01-setup
    - theme: alt
      text: View on GitHub
      link: https://github.com/vikinale/yantra-framework

features:
  - icon: ⚡
    title: High-Performance Routing
    details: Static routes resolve via O(1) hash lookup, dynamic routes via compiled regex, cached per HTTP method.
    link: /essentials/routing
  - icon: 🗄️
    title: Active Record ORM
    details: Expressive models with relationships, scopes, accessors, casting, and eager loading — on top of a fluent query builder.
    link: /database/models
  - icon: 🛡️
    title: Security by Default
    details: CSRF protection, JWT auth, security headers, rate limiting, cookie hardening, login throttling, and audit logging built in.
    link: /security/overview
  - icon: ✅
    title: 60+ Validation Rules
    details: From email and UUID to database-aware unique/exists, file rules, and India-specific identifiers (PAN, GSTIN, Aadhaar, IFSC).
    link: /essentials/validation
  - icon: 🪝
    title: WordPress-like Hooks
    details: Actions and filters make every part of your application pluggable and extensible without touching core code.
    link: /features/hooks
  - icon: 📦
    title: Zero Production Dependencies
    details: Only PHP 8.0+ and PDO. No dependency tree to audit, update, or break — the entire framework is self-contained.
    link: /guide/introduction
  - icon: 🧰
    title: Batteries Included
    details: Queues, mail transports, task scheduler, webhooks, CSV imports, reporting, themes, and a 25-command CLI.
    link: /features/cli
  - icon: 🧪
    title: First-Class Testing
    details: HTTP test client, response assertions, and isolated sandboxes for database, session, cache, filesystem, and time.
    link: /testing/getting-started
---
