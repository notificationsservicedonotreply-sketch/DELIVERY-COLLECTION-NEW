<?php
declare(strict_types=1);

/**
 * Thin PDO connection manager. Reuses one connection per request per DB key
 * (unchanged behavior from the original connection.php).
 */
class Database
{
    private static array $connections = [];
    private static array $config = [];

    public static function setConfig(array $config): void
    {
        self::$config = $config;
    }

    public static function getConnection(string $dbKey): PDO
    {
        if (!isset(self::$config[$dbKey])) {
            throw new RuntimeException("Database configuration for '{$dbKey}' not found.");
        }

        if (!isset(self::$connections[$dbKey])) {
            try {
                $dbConfig = self::$config[$dbKey];
                $dsn = "sqlsrv:Server={$dbConfig['serverName']};Database={$dbConfig['databaseName']}";
                $pdo = new PDO($dsn, $dbConfig['userName'], $dbConfig['password']);
                $pdo->setAttribute(PDO::ATTR_PERSISTENT, false);
                $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                self::$connections[$dbKey] = $pdo;
            } catch (PDOException $e) {
                error_log('Database connection error: ' . $e->getMessage());
                http_response_code(503);
                die('Database connection failed. Please call IT.');
            }
        }

        return self::$connections[$dbKey];
    }
}
