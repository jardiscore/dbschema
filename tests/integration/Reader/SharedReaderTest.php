<?php

declare(strict_types=1);

namespace JardisCore\DbSchema\Tests\integration\Reader;

use JardisCore\DbSchema\Reader\MySqlReader;
use JardisCore\DbSchema\Reader\PostgresReader;
use JardisCore\DbSchema\Reader\SqLiteReader;
use JardisCore\DbSchema\Tests\PdoFactory;
use JardisCore\DbSchema\Tests\SchemaBuilder;
use JardisPsr\DbSchema\DbSchemaInterface;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Shared integration tests for all Reader implementations.
 * Tests that all readers (MySQL, Postgres, SQLite) produce consistent results.
 */
class SharedReaderTest extends TestCase
{
    /**
     * Test constructor accepts a PDO instance and stores it correctly
     */
    public function testMySqlConstructor(): void
    {
        $pdo = PdoFactory::createMySqlPdo();
        $repository = new MySqlReader($pdo);

        $this->assertInstanceOf(MySqlReader::class, $repository);
        $this->assertInstanceOf(DbSchemaInterface::class, $repository);
    }

    /**
     * Test constructor accepts a PDO instance and stores it correctly
     */
    public function testPostgresConstructor(): void
    {
        $pdo = PdoFactory::createPostgresPdo();
        $repository = new PostgresReader($pdo);

        $this->assertInstanceOf(PostgresReader::class, $repository);
        $this->assertInstanceOf(DbSchemaInterface::class, $repository);
    }

    /**
     * Test constructor accepts a PDO instance and stores it correctly
     */
    public function testSqLiteConstructor(): void
    {
        $pdo = PdoFactory::createSqlitePdo();
        $repository = new SqLiteReader($pdo);

        $this->assertInstanceOf(SqLiteReader::class, $repository);
        $this->assertInstanceOf(DbSchemaInterface::class, $repository);
    }

    /**
     * @dataProvider databaseProvider
     */
    public function testTables(string $driverName, PDO $pdo, callable $schemaCreator, DbSchemaInterface $repository): void
    {
        $schemaCreator($pdo);

        $tables = $repository->tables();

        $this->assertIsArray($tables);
        $this->assertNotEmpty($tables, "Should return tables");

        // Verify table structure - check for expected tables (ignore any leftover test tables)
        $tableNames = array_column($tables, 'name');
        $this->assertContains('users', $tableNames, "Should contain 'users' table");
        $this->assertContains('orders', $tableNames, "Should contain 'orders' table");

        // Verify all tables have 'type' field
        foreach ($tables as $table) {
            $this->assertArrayHasKey('name', $table);
            $this->assertArrayHasKey('type', $table);
            $this->assertEquals('BASE TABLE', $table['type']);
        }

        // Filter to only expected tables and verify they exist in correct order
        $expectedTables = ['orders', 'users'];
        $actualExpectedTables = array_values(array_filter($tableNames, fn($name) => in_array($name, $expectedTables)));
        sort($actualExpectedTables); // Ensure alphabetical order
        $this->assertEquals($expectedTables, $actualExpectedTables, "Expected tables should be present in alphabetical order");
    }

