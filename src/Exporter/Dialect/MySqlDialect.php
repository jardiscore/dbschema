<?php

declare(strict_types=1);

namespace JardisCore\DbSchema\Exporter\Dialect;

use JardisCore\DbSchema\Exporter\Ddl\DdlDialectInterface;

/**
 * MySQL/MariaDB SQL dialect implementation for DDL generation.
 */
class MySqlDialect implements DdlDialectInterface
{
    public function typeMapping(string $dbType, ?int $length, ?int $precision, ?int $scale): string
    {
        $type = strtoupper($dbType);

        return match ($type) {
            // String types with length
            'VARCHAR', 'CHAR' => $type . '(' . ($length ?? 255) . ')',
            'VARBINARY', 'BINARY' => $type . '(' . ($length ?? 255) . ')',

            // Text types (no length)
            'TEXT', 'TINYTEXT', 'MEDIUMTEXT', 'LONGTEXT' => $type,
            'BLOB', 'TINYBLOB', 'MEDIUMBLOB', 'LONGBLOB' => $type,

            // Integer types
            'TINYINT' => 'TINYINT',
            'SMALLINT' => 'SMALLINT',
            'MEDIUMINT' => 'MEDIUMINT',
            'INT', 'INTEGER' => 'INT',
            'BIGINT' => 'BIGINT',

            // Decimal types with precision/scale
            'DECIMAL', 'NUMERIC' => $precision && $scale
                ? $type . '(' . $precision . ',' . $scale . ')'
                : ($precision ? $type . '(' . $precision . ')' : $type),

            // Float types
            'FLOAT' => $precision ? 'FLOAT(' . $precision . ')' : 'FLOAT',
            'DOUBLE', 'REAL' => 'DOUBLE',

            // Date/Time types
            'DATE' => 'DATE',
            'TIME' => 'TIME',
            'DATETIME' => 'DATETIME',
            'TIMESTAMP' => 'TIMESTAMP',
            'YEAR' => 'YEAR',

            // Boolean
            'BOOL', 'BOOLEAN' => 'TINYINT(1)',

            // JSON
            'JSON' => 'JSON',

            // Enum/Set (length contains definition)
            'ENUM', 'SET' => $type,

            // Default fallback
            default => $type,
        };
    }

    public function createTableStatement(string $tableName, array $columns, array $primaryKeys): string
    {
        $columnDefinitions = [];

        foreach ($columns as $column) {
            $def = '  `' . $column['name'] . '` ';
            $def .= $this->typeMapping(
                $column['type'],
                $column['length'],
                $column['precision'],
                $column['scale']
            );

            if (!$column['nullable']) {
                $def .= ' NOT NULL';
            }

            if ($column['auto_increment']) {
                $def .= ' AUTO_INCREMENT';
            }

            if ($column['default'] !== null && !$column['auto_increment']) {
                $def .= " DEFAULT '" . addslashes($column['default']) . "'";
            }

            $columnDefinitions[] = $def;
        }

        // Add primary key constraint
        if (!empty($primaryKeys)) {
            $pkColumns = array_map(fn($col) => '`' . $col . '`', $primaryKeys);
            $columnDefinitions[] = '  PRIMARY KEY (' . implode(', ', $pkColumns) . ')';
        }

        $sql = "CREATE TABLE `{$tableName}` (\n";
        $sql .= implode(",\n", $columnDefinitions);
        $sql .= "\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";

        return $sql;
    }

    public function createIndexStatement(string $tableName, array $index): string
    {
        // Skip PRIMARY indexes (handled in CREATE TABLE)
        if ($index['name'] === 'PRIMARY' || $index['index_type'] === 'primary') {
            return '';
        }

        $indexName = $index['name'];
        $columnName = $index['column_name'];
        $isUnique = $index['is_unique'] ?? false;

        $uniqueKeyword = $isUnique ? 'UNIQUE ' : '';

        return "CREATE {$uniqueKeyword}INDEX `{$indexName}` ON `{$tableName}` (`{$columnName}`);";
    }

    public function createForeignKeyStatement(string $tableName, array $foreignKey): string
    {
        $constraintName = $foreignKey['constraintName'];
        $column = $foreignKey['constraintCol'];
        $refTable = $foreignKey['refContainer'];
        $refColumn = $foreignKey['refColumn'];
        $onUpdate = $foreignKey['onUpdate'] ?? 'RESTRICT';
        $onDelete = $foreignKey['onDelete'] ?? 'RESTRICT';

        return "ALTER TABLE `{$tableName}` ADD CONSTRAINT `{$constraintName}` " .
               "FOREIGN KEY (`{$column}`) REFERENCES `{$refTable}` (`{$refColumn}`) " .
               "ON UPDATE {$onUpdate} ON DELETE {$onDelete};";
    }

    public function dropTableStatement(string $tableName): string
    {
        return "DROP TABLE IF EXISTS `{$tableName}`;";
    }

    public function beginTransaction(): string
    {
        return 'START TRANSACTION;';
    }

    public function commitTransaction(): string
    {
        return 'COMMIT;';
    }
}
