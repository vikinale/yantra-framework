# Wire the Scheduler to System Cron

Yantra's [scheduler](/features/scheduler) doesn't daemonize. You register recurring tasks in a `ScheduleRegistry`, and one system cron entry — firing **every minute** — runs a small script (or CLI command) that calls `Scheduler::runDue()`. That single entry then drives every task you've registered, each on its own cron expression.

```php
use System\Services\Scheduler\Scheduler;
use System\Services\Scheduler\ScheduleRegistry;

$registry = new ScheduleRegistry();
$registry->addCallable('db-backup', 'Database backup', '0 2 * * *', function ($ctx) {
    // ... runs daily at 02:00
});

$scheduler = new Scheduler($registry);
$runs = $scheduler->runDue();   // ScheduleRun[] — one per task that fired this minute
```

## Step 1 — Register your schedules

`addCallable(id, name, cron, fn)` wraps a closure in a task and adds it. The closure receives a `SchedulerContext` (`$ctx->nowUtc`, plus any `$ctx->extras`):

```php
$registry = new ScheduleRegistry();

// Every 15 minutes
$registry->addCallable('cache-warm', 'Warm caches', '*/15 * * * *', function ($ctx) {
    // ... warm caches
});

// Daily at 02:00
$registry->addCallable('db-backup', 'Database backup', '0 2 * * *', function ($ctx) {
    // ... back up the database
});

// Mondays at 09:00 Berlin time
$registry->addCallable(
    id: 'weekly-report',
    name: 'Weekly report',
    cron: '0 9 * * 1',
    fn: fn ($ctx) => buildReport(),
    timezone: 'Europe/Berlin',   // default 'UTC'
);
```

`isDue()` evaluates each cron expression in the schedule's own timezone.

## Step 2 — Build the runner as a CLI command

The cleanest way to invoke the scheduler is a [custom command](/cookbook/custom-command) so cron just calls `php yantra schedule:run`:

```php
<?php
namespace App\Cli\Commands;

use System\Cli\AbstractCommand;
use System\Cli\Input;
use System\Cli\Output;
use System\Cli\Style;
use System\Services\Scheduler\Scheduler;
use System\Services\Scheduler\ScheduleRegistry;

class ScheduleRunCommand extends AbstractCommand
{
    public function name(): string { return 'schedule:run'; }

    public function description(): string
    {
        return 'Run all scheduled tasks that are due this minute.';
    }

    public function run(Input $in, Output $out): int
    {
        // Prevent overlapping runs — see Step 3.
        $lock = fopen(storage_path('schedule.lock'), 'c');
        if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
            $out->writeln(Style::warn('Another schedule:run is already active — skipping.'));
            return 0;
        }

        $registry = new ScheduleRegistry();
        $this->registerSchedules($registry);

        $scheduler = new Scheduler($registry);
        $runs = $scheduler->runDue();

        foreach ($runs as $run) {
            $line = "{$run->scheduleName}: {$run->status}";
            $out->writeln($run->status === 'success' ? Style::ok($line) : Style::err($line));
        }

        flock($lock, LOCK_UN);
        return 0;
    }

    private function registerSchedules(ScheduleRegistry $registry): void
    {
        $registry->addCallable('cache-warm', 'Warm caches', '*/15 * * * *', fn ($ctx) => null);
        $registry->addCallable('db-backup', 'Database backup', '0 2 * * *', fn ($ctx) => null);
        // ... register the rest here
    }
}
```

Because the command is auto-discovered from `App/Cli/Commands/`, `php yantra schedule:run` works immediately.

## Step 3 — Add the every-minute cron entry

The scheduler matches only the **current minute**, so cron must fire once per minute. On Linux, edit the crontab (`crontab -e`):

```
* * * * * cd /var/www/myapp && php yantra schedule:run >> storage/logs/schedule.log 2>&1
```

On Windows, create a Task Scheduler task that runs `php yantra schedule:run` with a trigger repeating **every 1 minute** indefinitely.

If you'd rather not use a command, a bare runner script works the same way:

```php
<?php
// schedule-run.php
require __DIR__ . '/vendor/autoload.php';   // or your bootstrap

$registry = new System\Services\Scheduler\ScheduleRegistry();
$registry->addCallable('cache-warm', 'Warm caches', '*/15 * * * *', fn ($ctx) => null);

(new System\Services\Scheduler\Scheduler($registry))->runDue();
```

```
* * * * * php /var/www/myapp/schedule-run.php >> /dev/null 2>&1
```

## Step 4 — Guard against overlaps (recommended)

The scheduler has **no built-in overlap protection**: if a task outlives its minute and cron starts a second runner, both execute. The `flock` in Step 2 solves this — the second runner can't take the lock and exits cleanly. In a bare script, wrap `runDue()` the same way:

```php
$lock = fopen('/var/www/myapp/storage/schedule.lock', 'c');
if ($lock && flock($lock, LOCK_EX | LOCK_NB)) {
    (new Scheduler($registry))->runDue();
    flock($lock, LOCK_UN);
}
```

## Logging and persistence (optional)

Attach an observer for logging, or pass a `FileRunStore` to persist run history:

```php
use System\Services\Scheduler\Stores\FileRunStore;

$scheduler = new Scheduler(
    registry: $registry,
    store: new FileRunStore(storage_path('schedule-runs')),
);
```

Each fired task yields a `ScheduleRun` (`scheduleId`, `scheduleName`, `status`, timestamps, and error details on failure). One failing task never prevents the others from running.

::: warning Gotchas
- **No catch-up.** `runDue()` matches the current minute only — if cron doesn't fire during a due minute (downtime, drift), that run is skipped forever.
- **Day-of-week is `0-6` with Sunday = `0`.** `7` is **not** accepted for Sunday.
- **Tasks run sequentially** inside `runDue()` — a slow task delays the rest and can push later tasks past their minute. Keep scheduled work short; push heavy lifting onto the [queue](/features/queues).
- **Add your own overlap lock** (the `flock` above) if tasks must not run concurrently — the scheduler provides none.
- **Timezones:** `isDue()` uses the schedule's timezone, but `ScheduleRun` timestamps are Unix (UTC) seconds.
:::

## Related

- [Task Scheduler](/features/scheduler) — the full scheduler reference
- [Custom Command cookbook](/cookbook/custom-command) — build the `schedule:run` command
- [Queues](/features/queues) — offload heavy work from scheduled tasks
- [Deployment cookbook](/cookbook/deployment) — set up the cron entry in production