    /**
     * @dataProvider databaseProvider
     * @covers \JardisCore\DbSchema\Reader\MySqlReader::columns
     * @covers \JardisCore\DbSchema\Reader\PostgresReader::columns
     * @covers \JardisCore\DbSchema\Reader\SqLiteReader::columns
     */
    public function testColumnsUsers(string $driverName, PDO $pdo, callable $schemaCreator, DbSchemaInterface $repository): void
    {
        $schemaCreator($pdo);

        $columns = $repository->columns('users');

        $this->assertIsArray($columns);
        $this->assertCount(8, $columns, "Users table should have exactly 8 columns");

        $columnNames = array_column($columns, 'name');
        $expectedColumns = ['id', 'name', 'email', 'age', 'is_active', 'balance', 'created_at', 'updated_at'];

        foreach ($expectedColumns as $expectedColumn) {
            $this->assertContains($expectedColumn, $columnNames, "Should contain '$expectedColumn' column");
        }

        // Test specific column properties
        $columnsByName = [];
        foreach ($columns as $column) {
            $columnsByName[$column['name']] = $column;
        }

        // Test ID column (Primary Key, Auto Increment)
        $idColumn = $columnsByName['id'];
        $this->assertTrue($idColumn['primary'], "ID should be primary key");
        $this->assertTrue($idColumn['auto_increment'], "ID should be auto increment");
        $this->assertFalse($idColumn['nullable'], "ID should not be nullable");

        // Test name column (NOT NULL)
        $nameColumn = $columnsByName['name'];
        $this->assertFalse($nameColumn['nullable'], "Name should not be nullable");
        if ($driverName === 'mysql') {
            $this->assertEquals('varchar', $nameColumn['type']);
            $this->assertEquals(255, $nameColumn['length']);
        }

        // Test email column (UNIQUE, NOT NULL)
        $emailColumn = $columnsByName['email'];
        $this->assertFalse($emailColumn['nullable'], "Email should not be nullable");

        // Test age column (nullable)
        $ageColumn = $columnsByName['age'];
        $this->assertTrue($ageColumn['nullable'], "Age should be nullable");

        // Test is_active column (boolean with default)
        $isActiveColumn = $columnsByName['is_active'];
        $this->assertIsBool($isActiveColumn['nullable'], "is_active nullable should be boolean");
        $this->assertIsBool($isActiveColumn['primary'], "is_active primary should be boolean");
        $this->assertIsBool($isActiveColumn['auto_increment'], "is_active auto_increment should be boolean");

        // Test balance column (decimal)
        $balanceColumn = $columnsByName['balance'];
        if ($driverName === 'mysql') {
            $this->assertEquals('decimal', $balanceColumn['type']);
            $this->assertEquals(10, $balanceColumn['precision']);
            $this->assertEquals(2, $balanceColumn['scale']);
        } elseif ($driverName === 'pgsql') {
            $this->assertEquals('numeric', $balanceColumn['type']);
            $this->assertEquals(10, $balanceColumn['precision']);
            $this->assertEquals(2, $balanceColumn['scale']);
        }

        // Test all columns have required fields
        foreach ($columns as $column) {
            $this->assertArrayHasKey('name', $column);
            $this->assertArrayHasKey('type', $column);
            $this->assertArrayHasKey('nullable', $column);
            $this->assertArrayHasKey('primary', $column);
            $this->assertArrayHasKey('auto_increment', $column);

            // Verify data types
            $this->assertIsString($column['name']);
            $this->assertIsString($column['type']);
            $this->assertIsBool($column['nullable']);
            $this->assertIsBool($column['primary']);
            $this->assertIsBool($column['auto_increment']);
        }
    }

    /**
     * @dataProvider databaseProvider
     * @covers \JardisCore\DbSchema\Reader\MySqlReader::columns
     * @covers \JardisCore\DbSchema\Reader\PostgresReader::columns
     * @covers \JardisCore\DbSchema\Reader\SqLiteReader::columns
     */
    public function testColumnsOrders(string $driverName, PDO $pdo, callable $schemaCreator, DbSchemaInterface $repository): void
    {
        $schemaCreator($pdo);

        $columns = $repository->columns('orders');

        $this->assertIsArray($columns);
        $this->assertCount(6, $columns, "Orders table should have exactly 6 columns");

        $columnNames = array_column($columns, 'name');
        $expectedColumns = ['id', 'user_id', 'order_number', 'total', 'status', 'created_at'];

        foreach ($expectedColumns as $expectedColumn) {
            $this->assertContains($expectedColumn, $columnNames, "Should contain '$expectedColumn' column");
        }

        $columnsByName = [];
        foreach ($columns as $column) {
            $columnsByName[$column['name']] = $column;
        }

        // Test foreign key column
        $userIdColumn = $columnsByName['user_id'];
        $this->assertFalse($userIdColumn['nullable'], "user_id should not be nullable (FK)");
        $this->assertFalse($userIdColumn['primary'], "user_id should not be primary");
    }

