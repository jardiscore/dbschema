<?php

declare(strict_types=1);

namespace JardisCore\DbSchema\Tests\integration;

use JardisCore\DbSchema\DbSchema;
use JardisCore\DbSchema\Tests\PdoFactory;
use JardisCore\DbSchema\Tests\SchemaBuilder;
use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\TestCase;

class DbSchemaTest extends TestCase
{
    /**
     * Test that DbSchema correctly delegates to the right repository for each database type
     * @dataProvider pdoProvider
     */
    public function testDbSchemaFactoryAndDelegation(string $driverName, PDO $pdo, callable $schemaCreator): void
    {
        $schemaCreator($pdo);

        $dbSchema = new DbSchema($pdo);

        // Test that delegation works - we don't care about specific results,
        // just that the methods work without errors
        $tables = $dbSchema->tables();
        $this->assertIsArray($tables);
        $this->assertNotEmpty($tables);

        $columns = $dbSchema->columns('users');
        $this->assertIsArray($columns);
        $this->assertNotEmpty($columns);

        $fieldType = $dbSchema->fieldType('varchar');
        $this->assertEquals('string', $fieldType);
    }

    /**
     * Test that repository instances are cached (created only once)
     */
    public function testRepositoryCaching(): void
    {
        $pdo = PdoFactory::createSqlitePdo();
        SchemaBuilder::createSqliteTestSchema($pdo);

        $dbSchema = new DbSchema($pdo);

        // Multiple calls should use the same repository instance
        $tables1 = $dbSchema->tables();
        $tables2 = $dbSchema->tables();
        $columns1 = $dbSchema->columns('users');
        $columns2 = $dbSchema->columns('users');

        // Results should be identical (same cached repository)
        $this->assertEquals($tables1, $tables2);
        $this->assertEquals($columns1, $columns2);
    }

    /**
     * Test unsupported database driver throws proper exception
     */
    public function testUnsupportedDriverThrowsException(): void
    {
        // Create a mock PDO that returns an unsupported driver
        $mockPdo = $this->createMock(PDO::class);
        $mockPdo->method('getAttribute')
                ->with(PDO::ATTR_DRIVER_NAME)
                ->willReturn('unsupported_driver');

        $dbSchema = new DbSchema($mockPdo);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported database driver: unsupported_driver. Supported: mysql, pgsql, sqlite');

        $dbSchema->tables();
    }

    /**
     * Test case-insensitive driver matching
     */
    public function testCaseInsensitiveDriverMatching(): void
    {
        // Create a mock PDO that returns uppercase driver name
        $realPdo = PdoFactory::createMySqlPdo();
        SchemaBuilder::createMySqlTestSchema($realPdo);

        $mockPdo = $this->createMock(PDO::class);
        $mockPdo->method('getAttribute')
                ->with(PDO::ATTR_DRIVER_NAME)
                ->willReturn('MYSQL'); // Uppercase

        // Forward other method calls to real PDO
        $mockPdo->method('prepare')
                ->willReturnCallback(fn($sql) => $realPdo->prepare($sql));

        $dbSchema = new DbSchema($mockPdo);

        // Should work with uppercase driver name
        $tables = $dbSchema->tables();
        $this->assertIsArray($tables);
        $this->assertNotEmpty($tables);
    }

    /**
     * Test that PDO driver is correctly detected for each database type
     * @dataProvider pdoProvider
     */
    public function testDriverDetection(string $expectedDriverName, PDO $pdo, callable $schemaCreator): void
    {
        $actualDriverName = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $this->assertEquals($expectedDriverName, $actualDriverName);

        // Verify DbSchema can work with this PDO
        $schemaCreator($pdo);
        $dbSchema = new DbSchema($pdo);

        $tables = $dbSchema->tables();
        $this->assertIsArray($tables);
        $this->assertNotEmpty($tables);
    }

    /**
     * Test that all interface methods work through DbSchema with real PDO connections
     * @dataProvider pdoProvider
     */
    public function testAllInterfaceMethodsWithRealPdo(string $driverName, PDO $pdo, callable $schemaCreator): void
    {
        $schemaCreator($pdo);
        $dbSchema = new DbSchema($pdo);

        // Test all DbSchemaInterface methods
        $tables = $dbSchema->tables();
        $this->assertIsArray($tables);

        // Filter to expected tables (ignore any leftover test tables)
        $tableNames = array_column($tables, 'name');
        $this->assertContains('users', $tableNames);
        $this->assertContains('orders', $tableNames);

        $columns = $dbSchema->columns('users');
        $this->assertIsArray($columns);
        $this->assertCount(8, $columns);

        $filteredColumns = $dbSchema->columns('users', ['id', 'name']);
        $this->assertIsArray($filteredColumns);
        $this->assertCount(2, $filteredColumns);

        $indexes = $dbSchema->indexes('users');
        $this->assertIsArray($indexes);
        $this->assertNotEmpty($indexes);

        $foreignKeys = $dbSchema->foreignKeys('orders');
        $this->assertIsArray($foreignKeys);
        $this->assertCount(1, $foreignKeys);

        $fieldType = $dbSchema->fieldType('varchar');
        $this->assertEquals('string', $fieldType);
    }

    /**
     * Test error handling when a table doesn't exist
     * @dataProvider pdoProvider
     */
    public function testNonExistentTable(string $driverName, PDO $pdo, callable $schemaCreator): void
    {
        $schemaCreator($pdo);
        $dbSchema = new DbSchema($pdo);

        // Test with a non-existent table
        $columns = $dbSchema->columns('non_existent_table');
        $this->assertTrue($columns === null || $columns === []);

        $indexes = $dbSchema->indexes('non_existent_table');
        $this->assertTrue($indexes === null || $indexes === []);

        $foreignKeys = $dbSchema->foreignKeys('non_existent_table');
        $this->assertTrue($foreignKeys === null || $foreignKeys === []);
    }

    public static function pdoProvider(): array
    {
        return [
            'mysql' => [
                'mysql',
                PdoFactory::createMySqlPdo(),
                fn(PDO $pdo) => SchemaBuilder::createMySqlTestSchema($pdo),
            ],
            'pgsql' => [
                'pgsql',
                PdoFactory::createPostgresPdo(),
                fn(PDO $pdo) => SchemaBuilder::createPostgresTestSchema($pdo),
            ],
            'sqlite' => [
                'sqlite',
                PdoFactory::createSqlitePdo(),
                fn(PDO $pdo) => SchemaBuilder::createSqliteTestSchema($pdo),
            ],
        ];
    }
}
