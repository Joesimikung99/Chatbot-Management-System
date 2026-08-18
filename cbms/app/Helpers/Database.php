<?php
/**
 * Database Helper — PDO Singleton
 * AI Chatbot System — CBMS
 *
 * Usage:
 *   $db = Database::getInstance();
 *   $rows = $db->fetchAll("SELECT * FROM ai_models WHERE is_active = ?", [1]);
 */

namespace App\Helpers;

use PDO;
use PDOException;
use PDOStatement;
use RuntimeException;

class Database
{
    private static ?Database $instance = null;
    private PDO $pdo;
    private string $logPath;

    // ----------------------------------------------------------------
    // Singleton
    // ----------------------------------------------------------------

    private function __construct()
    {
        $this->logPath = dirname(__DIR__, 2) . '/storage/logs/db_errors.log';

        $config = require dirname(__DIR__, 2) . '/config/database.php';
        $cfg    = $config['connections']['mysql'];

        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $cfg['host'],
            $cfg['port'],
            $cfg['database'],
            $cfg['charset']
        );

        try {
            $this->pdo = new PDO($dsn, $cfg['username'], $cfg['password'], $cfg['options']);
        } catch (PDOException $e) {
            $this->logError('Connection failed: ' . $e->getMessage());
            throw new RuntimeException('Database connection failed. Check logs.');
        }
    }

    public static function getInstance(): static
    {
        if (static::$instance === null) {
            static::$instance = new static();
        }
        return static::$instance;
    }

    // Prevent cloning
    private function __clone() {}

    // ----------------------------------------------------------------
    // Core Query Methods
    // ----------------------------------------------------------------

    /**
     * Execute a query and return the PDOStatement.
     */
    public function query(string $sql, array $params = []): PDOStatement
    {
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt;
        } catch (PDOException $e) {
            $this->logError("Query failed: {$e->getMessage()} | SQL: {$sql}");
            throw new RuntimeException('Database query failed: ' . $e->getMessage());
        }
    }

    /**
     * Fetch a single row as associative array, or null if not found.
     */
    public function fetch(string $sql, array $params = []): ?array
    {
        $result = $this->query($sql, $params)->fetch(PDO::FETCH_ASSOC);
        return $result === false ? null : $result;
    }

    /**
     * Fetch all rows as array of associative arrays.
     */
    public function fetchAll(string $sql, array $params = []): array
    {
        return $this->query($sql, $params)->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Fetch a single column value from the first row.
     */
    public function fetchColumn(string $sql, array $params = []): mixed
    {
        $result = $this->query($sql, $params)->fetchColumn();
        return $result === false ? null : $result;
    }

    // ----------------------------------------------------------------
    // Write Methods
    // ----------------------------------------------------------------

    /**
     * Insert a row into a table.
     * Returns the last insert ID.
     */
    public function insert(string $table, array $data): int|string
    {
        $columns      = implode(', ', array_map(fn($c) => $this->quoteIdentifier($c), array_keys($data)));
        $placeholders = implode(', ', array_fill(0, count($data), '?'));
        $sql          = "INSERT INTO {$this->quoteIdentifier($table)} ({$columns}) VALUES ({$placeholders})";

        $this->query($sql, array_values($data));
        return $this->pdo->lastInsertId();
    }

    /**
     * Insert or update on duplicate key.
     * $updateData = columns to update on conflict (null = same as $data).
     */
    public function upsert(string $table, array $data, ?array $updateData = null): int|string
    {
        $updateData ??= $data;

        $columns      = implode(', ', array_map(fn($c) => $this->quoteIdentifier($c), array_keys($data)));
        $placeholders = implode(', ', array_fill(0, count($data), '?'));
        $updates      = implode(', ', array_map(fn($c) => "{$this->quoteIdentifier($c)} = VALUES({$this->quoteIdentifier($c)})", array_keys($updateData)));

        $sql = "INSERT INTO {$this->quoteIdentifier($table)} ({$columns}) VALUES ({$placeholders})
                ON DUPLICATE KEY UPDATE {$updates}";

        $this->query($sql, array_values($data));
        return $this->pdo->lastInsertId();
    }

    /**
     * Update rows matching $where conditions.
     * Returns number of affected rows.
     *
     * @param array $where ['column' => value, ...]
     */
    public function update(string $table, array $data, array $where): int
    {
        $setClauses   = implode(', ', array_map(fn($c) => "{$this->quoteIdentifier($c)} = ?", array_keys($data)));
        $whereClauses = implode(' AND ', array_map(fn($c) => "{$this->quoteIdentifier($c)} = ?", array_keys($where)));

        $sql    = "UPDATE {$this->quoteIdentifier($table)} SET {$setClauses} WHERE {$whereClauses}";
        $params = array_merge(array_values($data), array_values($where));

        return $this->query($sql, $params)->rowCount();
    }

    /**
     * Delete rows matching $where conditions.
     * Returns number of affected rows.
     */
    public function delete(string $table, array $where): int
    {
        $whereClauses = implode(' AND ', array_map(fn($c) => "{$this->quoteIdentifier($c)} = ?", array_keys($where)));
        $sql          = "DELETE FROM {$this->quoteIdentifier($table)} WHERE {$whereClauses}";

        return $this->query($sql, array_values($where))->rowCount();
    }

    // ----------------------------------------------------------------
    // Transactions
    // ----------------------------------------------------------------

    public function beginTransaction(): bool
    {
        return $this->pdo->beginTransaction();
    }

    public function commit(): bool
    {
        return $this->pdo->commit();
    }

    public function rollback(): bool
    {
        return $this->pdo->rollBack();
    }

    /**
     * Execute a callable inside a transaction.
     * Rolls back automatically on exception.
     */
    public function transaction(callable $callback): mixed
    {
        $this->beginTransaction();
        try {
            $result = $callback($this);
            $this->commit();
            return $result;
        } catch (\Throwable $e) {
            $this->rollback();
            throw $e;
        }
    }

    // ----------------------------------------------------------------
    // Utility
    // ----------------------------------------------------------------

    /**
     * Get the last inserted ID.
     */
    public function lastInsertId(): int|string
    {
        return $this->pdo->lastInsertId();
    }

    /**
     * Quote a value for safe use (prefer prepared statements instead).
     */
    public function quote(string $value): string
    {
        return $this->pdo->quote($value);
    }

    /**
     * Return the raw PDO instance for advanced usage.
     */
    public function getPdo(): PDO
    {
        return $this->pdo;
    }

    // ----------------------------------------------------------------
    // System Settings (cached)
    // ----------------------------------------------------------------

    private static array $sysSettingsCache = [];

    /**
     * Get a system_settings value (cached per-request).
     */
    public function setting(string $key, ?string $default = null): ?string
    {
        if (!array_key_exists($key, static::$sysSettingsCache)) {
            $val = $this->fetchColumn(
                "SELECT setting_value FROM system_settings WHERE setting_key = ?",
                [$key]
            );
            static::$sysSettingsCache[$key] = $val;
        }
        return static::$sysSettingsCache[$key] ?? $default;
    }

    /**
     * Get the app display name (system_settings → .env → fallback).
     */
    public static function appName(): string
    {
        try {
            $val = static::getInstance()->setting('app_name');
            if ($val !== null && $val !== '') return $val;
        } catch (\Throwable) {}
        return $_ENV['APP_NAME'] ?? 'AI Chatbot System';
    }

    // ----------------------------------------------------------------
    // Identifier Validation
    // ----------------------------------------------------------------

    private function validateIdentifier(string $name): string
    {
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $name)) {
            throw new RuntimeException("Invalid SQL identifier: {$name}");
        }
        return $name;
    }

    private function quoteIdentifier(string $name): string
    {
        return '`' . $this->validateIdentifier($name) . '`';
    }

    // ----------------------------------------------------------------
    // Logging
    // ----------------------------------------------------------------

    private function logError(string $message): void
    {
        $line = sprintf("[%s] %s\n", date('Y-m-d H:i:s'), $message);
        @file_put_contents($this->logPath, $line, FILE_APPEND | LOCK_EX);
    }
}
