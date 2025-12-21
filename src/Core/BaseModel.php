<?php
declare(strict_types=1);

namespace Core;

use System\Database\Model;

/**
 * BaseModel
 *
 * Framework-level base model for all Yantra Core & App models.
 * Sits on top of System\Database\Model and adds only *very* generic helpers.
 */
abstract class BaseModel extends Model
{
    /**
     * @param string $table      Database table name
     * @param string $primaryKey Primary key column name
     */
    public function __construct(string $table, string $primaryKey = 'id')
    {
        parent::__construct($table, $primaryKey);
    }

    /**
     * Convenience scope for "active" rows, assuming a status=1 convention.
     */
    public function onlyActive(): static
    {
        return $this->where('status', '=', 1);
    }

    /**
     * Convenience scope for slug-based lookups.
     */
    public function withSlug(string $slug): static
    {
        return $this->where('slug', '=', $slug);
    }
}
