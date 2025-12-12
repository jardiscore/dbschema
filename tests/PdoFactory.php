<?php

declare(strict_types=1);

namespace JardisCore\DbSchema\Tests;

use PDO;
use PDOException;

class PdoFactory
{
    public static function createMySqlPdo(): PDO
    {
        $host = $_ENV['MYSQL_HOST'] ?? 'mysql';
        $port = $_ENV['MYSQL_PORT'] ?? 3306;
        $database = $_ENV['MYSQL_DATABASE'] ?? 'test_db';
        $username = $_ENV['MYSQL_USER'] ?? 'test_user';
        $password = $_ENV['MYSQL_PASSWORD'] ?? 'test_password';

        $dsn = "mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4";

        return new PDO($dsn, $username, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }

    public static function createPostgresPdo(): PDO
    {
        $host = $_ENV['POSTGRES_HOST'] ?? 'postgres';
        $port = $_ENV['POSTGRES_PORT'] ?? 5432;
        $database = $_ENV['POSTGRES_DATABASE'] ?? 'test_db';
        $username = $_ENV['POSTGRES_USER'] ?? 'test_user';
        $password = $_ENV['POSTGRES_PASSWORD'] ?? 'test_password';

        $dsn = "pgsql:host={$host};port={$port};dbname={$database}";

        return new PDO($dsn, $username, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }

    public static function createSqlitePdo(): PDO
    {
        return new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }
}
