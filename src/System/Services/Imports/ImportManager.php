<?php
declare(strict_types=1);

namespace System\Services\Imports;

use System\Services\Imports\Contracts\ImportStoreInterface;
use System\Services\Imports\Csv\CsvReader;
use System\Services\Imports\Model\ImportState;
use System\Services\Imports\Model\ImportError;

final class ImportManager
{
    /** @param array<string,mixed> $contextExtras */
    public function __construct(private ImportStoreInterface $store, private array $contextExtras = []) {}

    public function create(string $sourcePath, array $meta = []): ImportState
    {
        $id = bin2hex(random_bytes(16));
        $state = new ImportState($id, $sourcePath, 'created', meta: $meta);
        $this->store->create($state);
        return $state;
    }

    public function runInline(ImportDefinition $def, string $importId): ImportState
    {
        $state = $this->store->get($importId);
        if (!$state) throw new \RuntimeException("Import not found: {$importId}");

        $state->status = 'running';
        $state->updatedAtUnix = time();
        $this->store->update($state);

        $ctx = new ImportContext($importId, $this->contextExtras);

        $reader = new CsvReader($state->sourcePath);
        $header = [];
        foreach ($reader as $n => $row) {
            if ($n === 1) { $header = $row; continue; }

            $mapped = $def->mapping->apply($header, $row);

            foreach ($def->validators as $v) {
                $vr = $v->validate($mapped);
                if (!$vr->ok) {
                    $state->failedRows++;
                    foreach ($vr->errors as $e) {
                        $this->store->addError($importId, new ImportError($n, $e->field, $e->message, $e->meta));
                    }
                    continue 2;
                }
            }

            $out = $def->processor->process($mapped, $ctx);
            if ($out->rollback) $this->store->addRollback($importId, $out->rollback);

            if ($out->ok) $state->processedRows++; else $state->failedRows++;

            if (($state->processedRows + $state->failedRows) % 200 === 0) {
                $state->updatedAtUnix = time();
                $this->store->update($state);
            }
        }

        $state->totalRows = max($state->totalRows, $state->processedRows + $state->failedRows);
        $state->status = 'completed';
        $state->updatedAtUnix = time();
        $this->store->update($state);
        return $state;
    }

    public function rollback(ImportDefinition $def, string $importId): ImportState
    {
        $state = $this->store->get($importId);
        if (!$state) throw new \RuntimeException("Import not found: {$importId}");
        if (!$def->rollbackHandler) throw new \RuntimeException('Rollback handler not configured');

        $ctx = new ImportContext($importId, $this->contextExtras);
        $records = array_reverse($this->store->listRollbacks($importId));
        foreach ($records as $rec) $def->rollbackHandler->rollback($rec, $ctx);

        $state->status = 'rolled_back';
        $state->updatedAtUnix = time();
        $this->store->update($state);
        return $state;
    }
}
