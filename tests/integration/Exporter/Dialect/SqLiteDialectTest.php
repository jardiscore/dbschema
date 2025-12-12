<?php

declare(strict_types=1);

namespace JardisCore\DbSchema\Tests\integration\Exporter\Dialect;

use JardisCore\DbSchema\Exporter\Dialect\SqLiteDialect;
use PHPUnit\Framework\TestCase;

class SqLiteDialectTest extends TestCase
{
    private SqLiteDialect $dialect;

    protected function setUp(): void
    {
        $this->dialect = new SqLiteDialect();
    }

    public function testTypeMapping(): void
    {
        // Integer types (all map to INTEGER)
        $this->assertEquals('INTEGER', $this->dialect->typeMapping('int', null, null, null));
        $this->assertEquals('INTEGER', $this->dialect->typeMapping('integer', null, null, null));
        $this->assertEquals('INTEGER', $this->dialect->typeMapping('bigint', null, null, null));
        $this->assertEquals('INTEGER', $this->dialect->typeMapping('tinyint', null, null, null));

        // Text types (most map to TEXT, but CHAR is preserved by SQLite)
        $this->assertEquals('TEXT', $this->dialect->typeMapping('varchar', 255, null, null));
        $this->assertEquals('CHAR', $this->dialect->typeMapping('char', 10, null, null)); // SQLite preserves CHAR
        $this->assertEquals('TEXT', $this->dialect->typeMapping('text', null, null, null));

        // Real types
        $this->assertEquals('REAL', $this->dialect->typeMapping('real', null, null, null));
        $this->assertEquals('REAL', $this->dialect->typeMapping('float', null, null, null));
        $this->assertEquals('REAL', $this->dialect->typeMapping('double', null, null, null));
        $this->assertEquals('REAL', $this->dialect->typeMapping('numeric', null, null, null));

        // Decimal with precision
        $this->assertEquals('DECIMAL(10,2)', $this->dialect->typeMapping('decimal', null, 10, 2));

        // Blob
        $this->assertEquals('BLOB', $this->dialect->typeMapping('blob', null, null, null));

        // Date/Time (stored as TEXT in SQLite)
        $this->assertEquals('TEXT', $this->dialect->typeMapping('datetime', null, null, null));
        $this->assertEquals('TEXT', $this->dialect->typeMapping('date', null, null, null));
        $this->assertEquals('TEXT', $this->dialect->typeMapping('timestamp', null, null, null));

        // Boolean (stored as INTEGER in SQLite)
        $this->assertEquals('INTEGER', $this->dialect->typeMapping('bool', null, null, null));
    }

    public function testCreateTableStatementWithSinglePrimaryKey(): void
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
        ];

        $sql = $this->dialect->createTableStatement('users', $columns, ['id']);

        $this->assertStringContainsString('CREATE TABLE "users"', $sql);
        $this->assertStringContainsString('"id" INTEGER PRIMARY KEY AUTOINCREMENT', $sql);
        $this->assertStringContainsString('"name" TEXT NOT NULL', $sql);
        // Should NOT have a separate PRIMARY KEY constraint
        $this->assertStringNotContainsString('PRIMARY KEY ("id")', $sql);
    }

    public function testCreateTableStatementWithCompositePrimaryKey(): void
    {
        $columns = [
            [
                'name' => 'user_id',
                'type' => 'int',
                'length' => null,
                'precision' => null,
                'scale' => null,
                'nullable' => false,
                'default' => null,
                'primary' => true,
                'auto_increment' => false,
            ],
            [
                'name' => 'role_id',
                'type' => 'int',
                'length' => null,
                'precision' => null,
                'scale' => null,
                'nullable' => false,
                'default' => null,
                'primary' => true,
                'auto_increment' => false,
            ],
        ];

        $sql = $this->dialect->createTableStatement('user_roles', $columns, ['user_id', 'role_id']);

        $this->assertStringContainsString('CREATE TABLE "user_roles"', $sql);
        $this->assertStringContainsString('"user_id" INTEGER NOT NULL', $sql);
        $this->assertStringContainsString('"role_id" INTEGER NOT NULL', $sql);
        $this->assertStringContainsString('PRIMARY KEY ("user_id", "role_id")', $sql);
    }

    public function testCreateTableStatementWithDefaults(): void
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
                'name' => 'status',
                'type' => 'varchar',
                'length' => 20,
                'precision' => null,
                'scale' => null,
                'nullable' => true,
                'default' => 'active',
                'primary' => false,
                'auto_increment' => false,
            ],
            [
                'name' => 'count',
                'type' => 'int',
                'length' => null,
                'precision' => null,
                'scale' => null,
                'nullable' => true,
                'default' => '0',
                'primary' => false,
                'auto_increment' => false,
            ],
        ];

        $sql = $this->dialect->createTableStatement('test', $columns, ['id']);

        $this->assertStringContainsString('"status" TEXT DEFAULT \'active\'', $sql);
        $this->assertStringContainsString('"count" INTEGER DEFAULT 0', $sql);
    }

    public function testCreateIndexStatement(): void
    {
        $index = [
            'name' => 'idx_email',
            'column_name' => 'email',
            'is_unique' => false,
        ];

        $sql = $this->dialect->createIndexStatement('users', $index);

        $this->assertEquals('CREATE INDEX "idx_email" ON "users" ("email");', $sql);
    }

    public function testCreateUniqueIndexStatement(): void
    {
        $index = [
            'name' => 'idx_email_unique',
            'column_name' => 'email',
            'is_unique' => true,
        ];

        $sql = $this->dialect->createIndexStatement('users', $index);

        $this->assertEquals('CREATE UNIQUE INDEX "idx_email_unique" ON "users" ("email");', $sql);
    }

    public function testCreateForeignKeyStatementReturnsComment(): void
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

        // SQLite doesn't support ALTER TABLE ADD FOREIGN KEY
        // Should return a comment instead
        $this->assertStringStartsWith('-- Foreign key constraint', $sql);
        $this->assertStringContainsString('"orders"', $sql);
        $this->assertStringContainsString('"user_id"', $sql);
        $this->assertStringContainsString('"users"', $sql);
        $this->assertStringContainsString('"id"', $sql);
        $this->assertStringContainsString('ON UPDATE CASCADE', $sql);
        $this->assertStringContainsString('ON DELETE RESTRICT', $sql);
    }

    public function testDropTableStatement(): void
    {
        $sql = $this->dialect->dropTableStatement('users');

        $this->assertEquals('DROP TABLE IF EXISTS "users";', $sql);
    }

    public function testBeginTransaction(): void
    {
        $sql = $this->dialect->beginTransaction();

        $this->assertEquals('BEGIN TRANSACTION;', $sql);
    }

    public function testCommitTransaction(): void
    {
        $sql = $this->dialect->commitTransaction();

        $this->assertEquals('COMMIT;', $sql);
    }
}
