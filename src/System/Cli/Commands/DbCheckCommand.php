<?php
declare(strict_types=1);

namespace System\Cli\Commands;

use System\Cli\AbstractCommand;
use System\Cli\Input;
use System\Cli\Output;
use System\Cli\Style;
use System\Config;
use System\Database\Database;

final class DbCheckCommand extends AbstractCommand
{
    public function name(): string { return 'db:check'; }

    public function description(): string
    {
        return 'Check database connectivity and basic readiness (safe, read-only).';
    }

    public function usage(): array
    {
        return [
            "yantra db:check",
        ];
    }

    public function run(Input $in, Output $out): int
    {
        try {
            $pdo = Database::pdo();
        } catch (\Throwable $e) {
            $out->error(Style::err("DB connection failed: " . $e->getMessage()));
            return 2;
        }

        try {
            // Connectivity check
            $pdo->query("SELECT 1")->fetchColumn();

            $driver  = (string) $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
            $server  = (string) $pdo->getAttribute(\PDO::ATTR_SERVER_VERSION);

            $dbName = null;
            try {
                $dbName = $pdo->query("SELECT DATABASE()")->fetchColumn();
            } catch (\Throwable $e) {
                // Some drivers may not support it; ignore.
            }

            // Character set / collation (MySQL/MariaDB)
            $charset = null;
            $collation = null;
            try {
                $charset = $pdo->query("SELECT @@character_set_database")->fetchColumn();
                $collation = $pdo->query("SELECT @@collation_database")->fetchColumn();
            } catch (\Throwable $e) {
                // ignore for non-mysql drivers
            }

            // Check if migrations table exists (read-only)
            $hasMigrationsTable = false;
            try {
                $stmt = $pdo->query("SHOW TABLES LIKE 'yt_migrations'");
                $hasMigrationsTable = (bool) ($stmt && $stmt->fetchColumn());
            } catch (\Throwable $e) {
                // If non-mysql, skip this check
            }

            $out->writeln(Style::ok("DB OK") . " Connection established.");
            $out->writeln("Driver: {$driver}");
            $out->writeln("Server: {$server}");
            if ($dbName !== null && $dbName !== '') {
                $out->writeln("Database: {$dbName}");
            }
            if ($charset !== null && $charset !== '') {
                $out->writeln("Charset: {$charset}");
            }
            if ($collation !== null && $collation !== '') {
                $out->writeln("Collation: {$collation}");
            }

            if ($hasMigrationsTable) {
                $out->writeln(Style::ok("Migrations table") . " yt_migrations found.");
            } else {
                $out->writeln(Style::warn("Migrations table") . " yt_migrations not found (run: yantra migrate).");
            }

            // Optional: show current environment (useful in ops)
            $app = (array) Config::get('app');
            $env = (string) ($app['environment'] ?? 'production');
            $out->writeln("Environment: {$env}");

            return 0;
        } catch (\Throwable $e) {
            $out->error(Style::err("DB check failed: " . $e->getMessage()));
            return 1;
        }
    }
}