    /**
     * @dataProvider databaseProvider
     * @covers \JardisCore\DbSchema\Reader\MySqlReader::columns
     * @covers \JardisCore\DbSchema\Reader\PostgresReader::columns
     * @covers \JardisCore\DbSchema\Reader\SqLiteReader::columns
     */
    public function testColumnsWithFieldFilter(string $driverName, PDO $pdo, callable $schemaCreator, DbSchemaInterface $repository): void
    {
        $schemaCreator($pdo);

        $columns = $repository->columns('users', ['id', 'name', 'email']);

        $this->assertIsArray($columns);
        $this->assertCount(3, $columns, "Should return exactly 3 filtered columns");

        $columnNames = array_column($columns, 'name');
        $this->assertEquals(['id', 'name', 'email'], $columnNames);
    }

    /**
     * @dataProvider databaseProvider
     * @covers \JardisCore\DbSchema\Reader\MySqlReader::indexes
     * @covers \JardisCore\DbSchema\Reader\PostgresReader::indexes
     * @covers \JardisCore\DbSchema\Reader\SqLiteReader::indexes
     */
    public function testIndexesUsers(string $driverName, PDO $pdo, callable $schemaCreator, DbSchemaInterface $repository): void
    {
        $schemaCreator($pdo);

        $indexes = $repository->indexes('users');

        $this->assertIsArray($indexes);
        $this->assertNotEmpty($indexes, "Users table should have indexes");

        // All databases should have at least primary key index
        $indexTypes = array_column($indexes, 'index_type');
        $this->assertContains('primary', $indexTypes, "Should have primary key index");

        // Test index structure
        foreach ($indexes as $index) {
            $this->assertArrayHasKey('name', $index);
            $this->assertArrayHasKey('column_name', $index);
            $this->assertArrayHasKey('is_unique', $index);
            $this->assertArrayHasKey('index_type', $index);

            $this->assertIsString($index['name']);
            $this->assertIsString($index['column_name']);
            $this->assertIsBool($index['is_unique']);
            $this->assertIsString($index['index_type']);

            $this->assertContains($index['index_type'], ['primary', 'unique', 'index']);
        }
    }

    /**
     * @dataProvider databaseProvider
     * @covers \JardisCore\DbSchema\Reader\MySqlReader::indexes
     * @covers \JardisCore\DbSchema\Reader\PostgresReader::indexes
     * @covers \JardisCore\DbSchema\Reader\SqLiteReader::indexes
     */
    public function testIndexesOrders(string $driverName, PDO $pdo, callable $schemaCreator, DbSchemaInterface $repository): void
    {
        $schemaCreator($pdo);

        $indexes = $repository->indexes('orders');

        $this->assertIsArray($indexes);
        $this->assertNotEmpty($indexes, "Orders table should have indexes");

        $indexNames = array_unique(array_column($indexes, 'name'));

        // MySQL creates explicit indexes, verify they exist
        if ($driverName === 'mysql') {
            $this->assertGreaterThanOrEqual(3, count($indexNames), "Should have at least 3 indexes (PRIMARY, idx_user_id, idx_status)");
        }

        // Check for user_id index (foreign key)
        $columnNames = array_column($indexes, 'column_name');
        $this->assertContains('user_id', $columnNames, "Should have index on user_id (foreign key)");
    }

