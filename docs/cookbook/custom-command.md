# Write a Custom CLI Command

Yantra's console auto-discovers commands from `App/Cli/Commands/` — no registration step. A command extends `System\Cli\AbstractCommand`, declares its `name()`/`description()`/`usage()`, and does its work in `run(Input $in, Output $out): int`, returning an exit code. This recipe builds a `customers:purge` command that deletes inactive customers, with a `--dry-run` flag.

```bash
php yantra make:command PurgeCustomersCommand   # scaffold it
php yantra customers:purge --dry-run             # then run it
```

## Step 1 — Scaffold (or hand-write) the class

`make:command` drops a file in `App/Cli/Commands/`. You can also write it by hand — the shape is small:

```php
<?php
namespace App\Cli\Commands;

use System\Cli\AbstractCommand;
use System\Cli\Input;
use System\Cli\Output;
use System\Cli\Style;

class PurgeCustomersCommand extends AbstractCommand
{
    public function name(): string
    {
        return 'customers:purge';
    }

    public function description(): string
    {
        return 'Delete customers with no activity in the given number of days.';
    }

    public function usage(): array
    {
        return [
            'customers:purge',
            'customers:purge --days=90',
            'customers:purge --days=90 --dry-run',
        ];
    }

    public function run(Input $in, Output $out): int
    {
        // --days=90 → "90"; getOpt() returns the option value as a string or null
        $days   = (int) ($this->getOpt($in, 'days') ?? '90');
        $dryRun = $in->hasOption('dry-run');

        $out->writeln(Style::info("Purging customers inactive for {$days}+ days..."));

        $stale = \App\Models\Customer::query()
            ->where('last_seen_at', '<', date('Y-m-d', strtotime("-{$days} days")))
            ->get();   // get() returns RAW ARRAYS, not model instances

        if ($dryRun) {
            $out->writeln(Style::warn(count($stale) . ' customers would be deleted (dry run).'));
            return 0;
        }

        foreach ($stale as $customer) {
            \App\Models\Customer::query()->where('id', $customer['id'])->delete();
        }

        $out->writeln(Style::ok(count($stale) . ' customers deleted.'));
        return 0;   // 0 = success; return non-zero on failure so CI can react
    }
}
```

## Step 2 — Run it

Auto-discovery means it appears immediately:

```bash
php yantra list                       # customers:purge shows in the list
php yantra help customers:purge       # prints description + usage()
php yantra customers:purge --days=30 --dry-run
```

## Reading input

`Input` parses `argv` into a command, positional args, and options:

```php
$in->command();            // "customers:purge"
$in->arg(0);               // first positional argument, or null
$in->arg(0, 'default');    // with a fallback
$in->hasOption('dry-run'); // true for a bare --dry-run flag
$in->option('days');       // "90" for --days=90, or null
$in->option('days', 90);   // 90 when absent
```

`AbstractCommand::getOpt($in, 'days')` is a convenience that returns the option value as a `string` (or `null`) — handy when you want to cast it yourself.

Parsing rules to keep in mind:

- **Long options** — `--days=90` stores the string `"90"`; a bare `--force` stores `true`.
- **Short options** — `-v` stores `true`; a bundle like `-abc` sets `a`, `b`, and `c` each to `true`.
- The first non-option token becomes the command name; the rest are positional args.

## Writing output

`Output` is deliberately minimal — three methods — and `Style` adds ANSI color:

```php
$out->write('no newline');
$out->writeln('with newline');       // STDOUT + PHP_EOL
$out->error('goes to STDERR');       // STDERR + PHP_EOL

$out->writeln(Style::info('cyan'));
$out->writeln(Style::ok('green'));
$out->writeln(Style::warn('yellow'));
$out->writeln(Style::err('red'));
```

`Style` helpers return the wrapped string (they don't print), and fall back to plain text when colors are unsupported.

## Auto-discovery rules

The `yantra` entry script registers commands from `App/Cli/Commands/` (namespace `App\Cli\Commands\`). To be discovered a class must:

- live in a file ending with `Command.php`,
- implement `System\Cli\CommandInterface` (which `AbstractCommand` already does),
- be non-abstract, and
- have a constructor with **no required parameters**.

Set `YANTRA_CLI_DEBUG=1` to see why a file was skipped.

::: warning Gotchas
- **Discovery uses `new` with no arguments** — a constructor with required parameters is silently skipped. Resolve dependencies (models, services, the container) *inside* `run()` instead of injecting them.
- **`--key value` (space-separated) is not parsed as an option value** by `Input` — `value` becomes a positional arg. Use `--key=value`. (`getOpt()` has a raw-argv fallback that accepts the spaced form, but prefer `=` for consistency.)
- **`Output` has no `info()`/`success()` methods** — combine `writeln()` with `Style` helpers for color.
- **Return an `int`** — `0` for success, non-zero for failure, so shell scripts and CI can branch on the exit code.
:::

## Related

- [CLI](/features/cli) — the full console reference (built-in commands, exit codes, styling)
- [CSV Import cookbook](/cookbook/csv-import) — a natural task to drive from a command
- [Cron Scheduling cookbook](/cookbook/cron-scheduling) — a command run every minute by system cron
- [Migrations](/database/migrations) — the `migrate:*` commands
