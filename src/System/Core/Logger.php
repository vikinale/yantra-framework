<?php
declare(strict_types=1);

namespace System\Core;

use System\Contracts\LoggerInterface;

class Logger implements LoggerInterface
{
    private string $logFile;

    public function __construct(string $logFile)
    {
        $this->logFile = $logFile;
        // Ensure directory exists
        $dir = dirname($this->logFile);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
    }

    public function debug(string $message, array $context = []): void
    {
        $this->log('DEBUG', $message, $context);
    }

    public function error(string $message, array $context = []): void
    {
        $this->log('ERROR', $message, $context);
    }

    private function log(string $level, string $message, array $context = []): void
    {
        $date = date('Y-m-d H:i:s');
        $contextStr = empty($context) ? '' : json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $logEntry = sprintf("[%s] %s: %s %s" . PHP_EOL, $date, $level, $message, $contextStr);
        
        @file_put_contents($this->logFile, $logEntry, FILE_APPEND | LOCK_EX);
    }
}
