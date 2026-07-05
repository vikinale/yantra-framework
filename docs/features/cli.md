# CLI (Yantra Console)

The `yantra` CLI provides artisan-style commands for scaffolding, database work, route caching, and more. It is built on a small, dependency-free toolkit in `System\Cli`: commands implement `CommandInterface` (usually via `AbstractCommand`), receive an `Input`/`Output` pair, and return an integer exit code. Your own commands in `App/Cli/Commands/` are auto-discovered — no registration step required.

```bash
php yantra list                      # list all commands
php yantra make:controller BlogController
php yantra migrate
php yantra routes:cache
php yantra help migrate              # help for one command
```

## Built-in Commands

**Scaffolding**

| Command | Description |
|---|---|
| `make:controller <Name>` | Create a new controller. |
| `make:model <Name>` | Create a new database model. |
| `make:migration <name>` | Create a new migration file. |
| `make:seeder <Name>` | Create a new database seeder. |
| `make:middleware <Name>` | Create a new middleware class. |
| `make:command <Name>` | Create a new CLI command. |
| `make:service <Name>` | Create a new business service. |
| `make:repository <Name>` | Create a new repository. |
| `make:test <Name>` | Create a PHPUnit test skeleton (Unit/Feature). Supports interactive mode and DB-backed stubs. |
| `make:model:test <Name>` | Create a PHPUnit test skeleton for a Model (shortcut of make:test). |
| `app:scaffold` | Generate a ready-to-start application with sample code (portfolio starter). |

**Database**

| Command | Description |
|---|---|
| `migrate` | Run pending database migrations (application-owned migrations). |
| `migrate:rollback` | Rollback last migration batch (or a specific batch). |
| `migrate:refresh` | DEV ONLY: rollback all migrations, re-run migrate, then seed (optional). |
| `migrate:status` | Show migration status (ran/pending). |
| `db:seed` | Run application seeders (default data). |
| `db:check` | Check database connectivity and basic readiness (safe, read-only). |
| `db:make:model <table>` | Generate a Yantra Model by inspecting an existing database table. |

**Cache & Routes**

| Command | Description |
|---|---|
| `cache:clear` | Flush the application cache (routes, views, etc.) |
| `routes:cache` | Compile and write route cache files (GET.php, POST.php, __index.php, __errors.php). |
| `routes:clear` | Remove all compiled route cache files. |
| `route:list` | List all registered routes with their methods, names, handlers, and middleware. |

**Other**

| Command | Description |
|---|---|
| `env:set` | Set application environment (development\|production\|staging). |
| `list` | List available commands. |
| `help <command>` | Show help for a command. |

Global options handled by the console itself: `--help` / `-h` (show command list) and `--verbose` / `-v` (print stack traces for unhandled errors).

## Creating Custom Commands

Scaffold one, or write it by hand — commands extend `System\Cli\AbstractCommand` and implement `name()`, `description()`, optionally `usage()`, and `run(Input $in, Output $out): int`:

```bash
php yantra make:command SendNewsletterCommand
```

```php
<?php
namespace App\Cli\Commands;

use System\Cli\AbstractCommand;
use System\Cli\Input;
use System\Cli\Output;
use System\Cli\Style;

class SendNewsletterCommand extends AbstractCommand
{
    public function name(): string
    {
        return 'newsletter:send';
    }

    public function description(): string
    {
        return 'Send the weekly newsletter to all subscribers.';
    }

    public function usage(): array
    {
        return [
            'newsletter:send',
            'newsletter:send --dry-run',
        ];
    }

    public function run(Input $in, Output $out): int
    {
        $dryRun = $in->hasOption('dry-run');

        $out->writeln(Style::info('Sending newsletter...'));

        // Business logic here...

        $out->writeln(Style::ok('Newsletter sent to 1,234 subscribers.'));
        return 0;
    }
}
```

