<?php
declare(strict_types=1);

namespace System\Database;

use PDO;
use PDOStatement;

/**
 * ConnectionProxy
 *
 * A {@see Database} that owns no PDO connection of its own and instead delegates
 * every I/O call, at call time, to whatever Database is currently bound under its
 * name in {@see ConnectionManager} (typically 'branch').
 *
 * Why it `extends Database`: the DI container caches every resolved service
 * (singleton-per-id). A branch-scoped service injected with a *snapshot* Database
 * would freeze whichever branch happened to be bound when the container first
 * built it. By injecting a ConnectionProxy('branch') instead, a container-cached
 * service always talks to the request's currently-bound branch DB. Extending
 * Database means the proxy satisfies the ~60 existing `Database` constructor
 * type-hints across bbase/ybank with zero signature churn.
 *
 * The parent constructor is deliberately NOT invoked (no config, no PDO); every
 * method that would touch parent state is overridden to delegate.
 */
final class ConnectionProxy extends Database
{
    private string $name;

    /** @noinspection PhpMissingParentConstructorInspection — intentional: proxy owns no connection */
    public function __construct(string $name = 'branch')
    {
        $this->name = $name;
    }

    /** Resolve the currently-bound real connection for this proxy's name. */
    private function target(): Database
    {
        return ConnectionManager::get($this->name);
    }

    /** Name this proxy delegates to (diagnostics/tests). */
    public function connectionName(): string
    {
        return $this->name;
    }

    public function connect(): void
    {
        $this->target()->connect();
    }

    public function prepare(string $query, array $options = []): PDOStatement
    {
        return $this->target()->prepare($query, $options);
    }

    public function execute(string $query, array $params = []): PDOStatement
    {
        return $this->target()->execute($query, $params);
    }

    public function fetch(string $query, array $params = []): ?array
    {
        return $this->target()->fetch($query, $params);
    }

    public function fetchOne(string $query, array $params = []): ?array
    {
        return $this->target()->fetchOne($query, $params);
    }

    public function fetchAll(string $query, array $params = []): array
    {
        return $this->target()->fetchAll($query, $params);
    }

    public function exec(string $sql): int|false
    {
        return $this->target()->exec($sql);
    }

    public function lastInsertId(): string|false
    {
        return $this->target()->lastInsertId();
    }

    public function _beginTransaction(): bool
    {
        return $this->target()->_beginTransaction();
    }

    public function _commit(): bool
    {
        return $this->target()->_commit();
    }

    public function _rollBack(): bool
    {
        return $this->target()->_rollBack();
    }

    public function _inTransaction(): bool
    {
        return $this->target()->_inTransaction();
    }

    public function quote(string $string, int $type = PDO::PARAM_STR): string
    {
        return $this->target()->quote($string, $type);
    }

    public function getAttribute(int $attribute): mixed
    {
        return $this->target()->getAttribute($attribute);
    }

    public function getAdapter(): DatabaseAdapter
    {
        return $this->target()->getAdapter();
    }
}
