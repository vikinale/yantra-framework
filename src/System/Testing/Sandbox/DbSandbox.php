<?php
declare(strict_types=1);

namespace System\Testing\Sandbox;

use System\Database\Database;
use System\Database\ConnectionResolver;
use Exception;

class DbSandbox
{
    private bool $inTransaction = false;
    private ?Database $db = null;

    private function getDb(): Database
    {
        if ($this->db === null) {
            $this->db = ConnectionResolver::get();
        }
        return $this->db;
    }

    public function begin(): void
    {
        if ($this->inTransaction) {
            return;
        }
        
        $db = $this->getDb();
        $db->connect();
        
        try {
            // Check if already in transaction
            if ($db->inTransaction()) {
                return;
            }
            
            $db->_beginTransaction();
            $this->inTransaction = true;
        } catch (Exception $e) {
            throw new Exception("Failed to begin test transaction: " . $e->getMessage(), 0, $e);
        }
    }

    public function rollback(): void
    {
        if ($this->inTransaction) {
            try {
                $this->getDb()->_rollBack();
            } catch (Exception $e) {
                // ignore
            }
            $this->inTransaction = false;
        }
    } 
}
