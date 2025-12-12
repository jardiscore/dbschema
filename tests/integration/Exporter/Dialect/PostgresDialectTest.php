<?php

declare(strict_types=1);

namespace JardisCore\DbSchema\Tests\integration\Exporter\Dialect;

use JardisCore\DbSchema\Exporter\Dialect\PostgresDialect;
use PHPUnit\Framework\TestCase;

class PostgresDialectTest extends TestCase
{
    private PostgresDialect $dialect;

    protected function setUp(): void
    {
        $this->dialect = new PostgresDialect();
    }

    public function testTypeMapping(): void
    {
        // String types
        $this->assertEquals('VARCHAR(255)', $this->dialect->typeMapping('varchar', 255, null, null));
        $this->assertEquals('CHAR(10)', $this->dialect->typeMapping('char', 10, null, null));
        $this->assertEquals('TEXT', $this->dialect->typeMapping('text', null, null, null));

        // Integer types
        $this->assertEquals('INTEGER', $this->dialect->typeMapping('int', null, null, null));
        $this->assertEquals('INTEGER', $this->dialect->typeMapping('integer', null, null, null));
        $this->assertEquals('BIGINT', $this->dialect->typeMapping('bigint', null, null, null));
        $this->assertEquals('SMALLINT', $this->dialect->typeMapping('smallint', null, null, null));

        // Serial types
        $this->assertEquals('SERIAL', $this->dialect->typeMapping('serial', null, null, null));
        $this->assertEquals('BIGSERIAL', $this->dialect->typeMapping('bigserial', null, null, null));

        // Decimal types
        $this->assertEquals('DECIMAL(10,2)', $this->dialect->typeMapping('decimal', null, 10, 2));
        $this->assertEquals('NUMERIC(10)', $this->dialect->typeMapping('numeric', null, 10, null));

        // Float types
        $this->assertEquals('REAL', $this->dialect->typeMapping('real', null, null, null));
        $this->assertEquals('DOUBLE PRECISION', $this->dialect->typeMapping('double precision', null, null, null));

        // Date/Time types
        $this->assertEquals('TIMESTAMP', $this->dialect->typeMapping('timestamp', null, null, null));
        $this->assertEquals('DATE', $this->dialect->typeMapping('date', null, null, null));

        // Boolean
        $this->assertEquals('BOOLEAN', $this->dialect->typeMapping('bool', null, null, null));

        // JSON
        $this->assertEquals('JSON', $this->dialect->typeMapping('json', null, null, null));
        $this->assertEquals('JSONB', $this->dialect->typeMapping('jsonb', null, null, null));
    }

    public function testCreateTableStatementWithSerial(): void
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
        $this->assertStringContainsString('"id" SERIAL NOT NULL', $sql);
        $this->assertStringContainsString('"name" VARCHAR(255) NOT NULL', $sql);
        $this->assertStringContainsString('PRIMARY KEY ("id")', $sql);
    }

    public function testCreateTableStatementWithBigSerial(): void
    {
        $columns = [
            [
                'name' => 'id',
                'type' => 'bigint',
                'length' => null,
                'precision' => null,
                'scale' => null,
                'nullable' => false,
                'default' => null,
                'primary' => true,
                'auto_increment' => true,
            ],
        ];

        $sql = $this->dialect->createTableStatement('users', $columns, ['id']);

        $this->assertStringContainsString('"id" BIGSERIAL NOT NULL', $sql);
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
                'auto_increment' => false,
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

        $this->assertStringContainsString('"status" VARCHAR(20) DEFAULT \'active\'', $sql);
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

        $this->assertStringContainsString('ALTER TABLE "orders"', $sql);
        $this->assertStringContainsString('ADD CONSTRAINT "fk_orders_user"', $sql);
        $this->assertStringContainsString('FOREIGN KEY ("user_id")', $sql);
        $this->assertStringContainsString('REFERENCES "users" ("id")', $sql);
        $this->assertStringContainsString('ON UPDATE CASCADE', $sql);
        $this->assertStringContainsString('ON DELETE RESTRICT', $sql);
    }

    public function testDropTableStatement(): void
    {
        $sql = $this->dialect->dropTableStatement('users');

        $this->assertEquals('DROP TABLE IF EXISTS "users" CASCADE;', $sql);
    }

    public function testBeginTransaction(): void
    {
        $sql = $this->dialect->beginTransaction();

        $this->assertEquals('BEGIN;', $sql);
    }

    public function testCommitTransaction(): void
    {
        $sql = $this->dialect->commitTransaction();

        $this->assertEquals('COMMIT;', $sql);
    }
}
