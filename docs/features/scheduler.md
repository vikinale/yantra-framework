# Task Scheduler

Yantra's scheduler (`System\Services\Scheduler`) runs recurring tasks defined by standard 5-field cron expressions. You register schedules in a `ScheduleRegistry`, then a `Scheduler` — typically invoked every minute from a single system cron entry — checks which schedules are due and executes them, returning a `ScheduleRun` result per task. Observers and an optional store let you log, persist, and monitor runs.

```php
use System\Services\Scheduler\Scheduler;
use System\Services\Scheduler\ScheduleRegistry;

$registry = new ScheduleRegistry();

// Run daily at 2 AM
$registry->addCallable('db-backup', 'Database backup', '0 2 * * *', function ($ctx) {
    // ... backup logic
});

// Run every 15 minutes
$registry->addCallable('cache-clear', 'Clear caches', '*/15 * * * *', function ($ctx) {
    // ... cache clearing
});

$scheduler = new Scheduler($registry);
$runs = $scheduler->runDue();   // ScheduleRun[] — one entry per executed task
```

## Registering Schedules

`ScheduleRegistry` collects `Schedule` objects. The convenience method `addCallable()` wraps a closure in a `CallableTask` for you:

```php
$registry->addCallable(
    id: 'weekly-report',
    name: 'Weekly report',
    cron: '0 9 * * 1',            // Mondays at 9 AM
    fn: fn ($ctx) => buildReport(),
    timezone: 'Europe/Berlin',    // default 'UTC'
    enabled: true,                // default true
    meta: ['owner' => 'reports']  // default []
);
```

For full control, build a `Schedule` yourself and `add()` it. `Schedule` is readonly — `id`, `name`, `cron`, `task`, `timezone = 'UTC'`, `enabled = true`, `meta = []` — and parses its cron expression in the constructor (an invalid expression throws `InvalidArgumentException` immediately). `registry->all()` returns every registered schedule.

### Tasks

A task is anything implementing `TaskInterface`:

```php
interface TaskInterface
{
    public function name(): string;
    public function run(SchedulerContext $ctx): void;
}
```

`CallableTask(string $name, callable $fn)` is the built-in implementation — its `run()` simply invokes your callable with the `SchedulerContext`. The context is readonly and carries `nowUtc` (a `DateTimeImmutable`) plus the `extras` array you passed to the `Scheduler` constructor.

## Running Due Schedules

```php
$scheduler = new Scheduler(
    registry: $registry,
    store: $store,                       // ?ScheduleStoreInterface, default null
    contextExtras: ['app' => $container] // available as $ctx->extras
);

$runs = $scheduler->runDue();            // or runDue($someDateTimeImmutable) for testing
```

For each schedule, `runDue()`:

1. Skips it unless `isDue()` — the current time, converted to the schedule's timezone, matches the cron expression (and the schedule is enabled).
2. Notifies observers via `onDue($schedule)`.
3. Runs the task, catching any `Throwable`.
4. Produces a `ScheduleRun` with status `'success'` or `'failed'`, records it in the store (if configured), and notifies observers via `onRunCompleted($run)` or `onRunFailed($run, $e)`.

`ScheduleRun` is readonly: `scheduleId`, `scheduleName`, `startedAtUnix`, `finishedAtUnix`, `status`, `errorClass`, `errorMessage`, `meta`. One failing task never prevents the others from running.

## Observers

Attach any number of `ObserverInterface` implementations for logging/metrics:

```php
interface ObserverInterface
{
    public function onDue(Schedule $schedule): void;
    public function onRunCompleted(ScheduleRun $run): void;
    public function onRunFailed(ScheduleRun $run, \Throwable $e): void;
}
```

```php
$scheduler->addObserver(new class implements ObserverInterface {
    public function onDue(Schedule $s): void {}
    public function onRunCompleted(ScheduleRun $r): void { Logger::info("OK: {$r->scheduleName}"); }
    public function onRunFailed(ScheduleRun $r, \Throwable $e): void { Logger::error($e->getMessage()); }
});
```

## Schedule Stores

`ScheduleStoreInterface` lets the scheduler load additional schedules and persist run history:

```php
interface ScheduleStoreInterface
{
    public function saveSchedule(Schedule $schedule): void;
    /** @return Schedule[] */
    public function loadSchedules(): array;
    public function recordRun(ScheduleRun $run): void;
}
```

Schedules returned by `loadSchedules()` are merged with the registry's schedules on every `runDue()`. The bundled `Stores\FileRunStore(string $baseDir)` records run results to files; its `saveSchedule()`/`loadSchedules()` are no-ops (run history only). Implement the interface yourself for database-backed schedules.

## Cron Expression Format

Expressions have exactly five whitespace-separated fields:

```
*    *    *    *    *
│    │    │    │    │
│    │    │    │    └── Day of week (0-6, Sun = 0)
│    │    │    └─────── Month (1-12)
│    │    └──────────── Day of month (1-31)
│    └───────────────── Hour (0-23)
└────────────────────── Minute (0-59)
```

Each field supports:

| Syntax | Meaning | Example |
|---|---|---|
| `*` | every value | `* * * * *` — every minute |
| `n` | exact value | `0 2 * * *` — daily at 02:00 |
| `a,b,c` | list | `0 9,17 * * *` — 09:00 and 17:00 |
| `a-b` | range | `0 9-17 * * *` — hourly, 9 AM–5 PM |
| `*/n` | step | `*/15 * * * *` — every 15 minutes |
| `a-b/n` | stepped range | `0-30/10 * * * *` — :00, :10, :20, :30 |

Parsing is done by `Cron\CronExpression::parse()`; invalid expressions or fields throw `InvalidArgumentException`.

## Wiring to System Cron

The scheduler does not daemonize itself. Create a small runner script that builds the registry and calls `runDue()`, then invoke it **every minute** from a single crontab entry:

```
* * * * * php /var/www/myapp/schedule-run.php >> /dev/null 2>&1
```

See the [cron scheduling cookbook](/cookbook/cron-scheduling) for a complete runner script, locking, and deployment notes.

::: warning Gotchas
- `runDue()` matches the **current minute** only — if your cron entry doesn't fire during a due minute (downtime, drift), that run is skipped. There is no catch-up mechanism.
- Day-of-week is `0-6` with Sunday = `0`. Unlike some cron implementations, `7` is **not** accepted for Sunday.
- Tasks run sequentially inside `runDue()`. A slow task delays the rest and can push later tasks past their minute — keep scheduled work short or push heavy lifting onto the [queue](/features/queues).
- The scheduler provides no overlap protection: if a task outlives the minute and cron starts a second runner, both can execute. Add your own lock (e.g. `flock` in the runner script) if tasks must not overlap.
- Timezone matters: `isDue()` evaluates the cron expression in the schedule's timezone, while `ScheduleRun` timestamps are Unix timestamps.
:::

## Related

- [Cron scheduling cookbook](/cookbook/cron-scheduling) — full runner script and crontab setup
- [Queues](/features/queues) — offload heavy work from scheduled tasks
- [CLI](/features/cli) — build a custom `schedule:run` command
- [Configuration](/guide/configuration)
