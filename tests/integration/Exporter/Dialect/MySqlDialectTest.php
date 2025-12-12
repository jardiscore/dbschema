<?php

declare(strict_types=1);

namespace JardisCore\DbSchema\Tests\integration\Exporter\Dialect;

use JardisCore\DbSchema\Exporter\Dialect\MySqlDialect;
use PHPUnit\Framework\TestCase;

class MySqlDialectTest extends TestCase
{
    private MySqlDialect $dialect;

    protected function setUp(): void
    {
        $this->dialect = new MySqlDialect();
    }

    public function testTypeMapping(): void
    {
        // String types
        $this->assertEquals('VARCHAR(255)', $this->dialect->typeMapping('varchar', 255, null, null));
        $this->assertEquals('CHAR(10)', $this->dialect->typeMapping('char', 10, null, null));
        $this->assertEquals('TEXT', $this->dialect->typeMapping('text', null, null, null));

        // Integer types
        $this->assertEquals('INT', $this->dialect->typeMapping('int', null, null, null));
        $this->assertEquals('BIGINT', $this->dialect->typeMapping('bigint', null, null, null));
        $this->assertEquals('TINYINT', $this->dialect->typeMapping('tinyint', null, null, null));

        // Decimal types
        $this->assertEquals('DECIMAL(10,2)', $this->dialect->typeMapping('decimal', null, 10, 2));
        $this->assertEquals('DECIMAL(10)', $this->dialect->typeMapping('decimal', null, 10, null));

        // Float types
        $this->assertEquals('FLOAT(7)', $this->dialect->typeMapping('float', null, 7, null));
        $this->assertEquals('DOUBLE', $this->dialect->typeMapping('double', null, null, null));

        // Date/Time types
        $this->assertEquals('DATETIME', $this->dialect->typeMapping('datetime', null, null, null));
        $this->assertEquals('TIMESTAMP', $this->dialect->typeMapping('timestamp', null, null, null));
        $this->assertEquals('DATE', $this->dialect->typeMapping('date', null, null, null));

        // Boolean
        $this->assertEquals('TINYINT(1)', $this->dialect->typeMapping('bool', null, null, null));

        // JSON
        $this->assertEquals('JSON', $this->dialect->typeMapping('json', null, null, null));
    }

    public function testCreateTableStatement(): void
    {
        $columns = [
            [
                'name' => 'id',
                'type' => 'int',
                'length' => null,
                'precision' => null,
                'scale' => null,
                'nullable' => false,
                'default' => null,
                'primary' => true,
                'auto_increment' => true,
            ],
            [
                'name' => 'name',
                'type' => 'varchar',
                'length' => 255,
                'precision' => null,
                'scale' => null,
                'nullable' => false,
                'default' => null,
                'primary' => false,
                'auto_increment' => false,
            ],
            [
                'name' => 'balance',
                'type' => 'decimal',
                'length' => null,
                'precision' => 10,
                'scale' => 2,
                'nullable' => true,
                'default' => '0.00',
                'primary' => false,
                'auto_increment' => false,
            ],
        ];

        $sql = $this->dialect->createTableStatement('users', $columns, ['id']);

        $this->assertStringContainsString('CREATE TABLE `users`', $sql);
        $this->assertStringContainsString('`id` INT NOT NULL AUTO_INCREMENT', $sql);
        $this->assertStringContainsString('`name` VARCHAR(255) NOT NULL', $sql);
        $this->assertStringContainsString('`balance` DECIMAL(10,2)', $sql);
        $this->assertStringContainsString("DEFAULT '0.00'", $sql);
        $this->assertStringContainsString('PRIMARY KEY (`id`)', $sql);
        $this->assertStringContainsString('ENGINE=InnoDB DEFAULT CHARSET=utf8mb4', $sql);
    }

    public function testCreateIndexStatement(): void
    {
        $index = [
            'name' => 'idx_email',
            'column_name' => 'email',
            'is_unique' => false,
            'index_type' => 'index',
        ];

        $sql = $this->dialect->createIndexStatement('users', $index);

        $this->assertEquals('CREATE INDEX `idx_email` ON `users` (`email`);', $sql);
    }

    public function testCreateUniqueIndexStatement(): void
    {
        $index = [
            'name' => 'idx_email_unique',
            'column_name' => 'email',
            'is_unique' => true,
            'index_type' => 'unique',
        ];

        $sql = $this->dialect->createIndexStatement('users', $index);

        $this->assertEquals('CREATE UNIQUE INDEX `idx_email_unique` ON `users` (`email`);', $sql);
    }

    public function testCreateIndexSkipsPrimary(): void
    {
        $index = [
            'name' => 'PRIMARY',
            'column_name' => 'id',
            'is_unique' => true,
            'index_type' => 'primary',
        ];

        $sql = $this->dialect->createIndexStatement('users', $index);

        $this->assertEquals('', $sql);
    }

    public function testCreateForeignKeyStatement(): void
    {
        $foreignKey = [
            'constraintName' => 'fk_orders_user',
            'constraintCol' => 'user_id',
            'refContainer' => 'users',
            'refColumn' => 'id',
            'onUpdate' => 'CASCADE',
            'onDelete' => 'RESTRICT',
        ];

        $sql = $this->dialect->createForeignKeyStatement('orders', $foreignKey);

        $this->assertStringContainsString('ALTER TABLE `orders`', $sql);
        $this->assertStringContainsString('ADD CONSTRAINT `fk_orders_user`', $sql);
        $this->assertStringContainsString('FOREIGN KEY (`user_id`)', $sql);
        $this->assertStringContainsString('REFERENCES `users` (`id`)', $sql);
        $this->assertStringContainsString('ON UPDATE CASCADE', $sql);
        $this->assertStringContainsString('ON DELETE RESTRICT', $sql);
    }

    public function testDropTableStatement(): void
    {
        $sql = $this->dialect->dropTableStatement('users');

        $this->assertEquals('DROP TABLE IF EXISTS `users`;', $sql);
    }

    public function testBeginTransaction(): void
    {
        $sql = $this->dialect->beginTransaction();

        $this->assertEquals('START TRANSACTION;', $sql);
    }

    public function testCommitTransaction(): void
    {
        $sql = $this->dialect->commitTransaction();

        $this->assertEquals('COMMIT;', $sql);
    }
}
