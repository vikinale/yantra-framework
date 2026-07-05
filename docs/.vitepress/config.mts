import { defineConfig } from 'vitepress'

export default defineConfig({
  title: 'Yantra Framework',
  description: 'A modern, lightweight, zero-dependency PHP framework for performance, security, and developer productivity.',
  base: '/yantra-framework/',
  lastUpdated: true,
  cleanUrls: true,

  head: [
    ['meta', { name: 'theme-color', content: '#d97706' }],
  ],

  themeConfig: {
    siteTitle: 'Yantra',

    nav: [
      { text: 'Guide', link: '/guide/introduction' },
      { text: 'Tutorial', link: '/tutorial/01-setup' },
      { text: 'Cookbook', link: '/cookbook/file-uploads' },
      { text: 'API Reference', link: '/api/model' },
      {
        text: 'v0.1.1',
        items: [
          { text: 'Changelog', link: 'https://github.com/vikinale/yantra-framework/commits/main' },
          { text: 'Contributing', link: '/guide/introduction#contributing' },
        ],
      },
    ],

    sidebar: {
      '/guide/': sidebarMain(),
      '/tutorial/': sidebarMain(),
      '/essentials/': sidebarMain(),
      '/database/': sidebarMain(),
      '/features/': sidebarMain(),
      '/security/': sidebarMain(),
      '/testing/': sidebarMain(),
      '/cookbook/': sidebarMain(),
      '/api/': sidebarApi(),
    },

    search: {
      provider: 'local',
    },

    editLink: {
      pattern: 'https://github.com/vikinale/yantra-framework/edit/main/docs/:path',
      text: 'Edit this page on GitHub',
    },

    socialLinks: [
      { icon: 'github', link: 'https://github.com/vikinale/yantra-framework' },
    ],

    footer: {
      message: 'Released under the MIT License.',
      copyright: 'Copyright © Yantra Framework contributors',
    },

    outline: { level: [2, 3] },
  },
})

function sidebarMain() {
  return [
    {
      text: 'Getting Started',
      collapsed: false,
      items: [
        { text: 'Introduction', link: '/guide/introduction' },
        { text: 'Installation', link: '/guide/installation' },
        { text: 'Directory Structure', link: '/guide/directory-structure' },
        { text: 'Configuration', link: '/guide/configuration' },
        { text: 'Request Lifecycle', link: '/guide/lifecycle' },
      ],
    },
    {
      text: 'Tutorial: Build a Blog',
      collapsed: true,
      items: [
        { text: '1. Setup & First Route', link: '/tutorial/01-setup' },
        { text: '2. Database & Models', link: '/tutorial/02-database' },
        { text: '3. CRUD & Views', link: '/tutorial/03-crud' },
        { text: '4. Validation', link: '/tutorial/04-validation' },
        { text: '5. Authentication', link: '/tutorial/05-auth' },
        { text: '6. JSON API & JWT', link: '/tutorial/06-api' },
        { text: '7. Testing', link: '/tutorial/07-testing' },
      ],
    },
    {
      text: 'The Essentials',
      collapsed: false,
      items: [
        { text: 'Routing', link: '/essentials/routing' },
        { text: 'Controllers', link: '/essentials/controllers' },
        { text: 'Requests', link: '/essentials/requests' },
        { text: 'Responses', link: '/essentials/responses' },
        { text: 'Views & Templating', link: '/essentials/views' },
        { text: 'Middleware', link: '/essentials/middleware' },
        { text: 'Validation', link: '/essentials/validation' },
        { text: 'Session & Cookies', link: '/essentials/session' },
        { text: 'Error Handling', link: '/essentials/error-handling' },
      ],
    },
    {
      text: 'Database',
      collapsed: false,
      items: [
        { text: 'Getting Started', link: '/database/getting-started' },
        { text: 'Query Builder', link: '/database/query-builder' },
        { text: 'Models (ORM)', link: '/database/models' },
        { text: 'Relationships', link: '/database/relationships' },
        { text: 'Migrations', link: '/database/migrations' },
        { text: 'Seeders', link: '/database/seeders' },
        { text: 'Pagination', link: '/database/pagination' },
      ],
    },
    {
      text: 'Features',
      collapsed: true,
      items: [
        { text: 'Cache', link: '/features/cache' },
        { text: 'Hooks (Events)', link: '/features/hooks' },
        { text: 'Helpers', link: '/features/helpers' },
        { text: 'Collections', link: '/features/collections' },
        { text: 'Mail', link: '/features/mail' },
        { text: 'Queues', link: '/features/queues' },
        { text: 'Task Scheduler', link: '/features/scheduler' },
        { text: 'Webhooks', link: '/features/webhooks' },
        { text: 'Themes', link: '/features/themes' },
        { text: 'Reporting', link: '/features/reporting' },
        { text: 'CSV Imports', link: '/features/imports' },
        { text: 'CLI (Yantra Console)', link: '/features/cli' },
        { text: 'Service Container', link: '/features/container' },
      ],
    },
    {
      text: 'Security',
      collapsed: true,
      items: [
        { text: 'Overview', link: '/security/overview' },
        { text: 'CSRF Protection', link: '/security/csrf' },
        { text: 'Authentication', link: '/security/authentication' },
        { text: 'JWT', link: '/security/jwt' },
        { text: 'Hashing & Crypto', link: '/security/crypto' },
      ],
    },
    {
      text: 'Testing',
      collapsed: true,
      items: [
        { text: 'Getting Started', link: '/testing/getting-started' },
        { text: 'HTTP Tests', link: '/testing/http-tests' },
        { text: 'Sandboxes & Fakes', link: '/testing/sandboxes' },
      ],
    },
    {
      text: 'Cookbook',
      collapsed: true,
      items: [
        { text: 'File Uploads', link: '/cookbook/file-uploads' },
        { text: 'JWT-Protected API', link: '/cookbook/jwt-api' },
        { text: 'Queued Email', link: '/cookbook/queued-email' },
        { text: 'Rate Limiting', link: '/cookbook/rate-limiting' },
        { text: 'Flash Messages & PRG', link: '/cookbook/flash-messages' },
        { text: 'CSV Import with Rollback', link: '/cookbook/csv-import' },
        { text: 'Custom CLI Command', link: '/cookbook/custom-command' },
        { text: 'Custom Validation Rule', link: '/cookbook/custom-validation-rule' },
        { text: 'Cron Scheduling', link: '/cookbook/cron-scheduling' },
        { text: 'Production Deployment', link: '/cookbook/deployment' },
      ],
    },
  ]
}

function sidebarApi() {
  return [
    {
      text: 'API Reference',
      items: [
        { text: 'Model', link: '/api/model' },
        { text: 'QueryBuilder', link: '/api/query-builder' },
        { text: 'Request', link: '/api/request' },
        { text: 'Response', link: '/api/response' },
        { text: 'Collection', link: '/api/collection' },
        { text: 'Validator & Rules', link: '/api/validator' },
        { text: 'RouteCollector', link: '/api/route-collector' },
        { text: 'SessionStore', link: '/api/session-store' },
        { text: 'Cache', link: '/api/cache' },
        { text: 'Hooks', link: '/api/hooks' },
        { text: 'Security Classes', link: '/api/security' },
        { text: 'Helper Functions', link: '/api/helpers' },
        { text: 'Testing', link: '/api/testing' },
      ],
    },
  ]
}
