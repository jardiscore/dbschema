<?php

declare(strict_types=1);

namespace JardisCore\DbSchema\Tests\integration\Exporter\Ddl;

use JardisCore\DbSchema\DbSchema;
use JardisCore\DbSchema\Exporter\Dialect\MySqlDialect;
use JardisCore\DbSchema\Exporter\Dialect\PostgresDialect;
use JardisCore\DbSchema\Exporter\Dialect\SqLiteDialect;
use JardisCore\DbSchema\Exporter\Ddl\SqlDdlExporter;
use JardisCore\DbSchema\Tests\PdoFactory;
use JardisCore\DbSchema\Tests\SchemaBuilder;
use PHPUnit\Framework\TestCase;

class SqlDdlExporterTest extends TestCase
{
    public function testGenerateMySqlDdl(): void
    {
        // Create test schema
        $pdo = PdoFactory::createMySqlPdo();

        // Ensure clean state
        $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");
        $pdo->exec("DROP TABLE IF EXISTS order_items");
        $pdo->exec("DROP TABLE IF EXISTS products");
        $pdo->exec("DROP TABLE IF EXISTS orders");
        $pdo->exec("DROP TABLE IF EXISTS users");
        $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");

        SchemaBuilder::createMySqlTestSchema($pdo);

        // Create schema analyzer and generator
        $schema = new DbSchema($pdo);
        $dialect = new MySqlDialect();
        $generator = new SqlDdlExporter($schema, $dialect);

        // Generate DDL for both tables
        $sql = $generator->generate(['users', 'orders']);

        // Assert structure
        $this->assertStringContainsString('SQL DDL Export', $sql);
        $this->assertStringContainsString('START TRANSACTION;', $sql);
        $this->assertStringContainsString('COMMIT;', $sql);

        // Assert DROP statements (reverse order due to FK)
        $this->assertStringContainsString('DROP TABLE IF EXISTS `orders`;', $sql);
        $this->assertStringContainsString('DROP TABLE IF EXISTS `users`;', $sql);

        // Assert DROP comes before CREATE
        $dropPos = strpos($sql, 'DROP TABLE');
        $createPos = strpos($sql, 'CREATE TABLE');
        $this->assertLessThan($createPos, $dropPos, 'DROP should come before CREATE');

        // Assert table creation
        $this->assertStringContainsString('CREATE TABLE `users`', $sql);
        $this->assertStringContainsString('CREATE TABLE `orders`', $sql);

        // Assert users table comes before orders (dependency order)
        $usersPos = strpos($sql, 'CREATE TABLE `users`');
        $ordersPos = strpos($sql, 'CREATE TABLE `orders`');
        $this->assertLessThan($ordersPos, $usersPos, 'users table should be created before orders');

        // Assert column definitions
        $this->assertStringContainsString('`id` INT NOT NULL AUTO_INCREMENT', $sql);
        $this->assertStringContainsString('`email` VARCHAR(255) NOT NULL', $sql);
        $this->assertStringContainsString('`balance` DECIMAL(10,2)', $sql);

        // Assert primary key
        $this->assertStringContainsString('PRIMARY KEY (`id`)', $sql);

        // Assert indexes
        $this->assertStringContainsString('CREATE INDEX', $sql);
        $this->assertStringContainsString('`idx_user_id`', $sql);

        // Assert foreign keys (should be after all CREATE TABLE statements)
        $this->assertStringContainsString('ALTER TABLE `orders` ADD CONSTRAINT `fk_orders_user_id`', $sql);
        $this->assertStringContainsString('FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)', $sql);
        $this->assertStringContainsString('ON UPDATE CASCADE ON DELETE CASCADE', $sql);

        // Assert FK comes after all CREATE TABLE statements
        $fkPos = strpos($sql, 'ALTER TABLE');
        $lastCreatePos = strrpos($sql, 'CREATE TABLE');
        $this->assertGreaterThan($lastCreatePos, $fkPos, 'Foreign keys should be added after all tables are created');
    }

