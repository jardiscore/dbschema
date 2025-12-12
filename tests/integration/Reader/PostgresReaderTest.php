<?php

declare(strict_types=1);

namespace JardisCore\DbSchema\Tests\integration\Reader;

use JardisCore\DbSchema\Reader\PostgresReader;
use JardisCore\DbSchema\Tests\PdoFactory;
use JardisCore\DbSchema\Tests\SchemaBuilder;
use PHPUnit\Framework\TestCase;

class PostgresReaderTest extends TestCase
{
    public function testTablesReturnsArrayOfTables(): void
    {
        $pdo = PdoFactory::createPostgresPdo();
        SchemaBuilder::createPostgresTestSchema($pdo);

        $reader = new PostgresReader($pdo);
        $tables = $reader->tables();

        $this->assertIsArray($tables);
        $this->assertNotEmpty($tables);

        // Check for expected tables
        $tableNames = array_column($tables, 'name');
        $this->assertContains('users', $tableNames);
        $this->assertContains('orders', $tableNames);
    }

    public function testColumnsReturnsProperStructure(): void
    {
        $pdo = PdoFactory::createPostgresPdo();
        SchemaBuilder::createPostgresTestSchema($pdo);

        $reader = new PostgresReader($pdo);
        $columns = $reader->columns('users');

        $this->assertIsArray($columns);
        $this->assertNotEmpty($columns);

        // Check required keys exist
        $firstColumn = $columns[0];
        $this->assertArrayHasKey('name', $firstColumn);
        $this->assertArrayHasKey('type', $firstColumn);
        $this->assertArrayHasKey('length', $firstColumn);
        $this->assertArrayHasKey('precision', $firstColumn);
        $this->assertArrayHasKey('scale', $firstColumn);
        $this->assertArrayHasKey('nullable', $firstColumn);
        $this->assertArrayHasKey('default', $firstColumn);
        $this->assertArrayHasKey('primary', $firstColumn);
        $this->assertArrayHasKey('auto_increment', $firstColumn);

        // Check boolean normalization
        foreach ($columns as $column) {
            $this->assertIsBool($column['nullable'], 'nullable should be boolean');
            $this->assertIsBool($column['primary'], 'primary should be boolean');
            $this->assertIsBool($column['auto_increment'], 'auto_increment should be boolean');
        }
    }

    public function testColumnsWithFieldFilter(): void
    {
        $pdo = PdoFactory::createPostgresPdo();
        SchemaBuilder::createPostgresTestSchema($pdo);

        $reader = new PostgresReader($pdo);
        $columns = $reader->columns('users', ['id', 'email']);

        $this->assertIsArray($columns);
        $this->assertCount(2, $columns);

        $columnNames = array_column($columns, 'name');
        $this->assertContains('id', $columnNames);
        $this->assertContains('email', $columnNames);
    }

    public function testIndexesReturnsArrayOfIndexes(): void
    {
        $pdo = PdoFactory::createPostgresPdo();
        SchemaBuilder::createPostgresTestSchema($pdo);

        $reader = new PostgresReader($pdo);
        $indexes = $reader->indexes('orders');

        $this->assertIsArray($indexes);
        $this->assertNotEmpty($indexes);

        // Check structure - PostgreSQL returns specific format
        $firstIndex = $indexes[0];
        $this->assertArrayHasKey('name', $firstIndex);
        $this->assertArrayHasKey('column_name', $firstIndex);
        $this->assertArrayHasKey('is_unique', $firstIndex);

        // Verify index names
        $indexNames = array_column($indexes, 'name');
        $this->assertContains('idx_orders_user_id', $indexNames);
        $this->assertContains('idx_orders_status', $indexNames);
    }

    public function testForeignKeysReturnsArrayOfConstraints(): void
    {
        $pdo = PdoFactory::createPostgresPdo();
        SchemaBuilder::createPostgresTestSchema($pdo);

        $reader = new PostgresReader($pdo);
        $foreignKeys = $reader->foreignKeys('orders');

        $this->assertIsArray($foreignKeys);
        $this->assertNotEmpty($foreignKeys);

        // Check structure - PostgreSQL uses specific key names
        $firstFk = $foreignKeys[0];
        $this->assertArrayHasKey('constraintName', $firstFk);
        $this->assertArrayHasKey('constraintCol', $firstFk);
        $this->assertArrayHasKey('refContainer', $firstFk);
        $this->assertArrayHasKey('refColumn', $firstFk);
        $this->assertArrayHasKey('onUpdate', $firstFk);
        $this->assertArrayHasKey('onDelete', $firstFk);

        // Verify FK points to users table
        $this->assertSame('users', $firstFk['refContainer']);
        $this->assertSame('id', $firstFk['refColumn']);
        $this->assertSame('user_id', $firstFk['constraintCol']);
    }

    public function testFieldTypeMapping(): void
    {
        $pdo = PdoFactory::createPostgresPdo();
        $reader = new PostgresReader($pdo);

        // Test common PostgreSQL type mappings
        $this->assertSame('int', $reader->fieldType('integer'));
        $this->assertSame('int', $reader->fieldType('bigint'));
        $this->assertSame('int', $reader->fieldType('smallint'));
        $this->assertSame('string', $reader->fieldType('varchar'));
        $this->assertSame('string', $reader->fieldType('text'));
        $this->assertSame('float', $reader->fieldType('numeric'));
        $this->assertSame('float', $reader->fieldType('decimal'));
        $this->assertSame('bool', $reader->fieldType('boolean'));
        $this->assertSame('datetime', $reader->fieldType('timestamp'));
        $this->assertSame('date', $reader->fieldType('date'));
    }

    public function testPrimaryKeyDetection(): void
    {
        $pdo = PdoFactory::createPostgresPdo();
        SchemaBuilder::createPostgresTestSchema($pdo);

        $reader = new PostgresReader($pdo);
        $columns = $reader->columns('users');

        // Find id column
        $idColumn = array_filter($columns, fn($c) => $c['name'] === 'id');
        $this->assertCount(1, $idColumn);

        $idColumn = array_values($idColumn)[0];
        $this->assertTrue($idColumn['primary']);
        $this->assertTrue($idColumn['auto_increment']); // SERIAL is auto-increment
    }

    public function testBooleanColumnHandling(): void
    {
        $pdo = PdoFactory::createPostgresPdo();
        SchemaBuilder::createPostgresTestSchema($pdo);

        $reader = new PostgresReader($pdo);
        $columns = $reader->columns('users');

        // Find is_active column (boolean type)
        $isActiveColumn = array_filter($columns, fn($c) => $c['name'] === 'is_active');
        $this->assertCount(1, $isActiveColumn);

        $isActiveColumn = array_values($isActiveColumn)[0];
        $this->assertSame('boolean', $isActiveColumn['type']);
        $this->assertTrue($isActiveColumn['nullable']); // PostgreSQL booleans are nullable by default
    }
}
