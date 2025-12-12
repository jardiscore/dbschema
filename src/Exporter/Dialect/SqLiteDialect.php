<?php

declare(strict_types=1);

namespace JardisCore\DbSchema\Exporter\Dialect;

use JardisCore\DbSchema\Exporter\Ddl\DdlDialectInterface;

/**
 * SQLite SQL dialect implementation for DDL generation.
 */
class SqLiteDialect implements DdlDialectInterface
{
    public function typeMapping(string $dbType, ?int $length, ?int $precision, ?int $scale): string
    {
        $type = strtoupper($dbType);

        // SQLite has a simplified type system (type affinity)
        // https://www.sqlite.org/datatype3.html
        return match ($type) {
            // Integer affinity
            'INT', 'INTEGER', 'TINYINT', 'SMALLINT', 'MEDIUMINT', 'BIGINT',
            'UNSIGNED BIG INT', 'INT2', 'INT8', 'SERIAL' => 'INTEGER',

            // Text affinity
            'CHARACTER', 'VARCHAR', 'VARYING CHARACTER', 'NCHAR',
            'NATIVE CHARACTER', 'NVARCHAR', 'TEXT', 'CLOB' => 'TEXT',

            // Real affinity
            'REAL', 'DOUBLE', 'DOUBLE PRECISION', 'FLOAT', 'NUMERIC' => 'REAL',

            // Blob affinity
            'BLOB', 'BYTEA' => 'BLOB',

            // Special handling for DECIMAL (TEXT in SQLite for precision)
            'DECIMAL' => $precision && $scale ? "DECIMAL({$precision},{$scale})" : 'NUMERIC',

            // Date/Time (stored as TEXT in SQLite)
            'DATE', 'DATETIME', 'TIMESTAMP', 'TIME' => 'TEXT',

            // Boolean (stored as INTEGER 0/1 in SQLite)
            'BOOL', 'BOOLEAN' => 'INTEGER',

            // Default fallback
            default => $type,
        };
    }

    public function createTableStatement(string $tableName, array $columns, array $primaryKeys): string
    {
        $columnDefinitions = [];

        foreach ($columns as $column) {
            $def = '  "' . $column['name'] . '" ';
            $def .= $this->typeMapping(
                $column['type'],
                $column['length'],
                $column['precision'],
                $column['scale']
            );

            // SQLite requires PRIMARY KEY and AUTOINCREMENT on the same line as column definition
            // for single-column primary keys
            $isSinglePrimaryKey = count($primaryKeys) === 1 && $primaryKeys[0] === $column['name'];

            if ($isSinglePrimaryKey) {
                $def .= ' PRIMARY KEY';
                if ($column['auto_increment']) {
                    $def .= ' AUTOINCREMENT';
                }
            }

            if (!$column['nullable'] && !$isSinglePrimaryKey) {
                $def .= ' NOT NULL';
            }

            if ($column['default'] !== null && !$column['auto_increment']) {
                // SQLite default handling
                if (
                    in_array(
                        strtolower($column['type']),
                        ['text', 'varchar', 'char', 'date', 'datetime', 'timestamp', 'time']
                    )
                ) {
                    $def .= " DEFAULT '" . addslashes($column['default']) . "'";
                } else {
                    $def .= ' DEFAULT ' . $column['default'];
                }
            }

            $columnDefinitions[] = $def;
        }

        // Add composite primary key constraint if applicable
        if (count($primaryKeys) > 1) {
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
        // SQLite does not support ALTER TABLE ... ADD CONSTRAINT for foreign keys
        // Foreign keys must be defined in CREATE TABLE statement
        // For generated DDL, we'll return an empty string and rely on inline FK definitions
        // This is a limitation of SQLite's DDL

        // Note: To properly support FK in SQLite DDL generation, we would need to:
        // 1. Include FOREIGN KEY constraints in CREATE TABLE
        // 2. Return empty string here

        // For now, we'll generate a comment indicating this limitation
        $column = $foreignKey['constraintCol'];
        $refTable = $foreignKey['refContainer'];
        $refColumn = $foreignKey['refColumn'];
        $onUpdate = $foreignKey['onUpdate'] ?? 'NO ACTION';
        $onDelete = $foreignKey['onDelete'] ?? 'NO ACTION';

        return '-- Foreign key constraint for "' .
            $tableName . '"("' . $column . '") -> "' . $refTable . '"("' . $refColumn . '") ' .
            'ON UPDATE ' . $onUpdate . ' ON DELETE ' . $onDelete;
    }

    public function dropTableStatement(string $tableName): string
    {
        return 'DROP TABLE IF EXISTS "' . $tableName . '";';
    }

    public function beginTransaction(): string
    {
        return 'BEGIN TRANSACTION;';
    }

    public function commitTransaction(): string
    {
        return 'COMMIT;';
    }
}