    public function testDependencyOrdering(): void
    {
        // Create a more complex schema to test dependency resolution
        $pdo = PdoFactory::createMySqlPdo();

        // Clean up everything first (including tables from other tests)
        SchemaBuilder::dropMySqlTestSchema($pdo);

        $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");
        $pdo->exec("DROP TABLE IF EXISTS order_items");
        $pdo->exec("DROP TABLE IF EXISTS products");
        $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");

        // Create schema with multiple dependencies:
        // users (no FK)
        // products (no FK)
        // orders (FK to users)
        // order_items (FK to orders and products)

        $pdo->exec("
            CREATE TABLE users (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(255) NOT NULL
            ) ENGINE=InnoDB
        ");

        $pdo->exec("
            CREATE TABLE products (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(255) NOT NULL,
                price DECIMAL(10,2) NOT NULL
            ) ENGINE=InnoDB
        ");

        $pdo->exec("
            CREATE TABLE orders (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                total DECIMAL(10,2) NOT NULL,
                FOREIGN KEY (user_id) REFERENCES users(id)
            ) ENGINE=InnoDB
        ");

        $pdo->exec("
            CREATE TABLE order_items (
                id INT AUTO_INCREMENT PRIMARY KEY,
                order_id INT NOT NULL,
                product_id INT NOT NULL,
                quantity INT NOT NULL,
                FOREIGN KEY (order_id) REFERENCES orders(id),
                FOREIGN KEY (product_id) REFERENCES products(id)
            ) ENGINE=InnoDB
        ");

        // Generate DDL
        $schema = new DbSchema($pdo);
        $dialect = new MySqlDialect();
        $generator = new SqlDdlExporter($schema, $dialect);

        $sql = $generator->generate(['users', 'products', 'orders', 'order_items']);

        // Extract table creation order
        preg_match_all('/CREATE TABLE `(\w+)`/', $sql, $matches);
        $tableOrder = $matches[1];

        // Assert dependency order:
        // - users and products should come before orders
        // - orders should come before order_items
        $usersIndex = array_search('users', $tableOrder);
        $productsIndex = array_search('products', $tableOrder);
        $ordersIndex = array_search('orders', $tableOrder);
        $orderItemsIndex = array_search('order_items', $tableOrder);

        $this->assertLessThan($ordersIndex, $usersIndex, 'users should be created before orders');
        $this->assertLessThan($orderItemsIndex, $productsIndex, 'products should be created before order_items');
        $this->assertLessThan($orderItemsIndex, $ordersIndex, 'orders should be created before order_items');
    }

    public function testGenerateWithSelectedTables(): void
    {
        $pdo = PdoFactory::createMySqlPdo();

        // Clean up first to ensure fresh state
        SchemaBuilder::dropMySqlTestSchema($pdo);
        $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");
        $pdo->exec("DROP TABLE IF EXISTS order_items");
        $pdo->exec("DROP TABLE IF EXISTS products");
        $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");

        SchemaBuilder::createMySqlTestSchema($pdo);

        $schema = new DbSchema($pdo);
        $dialect = new MySqlDialect();
        $generator = new SqlDdlExporter($schema, $dialect);

        // Generate DDL for only users table
        $sql = $generator->generate(['users']);

        $this->assertStringContainsString('CREATE TABLE `users`', $sql);
        $this->assertStringNotContainsString('CREATE TABLE `orders`', $sql);
        $this->assertStringContainsString('Tables: 1 (users)', $sql);
    }

    public function testGeneratePostgresDdl(): void
    {
        // Create test schema
        $pdo = PdoFactory::createPostgresPdo();

        // Clean up first
        $pdo->exec("DROP TABLE IF EXISTS orders CASCADE");
        $pdo->exec("DROP TABLE IF EXISTS users CASCADE");

        SchemaBuilder::createPostgresTestSchema($pdo);

        // Create schema analyzer and generator
        $schema = new DbSchema($pdo);
        $dialect = new PostgresDialect();
        $generator = new SqlDdlExporter($schema, $dialect);

        // Generate DDL for both tables
        $sql = $generator->generate(['users', 'orders']);

        // Assert structure
        $this->assertStringContainsString('SQL DDL Export', $sql);
        $this->assertStringContainsString('BEGIN;', $sql);
        $this->assertStringContainsString('COMMIT;', $sql);

        // Assert DROP statements with CASCADE
        $this->assertStringContainsString('DROP TABLE IF EXISTS "orders" CASCADE;', $sql);
        $this->assertStringContainsString('DROP TABLE IF EXISTS "users" CASCADE;', $sql);

        // Assert table creation with quoted identifiers
        $this->assertStringContainsString('CREATE TABLE "users"', $sql);
        $this->assertStringContainsString('CREATE TABLE "orders"', $sql);

        // Assert dependency order
        $usersPos = strpos($sql, 'CREATE TABLE "users"');
        $ordersPos = strpos($sql, 'CREATE TABLE "orders"');
        $this->assertLessThan($ordersPos, $usersPos, 'users table should be created before orders');

        // Assert SERIAL for auto-increment
        $this->assertStringContainsString('SERIAL', $sql);

        // Assert column types
        $this->assertStringContainsString('VARCHAR(255)', $sql);
        // PostgreSQL returns NUMERIC instead of DECIMAL (they are synonyms)
        $this->assertStringContainsString('NUMERIC(10,2)', $sql);

        // Assert foreign keys
        $this->assertStringContainsString('ALTER TABLE "orders" ADD CONSTRAINT', $sql);
        $this->assertStringContainsString('FOREIGN KEY ("user_id") REFERENCES "users" ("id")', $sql);
    }

    public function testGenerateSqLiteDdl(): void
    {
        // Create test schema
        $pdo = PdoFactory::createSqlitePdo();
        SchemaBuilder::createSqliteTestSchema($pdo);

        // Create schema analyzer and generator
        $schema = new DbSchema($pdo);
        $dialect = new SqLiteDialect();
        $generator = new SqlDdlExporter($schema, $dialect);

        // Generate DDL for both tables
        $sql = $generator->generate(['users', 'orders']);

        // Assert structure
        $this->assertStringContainsString('SQL DDL Export', $sql);
        $this->assertStringContainsString('BEGIN TRANSACTION;', $sql);
        $this->assertStringContainsString('COMMIT;', $sql);

        // Assert DROP statements
        $this->assertStringContainsString('DROP TABLE IF EXISTS "orders";', $sql);
        $this->assertStringContainsString('DROP TABLE IF EXISTS "users";', $sql);

        // Assert table creation with quoted identifiers
        $this->assertStringContainsString('CREATE TABLE "users"', $sql);
        $this->assertStringContainsString('CREATE TABLE "orders"', $sql);

        // Assert dependency order
        $usersPos = strpos($sql, 'CREATE TABLE "users"');
        $ordersPos = strpos($sql, 'CREATE TABLE "orders"');
        $this->assertLessThan($ordersPos, $usersPos, 'users table should be created before orders');

        // Assert SQLite-specific types
        $this->assertStringContainsString('INTEGER', $sql);
        $this->assertStringContainsString('TEXT', $sql);
        $this->assertStringContainsString('REAL', $sql);

        // Assert AUTOINCREMENT for primary keys
        $this->assertStringContainsString('PRIMARY KEY AUTOINCREMENT', $sql);

        // Assert foreign key comments (SQLite limitation)
        $this->assertStringContainsString('-- Foreign key constraint', $sql);
    }
}