`AbstractCommand` also provides two protected helpers: `getOpt(Input $in, string $key): ?string` (option value as a string, `null` if absent) and `scaffoldFile(Output $out, string $path, string $content, bool $force = false)` (writes a file, skipping existing files unless forced — used by all the `make:*` commands).

### Auto-Discovery

The `yantra` entry script auto-registers commands from the framework's `System/Cli/Commands` and from your application's `App/Cli/Commands/` (namespace `App\Cli\Commands\`). To be discovered, a class must:

- live in a file ending with `Command.php`,
- implement `System\Cli\CommandInterface`,
- be non-abstract, and
- have a constructor with no required parameters.

Set `YANTRA_CLI_DEBUG=1` to see why a command file was skipped during discovery.

## Input API

`Input` parses `argv` into a command, positional arguments, and options:

```php
// php yantra report:send monthly --format=pdf --force -v -abc

$in->command();            // "report:send"
$in->args();               // ["monthly"]
$in->arg(0);               // "monthly"
$in->arg(1, 'fallback');   // "fallback" (index missing -> default)

$in->hasOption('force');   // true  (bool flag)
$in->option('format');     // "pdf"
$in->option('limit', 10);  // 10 (default when absent)
$in->options();            // ['format' => 'pdf', 'force' => true, 'v' => true, 'a' => true, 'b' => true, 'c' => true]
```

Parsing rules:

- **Long options** — `--key=value` stores the string value; `--flag` stores `true`.
- **Short options** — `-v` stores `true`; bundles expand, so `-abc` sets `a`, `b`, and `c` each to `true`; `-k=value` stores the value.
- The first non-option token is the command name; remaining non-option tokens are positional args.

## Output API

`Output` is deliberately minimal:

```php
$out->write('no newline');       // STDOUT
$out->writeln('with newline');   // STDOUT + PHP_EOL
$out->error('to stderr');        // STDERR + PHP_EOL
```

Note that `error()` writes to **STDERR** — pipe-friendly for scripts that capture stdout.

## Styling

`System\Cli\Style` provides static ANSI-color helpers that return the wrapped string (they do not print):

```php
use System\Cli\Style;

$out->writeln(Style::info('cyan informational text'));
$out->writeln(Style::ok('green success text'));
$out->writeln(Style::warn('yellow warning text'));
$out->writeln(Style::err('red error text'));
$out->writeln(Style::bold('bold text'));

Style::wrap('custom', '35');     // any raw ANSI code (35 = magenta)
Style::supportsColors();         // false for non-CLI SAPI, NO_COLOR env, or TERM=dumb
```

When colors are unsupported, every helper returns the plain text unchanged.

## Exit Codes

`run()` returns the process exit code. The console application uses these conventions:

| Code | Meaning |
|---|---|
| `0` | Success (also returned by the help screen). |
| `1` | Unhandled exception inside a command (message printed; add `-v` for the trace). |
| `2` | Unknown command, or a `CliException` thrown by a command. |

Return `0` for success and non-zero for failure from your own commands so shell scripts and CI can react correctly.

::: warning Gotchas
- `Output` has only `write`, `writeln`, and `error` — there are no `info()`/`success()` methods on it. Combine `writeln()` with the `Style` helpers for colored output.
- `--key value` (space-separated) is **not** parsed as an option value by `Input` — `value` becomes a positional arg. Use `--key=value`. (`AbstractCommand::getOpt()` falls back to raw-argv parsing that does accept the space-separated form, but prefer `=` for consistency.)
- Discovery instantiates commands with `new` — constructors requiring parameters are silently skipped. Resolve dependencies inside `run()` instead.
:::

## Related

- [Custom command cookbook](/cookbook/custom-command)
- [Migrations](/database/migrations) — the `migrate:*` commands in depth
- [Seeders](/database/seeders) — `db:seed`
- [Routing](/essentials/routing) — route caching with `routes:cache`
- [Cache](/features/cache) — what `cache:clear` clears
