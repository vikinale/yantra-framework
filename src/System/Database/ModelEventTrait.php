<?php
declare(strict_types=1);

namespace System\Database;

use System\Hooks;

/**
 * ModelEventTrait — Adds lifecycle events to Model.
 *
 * Uses the existing Hooks system internally to fire events at key lifecycle points.
 * Events: creating, created, updating, updated, saving, saved, deleting, deleted
 *
 * Usage in Model subclass:
 *   User::creating(function(User $user) {
 *       $user->uuid = bin2hex(random_bytes(16));
 *   });
 *
 *   User::updated(function(User $user) {
 *       Cache::forget("user.{$user->id}");
 *   });
 */
trait ModelEventTrait
{
    /**
     * Register a "creating" event callback.
     * Fired before a new record is inserted.
     * Return false to cancel the insert.
     */
    public static function creating(callable $callback, int $priority = 10): void
    {
        self::registerModelEvent('creating', $callback, $priority);
    }

    /**
     * Register a "created" event callback.
     * Fired after a new record is inserted.
     */
    public static function created(callable $callback, int $priority = 10): void
    {
        self::registerModelEvent('created', $callback, $priority);
    }

    /**
     * Register an "updating" event callback.
     * Fired before an existing record is updated.
     * Return false to cancel the update.
     */
    public static function updating(callable $callback, int $priority = 10): void
    {
        self::registerModelEvent('updating', $callback, $priority);
    }

    /**
     * Register an "updated" event callback.
     * Fired after an existing record is updated.
     */
    public static function updated(callable $callback, int $priority = 10): void
    {
        self::registerModelEvent('updated', $callback, $priority);
    }

    /**
     * Register a "saving" event callback.
     * Fired before save (both create and update).
     * Return false to cancel the save.
     */
    public static function saving(callable $callback, int $priority = 10): void
    {
        self::registerModelEvent('saving', $callback, $priority);
    }

    /**
     * Register a "saved" event callback.
     * Fired after save (both create and update).
     */
    public static function saved(callable $callback, int $priority = 10): void
    {
        self::registerModelEvent('saved', $callback, $priority);
    }

    /**
     * Register a "deleting" event callback.
     * Fired before a record is deleted.
     * Return false to cancel the delete.
     */
    public static function deleting(callable $callback, int $priority = 10): void
    {
        self::registerModelEvent('deleting', $callback, $priority);
    }

    /**
     * Register a "deleted" event callback.
     * Fired after a record is deleted.
     */
    public static function deleted(callable $callback, int $priority = 10): void
    {
        self::registerModelEvent('deleted', $callback, $priority);
    }

    /* -------------------- Internal -------------------- */

    /**
     * Register a model event via the Hooks system.
     */
    private static function registerModelEvent(string $event, callable $callback, int $priority): void
    {
        $hookName = self::getModelEventHookName($event);
        Hooks::add_action($hookName, $callback, $priority);
    }

    /**
     * Fire a model event. Returns false if a "before" event signals cancellation.
     *
     * For "before" events (creating, updating, saving, deleting):
     *   Callbacks receive the model. If any callback returns false explicitly,
     *   the operation is cancelled.
     *
     * For "after" events (created, updated, saved, deleted):
     *   Callbacks receive the model. Return values are ignored.
     */
    protected function fireModelEvent(string $event): bool
    {
        $hookName = self::getModelEventHookName($event);

        if (!Hooks::has_action($hookName)) {
            return true;
        }

        // For "before" events, we use apply_filter to allow cancellation
        $isBefore = in_array($event, ['creating', 'updating', 'saving', 'deleting'], true);

        if ($isBefore) {
            // Use filter: pipe through callbacks, if any returns false => cancel
            $result = Hooks::apply_filter($hookName, true, $this);
            return $result !== false;
        }

        // "After" events: just fire
        Hooks::do_action($hookName, $this);
        return true;
    }

    /**
     * Build the hook name for a model event.
     * Format: model.{ClassName}.{event}
     */
    private static function getModelEventHookName(string $event): string
    {
        // Use short class name (without namespace) for cleaner hook names
        $class = static::class;
        $short = str_contains($class, '\\') ? substr($class, strrpos($class, '\\') + 1) : $class;

        return 'model.' . $short . '.' . $event;
    }
}
