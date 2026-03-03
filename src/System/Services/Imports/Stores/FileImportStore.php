<?php
declare(strict_types=1);

namespace System\Services\Imports\Stores;

use System\Services\\Imports\Contracts\ImportStoreInterface;
use System\Services\\Imports\Model\ImportState;
use System\Services\\Imports\Model\ImportError;
use System\Services\\Imports\Model\RollbackRecord;

final class FileImportStore implements ImportStoreInterface
{
    public function __construct(private string $baseDir)
    {
        $d = rtrim($baseDir,'/\\');
        if (!is_dir($d)) mkdir($d, 0775, true);
        $this->baseDir = $d;
    }

    private function stateFile(string $id): string { return $this->baseDir . DIRECTORY_SEPARATOR . $id . '.state.json'; }
    private function errFile(string $id): string { return $this->baseDir . DIRECTORY_SEPARATOR . $id . '.errors.jsonl'; }
    private function rbFile(string $id): string { return $this->baseDir . DIRECTORY_SEPARATOR . $id . '.rollback.jsonl'; }

    public function create(ImportState $state): void { $this->writeState($state); }
    public function update(ImportState $state): void { $this->writeState($state); }

    public function get(string $importId): ?ImportState
    {
        $f = $this->stateFile($importId);
        if (!is_file($f)) return null;
        $raw = file_get_contents($f);
        if ($raw === false) return null;
        $a = json_decode($raw, true);
        if (!is_array($a)) return null;
        return new ImportState(
            id: (string)($a['id'] ?? $importId),
            sourcePath: (string)($a['sourcePath'] ?? ''),
            status: (string)($a['status'] ?? 'created'),
            totalRows: (int)($a['totalRows'] ?? 0),
            processedRows: (int)($a['processedRows'] ?? 0),
            failedRows: (int)($a['failedRows'] ?? 0),
            meta: (array)($a['meta'] ?? []),
            createdAtUnix: (int)($a['createdAtUnix'] ?? 0),
            updatedAtUnix: (int)($a['updatedAtUnix'] ?? 0)
        );
    }

    public function addError(string $importId, ImportError $error): void
    {
        $line = json_encode([
            'rowNumber'=>$error->rowNumber,'field'=>$error->field,'message'=>$error->message,'meta'=>$error->meta,
        ], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        file_put_contents($this->errFile($importId), $line."\n", FILE_APPEND);
    }

    public function addRollback(string $importId, RollbackRecord $record): void
    {
        $line = json_encode(['type'=>$record->type,'payload'=>$record->payload,'createdAt'=>$record->createdAtUnix], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        file_put_contents($this->rbFile($importId), $line."\n", FILE_APPEND);
    }

    public function listRollbacks(string $importId): array
    {
        $f = $this->rbFile($importId);
        if (!is_file($f)) return [];
        $lines = file($f, FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES);
        if (!is_array($lines)) return [];
        $out=[];
        foreach ($lines as $ln) {
            $a = json_decode($ln, true);
            if (!is_array($a)) continue;
            $out[] = new RollbackRecord((string)($a['type'] ?? ''), (array)($a['payload'] ?? []), (int)($a['createdAt'] ?? 0));
        }
        return $out;
    }

    private function writeState(ImportState $state): void
    {
        $state->updatedAtUnix = time();
        file_put_contents($this->stateFile($state->id), json_encode([
            'id'=>$state->id,'sourcePath'=>$state->sourcePath,'status'=>$state->status,
            'totalRows'=>$state->totalRows,'processedRows'=>$state->processedRows,'failedRows'=>$state->failedRows,
            'meta'=>$state->meta,'createdAtUnix'=>$state->createdAtUnix,'updatedAtUnix'=>$state->updatedAtUnix,
        ], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
    }
}
