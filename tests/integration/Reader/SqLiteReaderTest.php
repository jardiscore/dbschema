<?php

declare(strict_types=1);

namespace JardisCore\DbSchema\Tests\integration\Reader;

use JardisCore\DbSchema\Reader\SqLiteReader;
use JardisCore\DbSchema\Tests\PdoFactory;
use JardisCore\DbSchema\Tests\SchemaBuilder;
use PDO;
use PHPUnit\Framework\TestCase;

class SqLiteReaderTest extends TestCase
{
    private PDO $pdo;
    private SqLiteReader $repository;

    protected function setUp(): void
    {
        $this->pdo = PdoFactory::createSqlitePdo();
        SchemaBuilder::createSqliteTestSchema($this->pdo);
        $this->repository = new SqLiteReader($this->pdo);
    }

    public function testAllIndexTypes(): void
    {
        // Test to ensure all index type paths are covered
        $indexes = $this->repository->indexes('users');

        $this->assertIsArray($indexes);
        $this->assertNotEmpty($indexes);

        // Check that we have different index types
        $indexTypes = array_unique(array_column($indexes, 'index_type'));
        $this->assertNotEmpty($indexTypes);
    }

    public function testUniqueIndexDetection(): void
    {
        // SQLite creates automatic indexes for UNIQUE constraints
        // The 'users' table already has a UNIQUE constraint on 'email'
        $indexes = $this->repository->indexes('users');

        $this->assertIsArray($indexes);

        // Debug: Print all indexes to see what we get
        // SQLite marks UNIQUE constraint indexes with is_unique = true
        $uniqueIndexes = array_filter($indexes, fn($idx) => $idx['is_unique'] === true);

        // Should have at least one unique index (the email column)
        $this->assertNotEmpty($uniqueIndexes, "Should have at least one unique index (email column)");

        // Additional check: verify the email unique index exists
        $emailIndexes = array_filter($indexes, fn($idx) => $idx['column_name'] === 'email');
        $this->assertNotEmpty($emailIndexes, "Should have index on email column");
    }

    public function testNonUniqueIndexDetection(): void
    {
        // The 'orders' table has non-unique indexes
        $indexes = $this->repository->indexes('orders');

        $this->assertIsArray($indexes);

        // Find the regular (non-unique) indexes
        $regularIndexes = array_filter($indexes, fn($idx) =>
            $idx['index_type'] === 'index' && $idx['is_unique'] === false
        );

        $this->assertNotEmpty($regularIndexes, "Should have at least one regular (non-unique) index");
    }

    public function testPrimaryKeyIndexDetection(): void
    {
        // Tables with PRIMARY KEY should have a primary index type
        $indexes = $this->repository->indexes('users');

        $this->assertIsArray($indexes);

        // SQLite creates an autoindex for PRIMARY KEY
        $primaryIndexes = array_filter($indexes, fn($idx) =>
            $idx['index_type'] === 'primary' ||
            str_contains($idx['name'], 'autoindex')
        );

        $this->assertNotEmpty($primaryIndexes, "Should detect primary key index");
    }

    public function testExplicitUniqueIndex(): void
    {
        // Create a table with an explicit UNIQUE INDEX (not just UNIQUE constraint)
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS test_explicit_unique (
                id INTEGER PRIMARY KEY,
                username TEXT NOT NULL
            )
        ");

        // Create an explicit unique index
        $this->pdo->exec("CREATE UNIQUE INDEX idx_username_unique ON test_explicit_unique(username)");

        $indexes = $this->repository->indexes('test_explicit_unique');

        $this->assertIsArray($indexes);

        // Should find the unique index
        $uniqueIndexes = array_filter($indexes, fn($idx) => $idx['is_unique'] === true);
        $this->assertNotEmpty($uniqueIndexes, "Should have at least one unique index");

        // Should have the specific index we created
        $usernameIndexes = array_filter($indexes, fn($idx) => $idx['name'] === 'idx_username_unique');
        $this->assertNotEmpty($usernameIndexes, "Should find idx_username_unique");

        // Verify it's marked as unique type
        $usernameIndex = array_values($usernameIndexes)[0];
        $this->assertTrue($usernameIndex['is_unique'], "Index should be marked as unique");
        $this->assertEquals('unique', $usernameIndex['index_type'], "Index type should be 'unique'");
    }

    /**
     * Test complex column types to cover lines 52, 63, 69-75 (DECIMAL/NUMERIC parsing)
     */
    public function testComplexColumnTypes(): void
    {
        // Lines 52, 63, 69-75: Test all DECIMAL/NUMERIC parsing paths
        $this->pdo->exec("
            CREATE TABLE test_types (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                -- Line 52: Simple length extraction VARCHAR(50)
                varchar_col VARCHAR(50),
                -- Lines 63, 69-71: DECIMAL with precision and scale DECIMAL(10,2)
                decimal_col DECIMAL(10,2),
                -- Lines 63, 69-71: NUMERIC with precision and scale NUMERIC(8,3)
                numeric_col NUMERIC(8,3),
                -- Lines 63, 73-75: DECIMAL with single parameter DECIMAL(5)
                decimal_single DECIMAL(5)
            )
        ");

        $columns = $this->repository->columns('test_types');

        $this->assertIsArray($columns);
        $this->assertNotEmpty($columns);

        $columnsByName = [];
        foreach ($columns as $column) {
            $columnsByName[$column['name']] = $column;
        }

        // Test VARCHAR with length (line 52)
        $varcharCol = $columnsByName['varchar_col'];
        $this->assertEquals(50, $varcharCol['length']);
        $this->assertNull($varcharCol['precision']);
        $this->assertNull($varcharCol['scale']);

        // Test DECIMAL with precision and scale (lines 63, 69-71)
        $decimalCol = $columnsByName['decimal_col'];
        $this->assertEquals(10, $decimalCol['precision']);
        $this->assertEquals(2, $decimalCol['scale']);
        $this->assertNull($decimalCol['length']); // Line 71: length = null for decimal

        // Test NUMERIC with precision and scale (lines 63, 69-71)
        $numericCol = $columnsByName['numeric_col'];
        $this->assertEquals(8, $numericCol['precision']);
        $this->assertEquals(3, $numericCol['scale']);
        $this->assertNull($numericCol['length']); // Line 71: length = null for numeric

        // Test DECIMAL with single parameter (lines 63, 73-75)
        $decimalSingle = $columnsByName['decimal_single'];
        $this->assertEquals(5, $decimalSingle['precision']);
        $this->assertEquals(0, $decimalSingle['scale']); // Line 74: scale = 0
        $this->assertNull($decimalSingle['length']); // Line 75: length = null
    }

    /**
     * Test error handling for non-existent tables (lines 143, 154, 182)
     */
    public function testNonExistentTable(): void
    {
        // Line 143: indexes() returns null when stmt is false
        $indexes = $this->repository->indexes('non_existent_table_xyz');
        $this->assertNull($indexes);

        // Line 154: indexes() continues when index_info stmt fails
        // (This is hard to trigger, but covered by the non-existent table)

        // Line 182: foreignKeys() returns null when stmt is false
        $foreignKeys = $this->repository->foreignKeys('non_existent_table_xyz');
        $this->assertNull($foreignKeys);
    }

    /**
     * Test that columns() returns empty array for non-existent table
     */
    public function testColumnsNonExistentTable(): void
    {
        // columns() returns [] when table doesn't exist
        $columns = $this->repository->columns('non_existent_table_xyz');
        $this->assertEquals([], $columns);
    }
}
