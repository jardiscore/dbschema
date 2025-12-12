<?php

declare(strict_types=1);

namespace JardisCore\DbSchema\Tests\integration\Reader;

use JardisCore\DbSchema\Reader\MySqlReader;
use JardisCore\DbSchema\Tests\PdoFactory;
use JardisCore\DbSchema\Tests\SchemaBuilder;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class MySqlReaderTest extends TestCase
{
    public function testBooleanColumnNormalization(): void
    {
        $pdo = PdoFactory::createMySqlPdo();
        SchemaBuilder::createMySqlTestSchema($pdo);

        $repository = new MySqlReader($pdo);

        // Test that boolean columns are properly normalized
        $columns = $repository->columns('users');

        $this->assertIsArray($columns);

        // Check that all boolean fields are actually boolean type
        foreach ($columns as $column) {
            $this->assertIsBool($column['nullable'], "nullable should be boolean");
            $this->assertIsBool($column['primary'], "primary should be boolean");
            $this->assertIsBool($column['auto_increment'], "auto_increment should be boolean");
        }

        // Specifically check the is_active column (boolean field)
        $isActiveColumn = array_filter($columns, fn($c) => $c['name'] === 'is_active');
        $this->assertCount(1, $isActiveColumn, "Should find is_active column");
    }

    public function testToBoolMethodWithActualBoolean(): void
    {
        // This test covers line 115: the is_bool($value) check
        $pdo = PdoFactory::createMySqlPdo();
        $repository = new MySqlReader($pdo);

        // Use reflection to test the private toBool method with actual boolean
        $reflection = new ReflectionClass($repository);
        $method = $reflection->getMethod('toBool');
        $method->setAccessible(true);

        // Test with actual boolean values (line 115)
        $this->assertTrue($method->invoke($repository, true));
        $this->assertFalse($method->invoke($repository, false));

        // Test with string/int values (normal path)
        $this->assertTrue($method->invoke($repository, 1));
        $this->assertTrue($method->invoke($repository, '1'));
        $this->assertFalse($method->invoke($repository, 0));
        $this->assertFalse($method->invoke($repository, '0'));
    }
}