    /**
     * @dataProvider databaseProvider
     * @covers \JardisCore\DbSchema\Reader\MySqlReader::foreignKeys
     * @covers \JardisCore\DbSchema\Reader\PostgresReader::foreignKeys
     * @covers \JardisCore\DbSchema\Reader\SqLiteReader::foreignKeys
     */
    public function testForeignKeysOrders(string $driverName, PDO $pdo, callable $schemaCreator, DbSchemaInterface $repository): void
    {
        $schemaCreator($pdo);

        $foreignKeys = $repository->foreignKeys('orders');

        $this->assertIsArray($foreignKeys);
        $this->assertCount(1, $foreignKeys, "Orders table should have exactly 1 foreign key");

        $fk = $foreignKeys[0];

        $this->assertArrayHasKey('container', $fk);
        $this->assertArrayHasKey('constraintCol', $fk);
        $this->assertArrayHasKey('refContainer', $fk);
        $this->assertArrayHasKey('refColumn', $fk);
        $this->assertArrayHasKey('onUpdate', $fk);
        $this->assertArrayHasKey('onDelete', $fk);

        $this->assertEquals('orders', $fk['container']);
        $this->assertEquals('user_id', $fk['constraintCol']);
        $this->assertEquals('users', $fk['refContainer']);
        $this->assertEquals('id', $fk['refColumn']);
        $this->assertEquals('CASCADE', $fk['onUpdate']);
        $this->assertEquals('CASCADE', $fk['onDelete']);
    }

    /**
     * @dataProvider databaseProvider
     * @covers \JardisCore\DbSchema\Reader\MySqlReader::foreignKeys
     * @covers \JardisCore\DbSchema\Reader\PostgresReader::foreignKeys
     * @covers \JardisCore\DbSchema\Reader\SqLiteReader::foreignKeys
     */
    public function testForeignKeysUsers(string $driverName, PDO $pdo, callable $schemaCreator, DbSchemaInterface $repository): void
    {
        $schemaCreator($pdo);

        $foreignKeys = $repository->foreignKeys('users');

        // Users table should have no foreign keys
        $this->assertTrue($foreignKeys === null || $foreignKeys === [], "Users table should have no foreign keys");
    }

    /**
     * @dataProvider databaseProvider
     * @covers \JardisCore\DbSchema\Reader\MySqlReader::fieldType
     * @covers \JardisCore\DbSchema\Reader\PostgresReader::fieldType
     * @covers \JardisCore\DbSchema\Reader\SqLiteReader::fieldType
     */
    public function testFieldTypeMapping(string $driverName, PDO $pdo, callable $schemaCreator, DbSchemaInterface $repository): void
    {
        // Test common field type mappings
        $this->assertEquals('int', $repository->fieldType('integer'));
        $this->assertEquals('string', $repository->fieldType('varchar'));
        $this->assertEquals('string', $repository->fieldType('varchar(255)'));
        $this->assertEquals('bool', $repository->fieldType('boolean'));
        $this->assertEquals('float', $repository->fieldType('decimal'));
        $this->assertEquals('float', $repository->fieldType('decimal(10,2)'));

        // Database-specific tests
        if ($driverName === 'mysql') {
            $this->assertEquals('datetime', $repository->fieldType('timestamp'));
            $this->assertEquals('string', $repository->fieldType('text'));
        } elseif ($driverName === 'pgsql') {
            $this->assertEquals('datetime', $repository->fieldType('timestamp'));
            $this->assertEquals('array', $repository->fieldType('json'));
        } elseif ($driverName === 'sqlite') {
            $this->assertEquals('string', $repository->fieldType('text'));
            $this->assertEquals('float', $repository->fieldType('real'));
        }

        // Unknown types should return null
        $this->assertNull($repository->fieldType('unknown_type'));
    }

    public static function databaseProvider(): array
    {
        // Create single PDO instances to share between schema creation and repository
        $mysqlPdo = PdoFactory::createMySqlPdo();
        $postgresPdo = PdoFactory::createPostgresPdo();
        $sqlitePdo = PdoFactory::createSqlitePdo();

        return [
            'mysql' => [
                'mysql',
                $mysqlPdo,
                fn(PDO $pdo) => SchemaBuilder::createMySqlTestSchema($pdo),
                new MySqlReader($mysqlPdo)
            ],
            'pgsql' => [
                'pgsql',
                $postgresPdo,
                fn(PDO $pdo) => SchemaBuilder::createPostgresTestSchema($pdo),
                new PostgresReader($postgresPdo)
            ],
            'sqlite' => [
                'sqlite',
                $sqlitePdo,
                fn(PDO $pdo) => SchemaBuilder::createSqliteTestSchema($pdo),
                new SqLiteReader($sqlitePdo)
            ]
        ];
    }
}
