<?php

declare(strict_types=1);

namespace JardisCore\DbSchema\Exporter\Dialect;

use JardisCore\DbSchema\Exporter\Ddl\DdlDialectInterface;

/**
 * PostgreSQL SQL dialect implementation for DDL generation.
 */
class PostgresDialect implements DdlDialectInterface
{
    public function typeMapping(string $dbType, ?int $length, ?int $precision, ?int $scale): string
    {
        $type = strtoupper($dbType);

        return match ($type) {
            // String types with length
            'VARCHAR', 'CHARACTER VARYING' => $length ? 'VARCHAR(' . $length . ')' : 'VARCHAR',
            'CHAR', 'CHARACTER' => $length ? 'CHAR(' . $length . ')' : 'CHAR',

            // Text types (no length)
            'TEXT' => 'TEXT',

            // Integer types
            'SMALLINT', 'INT2' => 'SMALLINT',
            'INTEGER', 'INT', 'INT4' => 'INTEGER',
            'BIGINT', 'INT8' => 'BIGINT',

            // Serial types (auto-increment)
            'SERIAL', 'SERIAL4' => 'SERIAL',
            'BIGSERIAL', 'SERIAL8' => 'BIGSERIAL',
            'SMALLSERIAL', 'SERIAL2' => 'SMALLSERIAL',

            // Decimal types with precision/scale
            'DECIMAL', 'NUMERIC' => $precision && $scale
                ? $type . '(' . $precision . ',' . $scale . ')'
                : ($precision ? $type . '(' . $precision . ')' : $type),

            // Float types
            'REAL', 'FLOAT4' => 'REAL',
            'DOUBLE PRECISION', 'FLOAT8', 'DOUBLE' => 'DOUBLE PRECISION',

            // Date/Time types
            'DATE' => 'DATE',
            'TIME' => 'TIME',
            'TIMESTAMP' => 'TIMESTAMP',
            'TIMESTAMPTZ', 'TIMESTAMP WITH TIME ZONE' => 'TIMESTAMP WITH TIME ZONE',
            'TIMETZ', 'TIME WITH TIME ZONE' => 'TIME WITH TIME ZONE',
            'INTERVAL' => 'INTERVAL',

            // Boolean
            'BOOL', 'BOOLEAN' => 'BOOLEAN',

            // JSON
            'JSON' => 'JSON',
            'JSONB' => 'JSONB',

            // Binary
            'BYTEA' => 'BYTEA',

            // UUID
            'UUID' => 'UUID',

            // Array types
            'ARRAY' => 'ARRAY',

            // Default fallback
            default => $type,
        };
    }

    public function createTableStatement(string $tableName, array $columns, array $primaryKeys): string
    {
        $columnDefinitions = [];

        foreach ($columns as $column) {
            $def = '  "' . $column['name'] . '" ';

            // PostgreSQL uses SERIAL for auto-increment integers
            if ($column['auto_increment'] && in_array(strtolower($column['type']), ['int', 'integer', 'int4'])) {
                $def .= 'SERIAL';
            } elseif ($column['auto_increment'] && in_array(strtolower($column['type']), ['bigint', 'int8'])) {
                $def .= 'BIGSERIAL';
            } elseif ($column['auto_increment'] && in_array(strtolower($column['type']), ['smallint', 'int2'])) {
                $def .= 'SMALLSERIAL';
            } else {
                $def .= $this->typeMapping(
                    $column['type'],
                    $column['length'],
                    $column['precision'],
                    $column['scale']
                );
            }

            if (!$column['nullable']) {
                $def .= ' NOT NULL';
            }

            if ($column['default'] !== null && !$column['auto_increment']) {
                // PostgreSQL requires proper quoting for string defaults
                if (
                    in_array(
                        strtolower($column['type']),
                        ['varchar', 'char', 'text', 'character', 'character varying']
                    )
                ) {
                    $def .= " DEFAULT '" . addslashes($column['default']) . "'";
                } else {
                    $def .= ' DEFAULT ' . $column['default'];
                }
            }

            $columnDefinitions[] = $def;
        }

        // Add primary key constraint
        if (!empty($primaryKeys)) {
            $pkColumns = array_map(fn($col) => '"' . $col . '"', $primaryKeys);
            $columnDefinitions[] = '  PRIMARY KEY (' . implode(', ', $pkColumns) . ')';
        }

        $sql = 'CREATE TABLE "' . $tableName . '" (' . "\n";
        $sql .= implode(",\n", $columnDefinitions);
        $sql .= "\n);";

        return $sql;
    }

    public function createIndexStatement(string $tableName, array $index): string
    {
        // Skip PRIMARY indexes (handled in CREATE TABLE)
        if ($index['name'] === 'PRIMARY' || ($index['index_type'] ?? '') === 'primary') {
            return '';
        }

        $indexName = $index['name'];
        $columnName = $index['column_name'];
        $isUnique = $index['is_unique'] ?? false;

        $uniqueKeyword = $isUnique ? 'UNIQUE ' : '';

        return 'CREATE ' .
            $uniqueKeyword .
            'INDEX "' .
            $indexName .
            '" ON "' .
            $tableName .
            '" ("' . $columnName . '");';
    }

    public function createForeignKeyStatement(string $tableName, array $foreignKey): string
    {
        $constraintName = $foreignKey['constraintName'];
        $column = $foreignKey['constraintCol'];
        $refTable = $foreignKey['refContainer'];
        $refColumn = $foreignKey['refColumn'];
        $onUpdate = $foreignKey['onUpdate'] ?? 'NO ACTION';
        $onDelete = $foreignKey['onDelete'] ?? 'NO ACTION';

        return 'ALTER TABLE "' . $tableName . '" ADD CONSTRAINT "' . $constraintName . '" ' .
               'FOREIGN KEY ("' . $column . '") REFERENCES "' . $refTable . '" ("' . $refColumn . '") ' .
               'ON UPDATE ' . $onUpdate . ' ON DELETE ' . $onDelete . ';';
    }

    public function dropTableStatement(string $tableName): string
    {
        return 'DROP TABLE IF EXISTS "' . $tableName . '" CASCADE;';
    }

    public function beginTransaction(): string
    {
        return 'BEGIN;';
    }

    public function commitTransaction(): string
    {
        return 'COMMIT;';
    }
}
