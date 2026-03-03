<?php
declare(strict_types=1);

namespace System\Database\Relations;

use System\Database\Database;
use System\Database\ConnectionResolver;
use System\Database\Model;
use System\Support\Collection;

/**
 * BelongsToMany — Many-to-many relationship via pivot table.
 *
 * Supports attach, detach, and sync operations for pivot table management.
 *
 * Usage:
 *   // In Model::relations()
 *   return [
 *       'roles' => ['belongsToMany', Role::class, 'user_roles', 'user_id', 'role_id'],
 *   ];
 *
 *   $user->roles;                      // Collection of Role models
 *   $user->roles()->attach([1, 2, 3]); // Add pivot rows
 *   $user->roles()->detach([2]);       // Remove pivot rows
 *   $user->roles()->sync([1, 3, 5]);   // Set exact pivot rows
 */
class BelongsToMany
{
    private Model $parent;
    private string $related;
    private string $pivotTable;
    private string $foreignPivotKey;
    private string $relatedPivotKey;
    private string $parentKey;
    private string $relatedKey;
    private Database $db;

    /**
     * @param Model  $parent          The parent model instance.
     * @param string $related         FQCN of the related model.
     * @param string $pivotTable      Pivot table name.
     * @param string $foreignPivotKey Foreign key for the parent model on the pivot table.
     * @param string $relatedPivotKey Foreign key for the related model on the pivot table.
     * @param string $parentKey       Primary key on the parent model (default: 'id').
     * @param string $relatedKey      Primary key on the related model (default: 'id').
     */
    public function __construct(
        Model $parent,
        string $related,
        string $pivotTable,
        string $foreignPivotKey,
        string $relatedPivotKey,
        string $parentKey = 'id',
        string $relatedKey = 'id'
    ) {
        $this->parent          = $parent;
        $this->related         = $related;
        $this->pivotTable      = $pivotTable;
        $this->foreignPivotKey = $foreignPivotKey;
        $this->relatedPivotKey = $relatedPivotKey;
        $this->parentKey       = $parentKey;
        $this->relatedKey      = $relatedKey;
        $this->db              = ConnectionResolver::get();
    }

    /**
     * Get all related models.
     *
     * @return Collection<int, Model>
     */
    public function get(): Collection
    {
        $parentId = $this->parent->attributes[$this->parentKey] ?? null;
        if ($parentId === null) {
            return new Collection();
        }

        // Get related IDs from pivot table
        $sql = "SELECT `{$this->relatedPivotKey}` FROM `{$this->pivotTable}` WHERE `{$this->foreignPivotKey}` = ?";
        $rows = $this->db->fetchAll($sql, [$parentId]);

        if (empty($rows)) {
            return new Collection();
        }

        $relatedIds = array_column($rows, $this->relatedPivotKey);

        // Fetch related models
        $relatedInstance = new $this->related();
        $models = $relatedInstance->whereIn($this->relatedKey, $relatedIds)->getModels();

        return new Collection($models);
    }

    /**
     * Attach related model IDs (add pivot rows).
     *
     * @param array<int|string> $ids Related model IDs to attach.
     * @param array<string, mixed> $attributes Extra pivot columns to set.
     */
    public function attach(array $ids, array $attributes = []): void
    {
        $parentId = $this->parent->attributes[$this->parentKey] ?? null;
        if ($parentId === null || $ids === []) {
            return;
        }

        foreach ($ids as $relatedId) {
            $data = array_merge($attributes, [
                $this->foreignPivotKey => $parentId,
                $this->relatedPivotKey => $relatedId,
            ]);

            $columns = array_keys($data);
            $placeholders = implode(', ', array_fill(0, count($data), '?'));
            $columnList = implode(', ', array_map(fn($c) => "`{$c}`", $columns));

            $sql = "INSERT IGNORE INTO `{$this->pivotTable}` ({$columnList}) VALUES ({$placeholders})";
            $this->db->execute($sql, array_values($data));
        }
    }

    /**
     * Detach related model IDs (remove pivot rows).
     *
     * @param array<int|string>|null $ids Related model IDs to detach. Null = detach all.
     */
    public function detach(?array $ids = null): void
    {
        $parentId = $this->parent->attributes[$this->parentKey] ?? null;
        if ($parentId === null) {
            return;
        }

        if ($ids === null) {
            // Detach all
            $sql = "DELETE FROM `{$this->pivotTable}` WHERE `{$this->foreignPivotKey}` = ?";
            $this->db->execute($sql, [$parentId]);
            return;
        }

        if ($ids === []) {
            return;
        }

        $placeholders = implode(', ', array_fill(0, count($ids), '?'));
        $sql = "DELETE FROM `{$this->pivotTable}` WHERE `{$this->foreignPivotKey}` = ? AND `{$this->relatedPivotKey}` IN ({$placeholders})";
        $this->db->execute($sql, array_merge([$parentId], array_values($ids)));
    }

    /**
     * Sync related model IDs (set exact pivot rows).
     *
     * Attaches new IDs and detaches any that are no longer in the list.
     *
     * @param array<int|string> $ids The exact set of related model IDs.
     * @param array<string, mixed> $attributes Extra pivot columns for new entries.
     */
    public function sync(array $ids, array $attributes = []): void
    {
        $parentId = $this->parent->attributes[$this->parentKey] ?? null;
        if ($parentId === null) {
            return;
        }

        // Get currently attached IDs
        $sql = "SELECT `{$this->relatedPivotKey}` FROM `{$this->pivotTable}` WHERE `{$this->foreignPivotKey}` = ?";
        $rows = $this->db->fetchAll($sql, [$parentId]);
        $currentIds = array_column($rows, $this->relatedPivotKey);

        // Calculate diff
        $toAttach = array_diff($ids, $currentIds);
        $toDetach = array_diff($currentIds, $ids);

        if (!empty($toDetach)) {
            $this->detach($toDetach);
        }

        if (!empty($toAttach)) {
            $this->attach($toAttach, $attributes);
        }
    }

    /**
     * Check if a given related model ID is attached.
     */
    public function contains(int|string $relatedId): bool
    {
        $parentId = $this->parent->attributes[$this->parentKey] ?? null;
        if ($parentId === null) {
            return false;
        }

        $sql = "SELECT 1 FROM `{$this->pivotTable}` WHERE `{$this->foreignPivotKey}` = ? AND `{$this->relatedPivotKey}` = ? LIMIT 1";
        $row = $this->db->fetch($sql, [$parentId, $relatedId]);
        return $row !== null;
    }

    /**
     * Get the count of related models.
     */
    public function count(): int
    {
        $parentId = $this->parent->attributes[$this->parentKey] ?? null;
        if ($parentId === null) {
            return 0;
        }

        $sql = "SELECT COUNT(*) AS c FROM `{$this->pivotTable}` WHERE `{$this->foreignPivotKey}` = ?";
        $row = $this->db->fetch($sql, [$parentId]);
        return (int) ($row['c'] ?? 0);
    }
}
