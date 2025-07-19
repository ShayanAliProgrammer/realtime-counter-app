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
        // Setup main database
        $this->conn->executeBatch(
            "CREATE TABLE IF NOT EXISTS counter (
                id TEXT PRIMARY KEY,
                value INTEGER NOT NULL DEFAULT 0
            );"
        );

        // Setup cache database
        $this->cache_db_conn->executeBatch(
            "CREATE TABLE IF NOT EXISTS counter (
                id TEXT PRIMARY KEY,
                value INTEGER NOT NULL DEFAULT 0
            );"
        );

        $remote_db_rows = $this->conn->prepare("SELECT `value` FROM `counter` WHERE id = ?")
            ->bind(['main'])
            ->query()
            ->fetchArray();


        if (empty($remote_db_rows)) {
            // Initialize main database
            $this->conn->prepare("INSERT INTO `counter` (`id`, `value`) VALUES (?, ?)")
                ->bind(['main', 0])
                ->execute();
        }

        $rows = $this->cache_db_conn->prepare("SELECT `value` FROM `counter` WHERE id = ?")
            ->bind(['main'])
            ->query()
            ->fetchArray();

        if (! empty($rows)) return;

        // Initialize cache database
        $this->cache_db_conn->prepare("INSERT INTO `counter` (`id`, `value`) VALUES (?, ?)")
            ->bind(['main', $remote_db_rows[count($remote_db_rows) - 1]['value']])
            ->execute();
    }

    public function get(): int
    {
        // Use cache database for instant retrieval
        $row = $this->cache_db_conn->prepare("SELECT value FROM counter WHERE id = ?")
            ->bind(['main'])
            ->query()
            ->fetchArray();

        return (int) ($row[0]['value'] ?? 0);
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

            $current = (int) ($row[0]['value'] ?? 0);
            $next = $current + 1;

            if ($next >= $this->limit) {
                // Reset main database
                $tx->execute("DROP TABLE IF EXISTS counter");
                $tx->commit();

                // Reset cache database
                $cache_tx->execute("DROP TABLE IF EXISTS counter");
                $cache_tx->commit();

                // Recreate tables and initialize
                $this->conn->executeBatch(
                    "CREATE TABLE IF NOT EXISTS counter (
                        id TEXT PRIMARY KEY,
                        value INTEGER NOT NULL DEFAULT 0
                    );"
                );
                $this->conn->prepare("INSERT INTO counter (id, value) VALUES (?, ?)")
                    ->bind(['main', 0])
                    ->execute();

                $this->cache_db_conn->executeBatch(
                    "CREATE TABLE IF NOT EXISTS counter (
                        id TEXT PRIMARY KEY,
                        value INTEGER NOT NULL DEFAULT 0
                    );"
                );
                $this->cache_db_conn->prepare("INSERT INTO counter (id, value) VALUES (?, ?)")
                    ->bind(['main', 0])
                    ->execute();

                return 0;
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
            throw $e;
        }
    }
}