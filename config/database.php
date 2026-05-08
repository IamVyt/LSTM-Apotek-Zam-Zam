<?php
/**
 * Database Connection - PDO Singleton Pattern
 * Apotek Zam Zam - Pharmacy Drug Inventory Prediction System
 */

class Database {
    private static ?Database $instance = null;
    private PDO $connection;

    private string $host = 'localhost';
    private string $db_name = 'pharmapredictt';
    private string $username = 'root';
    private string $password = '';
    private string $charset = 'utf8mb4';

    private function __construct() {
        try {
            $dsn = "mysql:host={$this->host};dbname={$this->db_name};charset={$this->charset}";
            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ];
            $this->connection = new PDO($dsn, $this->username, $this->password, $options);
        } catch (PDOException $e) {
            error_log("Database Connection Error: " . $e->getMessage());
            die("Koneksi database gagal. Silakan periksa konfigurasi database.");
        }
    }

    public static function getInstance(): self {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function getConnection(): PDO {
        return $this->connection;
    }

    private function __clone() {}
}

/**
 * Helper function to get PDO connection
 */
function getDB(): PDO {
    return Database::getInstance()->getConnection();
}
