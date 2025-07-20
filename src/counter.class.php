<?php

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/database.php';

use Libsql\Connection;
use Dotenv\Dotenv;

// Load environment variables
$dotenv = Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->safeLoad();

class Counter
{
    private Connection $conn;
    private Connection $cache_db_conn;
    private int $limit = 1_000_000;

    public function __construct(Connection $db_connection, Connection $cache_db_connection)
    {
        $this->conn = $db_connection;
        $this->cache_db_conn = $cache_db_connection;
        $this->setup();
    }

    private function setup(): void
    {
        // Step 1: Ensure cache table always exists
        $this->cache_db_conn->executeBatch("
            CREATE TABLE IF NOT EXISTS counter (
                id TEXT PRIMARY KEY,
                value INTEGER NOT NULL DEFAULT 0
            );
        ");

        // Step 2: Check remote (main) DB existence
        $remote_exists_stmt = $this->conn->prepare("
            SELECT 1 as `status`
                WHERE EXISTS (
                    SELECT 1
                    FROM sqlite_master 
                    WHERE type = 'table' AND name = 'counter'
                );
        ");
        $remote_table_exists = !empty($remote_exists_stmt->query()->fetchArray());

        // Step 3: If remote table exists, fetch counter value
        $remote_value = null;
        if ($remote_table_exists) {
            $remote_row = $this->conn->prepare("
            SELECT value FROM counter WHERE id = ?
        ")->bind(['main'])->query()->fetchArray();

            if (!empty($remote_row)) {
                $remote_value = $remote_row[0]['value'];
            } else {
                // Initialize remote table if empty and not deleted
                $this->conn->prepare("
                INSERT INTO counter (id, value) VALUES (?, ?)
            ")->bind(['main', 0])->execute();
                $remote_value = 0;
            }
        }

        // Step 4: Handle cache DB state
        $cache_row = $this->cache_db_conn->prepare("
            SELECT value FROM counter WHERE id = ?
        ")->bind(['main'])->query()->fetchArray();

        if (!empty($cache_row)) return; // Cache already initialized

        // Step 5: If remote DB doesn't exist, mark deleted in cache with -1
        $cache_value = $remote_table_exists ? $remote_value : -1;

        $this->cache_db_conn->prepare("
            INSERT INTO counter (id, value) VALUES (?, ?)
        ")->bind(['main', $cache_value])->execute();
    }

    public function get(): int
    {
        try {
            // Use cache database for instant retrieval
            $rows = $this->cache_db_conn->prepare("SELECT `value` FROM `counter` WHERE id = ?")
            ->bind(['main'])
            ->query()
            ->fetchArray();

            return (int) ($rows[0]['value'] ?? 0);
        } catch (\Throwable $th) {
            return -1;
        }
    }

    public function increment(): int
    {
        $tx = $this->conn->transaction();
        $cache_tx = $this->cache_db_conn->transaction();

        try {
            $row = $tx->prepare("SELECT value FROM counter WHERE id = ?")
                ->bind(['main'])
                ->query()
                ->fetchArray();

            $current = (int) ($row[0]['value'] ?? -2);
            $next = $current + 1;

            if ($next >= $this->limit) {
                // Reset main database
                $tx->execute("DROP TABLE IF EXISTS counter");
                $tx->commit();

                // Reset cache database
                $cache_tx->execute("DROP TABLE IF EXISTS counter");
                $cache_tx->commit();

                return -1;
            }

            // Update main database
            $tx->prepare("UPDATE counter SET value = ? WHERE id = ?")
                ->bind([$next, 'main'])
                ->execute();
            $tx->commit();

            // Update cache database
            $cache_tx->prepare("UPDATE counter SET value = ? WHERE id = ?")
                ->bind([$next, 'main'])
                ->execute();
            $cache_tx->commit();

            return $next;
        } catch (\Throwable $e) {
            $tx->rollback();
            $cache_tx->rollback();
            return -1;
        }
    }
}