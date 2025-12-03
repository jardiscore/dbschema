<?php

declare(strict_types=1);

namespace JardisCore\DbSchema\Tests;

use PDO;

class SchemaBuilder
{
    /**
     * Create a test schema for MySQL
     */
    public static function createMySqlTestSchema(PDO $pdo): void
    {
        self::dropMySqlTestSchema($pdo);

        $pdo->exec("
            CREATE TABLE users (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(255) NOT NULL,
                email VARCHAR(255) UNIQUE NOT NULL,
                age TINYINT UNSIGNED,
                is_active BOOLEAN DEFAULT TRUE,
                balance DECIMAL(10,2) DEFAULT 0.00,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB
        ");

        $pdo->exec("
            CREATE TABLE orders (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                order_number VARCHAR(50) NOT NULL,
                total DECIMAL(10,2) NOT NULL,
                status VARCHAR(20) DEFAULT 'pending',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT fk_orders_user_id 
                    FOREIGN KEY (user_id) REFERENCES users(id) 
                    ON DELETE CASCADE ON UPDATE CASCADE,
                INDEX idx_user_id (user_id),
                INDEX idx_status (status)
            ) ENGINE=InnoDB
        ");

        self::insertTestData($pdo);
    }

    /**
     * Create a test schema for PostgresSQL
     */
    public static function createPostgresTestSchema(PDO $pdo): void
    {
        self::dropPostgresTestSchema($pdo);

        $pdo->exec("
            CREATE TABLE users (
                id SERIAL PRIMARY KEY,
                name VARCHAR(255) NOT NULL,
                email VARCHAR(255) UNIQUE NOT NULL,
                age SMALLINT,
                is_active BOOLEAN DEFAULT TRUE,
                balance DECIMAL(10,2) DEFAULT 0.00,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP
            )
        ");

        $pdo->exec("
            CREATE TABLE orders (
                id BIGSERIAL PRIMARY KEY,
                user_id INTEGER NOT NULL,
                order_number VARCHAR(50) NOT NULL,
                total DECIMAL(10,2) NOT NULL,
                status VARCHAR(20) DEFAULT 'pending',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT fk_orders_user_id 
                    FOREIGN KEY (user_id) REFERENCES users(id) 
                    ON DELETE CASCADE ON UPDATE CASCADE
            )
        ");

        $pdo->exec("CREATE INDEX idx_orders_user_id ON orders(user_id)");
        $pdo->exec("CREATE INDEX idx_orders_status ON orders(status)");

        self::insertTestData($pdo);
    }

    /**
     * Create a test schema for SQLite
     */
    public static function createSqliteTestSchema(PDO $pdo): void
    {
        // SQLite doesn't need DROP - using :memory: DB

        $pdo->exec("PRAGMA foreign_keys = ON");

        $pdo->exec("
            CREATE TABLE users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                email TEXT UNIQUE NOT NULL,
                age INTEGER,
                is_active INTEGER DEFAULT 1,
                balance REAL DEFAULT 0.00,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                updated_at TEXT
            )
        ");

        $pdo->exec("
            CREATE TABLE orders (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                order_number TEXT NOT NULL,
                total REAL NOT NULL,
                status TEXT DEFAULT 'pending',
                created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES users(id) 
                    ON DELETE CASCADE ON UPDATE CASCADE
            )
        ");

        $pdo->exec("CREATE INDEX idx_orders_user_id ON orders(user_id)");
        $pdo->exec("CREATE INDEX idx_orders_status ON orders(status)");

        self::insertTestData($pdo);
    }

    /**
     * Insert test data with database-specific boolean handling
     */
    private static function insertTestData(PDO $pdo): void
    {
        // Get database driver for proper boolean handling
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

        // Insert users with a proper boolean conversion
        $stmt = $pdo->prepare("
            INSERT INTO users (name, email, age, is_active, balance) VALUES (?, ?, ?, ?, ?)
        ");

        $users = [
            ['John Doe', 'john@example.com', 30, true, 150.50],
            ['Jane Smith', 'jane@example.com', 25, false, 0.00],
            ['Bob Wilson', 'bob@example.com', 35, true, 250.75]
        ];

        foreach ($users as $user) {
            // Convert boolean to appropriate format for the database
            if ($driver === 'sqlite') {
                $user[3] = $user[3] ? 1 : 0; // SQLite uses integers for booleans
            } else {
                $user[3] = $user[3] ? 1 : 0; // MySQL also works better with explicit 1/0
            }

            $stmt->execute($user);
        }

        // Insert orders - works for all databases
        $stmt = $pdo->prepare("
            INSERT INTO orders (user_id, order_number, total, status) VALUES (?, ?, ?, ?)
        ");

        $orders = [
            [1, 'ORD-001', 99.99, 'completed'],
            [1, 'ORD-002', 149.50, 'pending'],
            [2, 'ORD-003', 75.00, 'cancelled'],
            [3, 'ORD-004', 199.99, 'completed']
        ];

        foreach ($orders as $order) {
            $stmt->execute($order);
        }
    }

    /**
     * Clean up MySQL test schema
     */
    public static function dropMySqlTestSchema(PDO $pdo): void
    {
        $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");
        $pdo->exec("DROP TABLE IF EXISTS orders");
        $pdo->exec("DROP TABLE IF EXISTS users");
        $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");
    }

    /**
     * Clean up PostgreSQL test schema
     */
    public static function dropPostgresTestSchema(PDO $pdo): void
    {
        $pdo->exec("DROP TABLE IF EXISTS orders CASCADE");
        $pdo->exec("DROP TABLE IF EXISTS users CASCADE");
    }

    /**
     * SQLite cleanup is not needed (using :memory: database)
     */
    public static function dropSqliteTestSchema(PDO $pdo): void
    {
        // Not needed for :memory: databases
        // But for completeness:
        $pdo->exec("DROP TABLE IF EXISTS orders");
        $pdo->exec("DROP TABLE IF EXISTS users");
    }

    /**
     * Get expected test data for assertions
     */
    public static function getExpectedTables(): array
    {
        return ['users', 'orders'];
    }

    /**
     * Get expected user columns for assertions
     */
    public static function getExpectedUserColumns(): array
    {
        return ['id', 'name', 'email', 'age', 'is_active', 'balance', 'created_at', 'updated_at'];
    }

    /**
     * Get the expected user count
     */
    public static function getExpectedUserCount(): int
    {
        return 3;
    }

    /**
     * Get the expected order count
     */
    public static function getExpectedOrderCount(): int
    {
        return 4;
    }
}
