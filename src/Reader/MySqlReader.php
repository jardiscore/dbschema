<?php

declare(strict_types=1);

namespace JardisCore\DbSchema\Reader;

use JardisPsr\DbSchema\DbSchemaInterface;
use PDO;

/**
 * MySQL database schema reader.
 *
 * This class provides methods to read and analyze MySQL database schema. It implements
 * the `DbSchemaInterface` and offers functionalities for retrieving database schema
 * details such as tables, columns, indexes, and foreign keys.
 */
class MySqlReader implements DbSchemaInterface
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function tables(): ?array
    {
        $stmt = $this->pdo->prepare("
            SELECT TABLE_NAME AS name,
                   TABLE_TYPE AS type
            FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_TYPE = 'BASE TABLE'
            ORDER BY TABLE_NAME
        ");

        $stmt->execute();

        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return $results ?: null;
    }

    /**
     * @param string $table
     * @param array<string>|null $fields
     * @return array<int, array<string, mixed>>|null
     */
    public function columns(string $table, ?array $fields = null): ?array
    {
        $stmt = $this->pdo->prepare("
                SELECT COLUMN_NAME AS name,
                       DATA_TYPE AS type,
                       COLUMN_TYPE AS columnType,
                       CHARACTER_MAXIMUM_LENGTH AS length,
                       NUMERIC_PRECISION AS `precision`,
                       NUMERIC_SCALE AS scale,
                       IS_NULLABLE = 'YES' AS nullable,
                       COLUMN_DEFAULT AS `default`,
                       COLUMN_KEY = 'PRI' AS `primary`,
                       EXTRA = 'auto_increment' AS auto_increment
                FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE()
                  AND TABLE_NAME = :table
            ");

        $stmt->execute(['table' => $table]);

        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Normalize data types for consistent PHP usage
        foreach ($results as &$column) {
            $column = $this->normalizeColumnTypes($column);
        }

        if ($fields !== null) {
            $results = array_filter(
                $results,
                fn(array $row) => in_array($row['name'], $fields)
            );

            // Sort results in the order specified by $fields
            usort($results, function ($a, $b) use ($fields) {
                $posA = array_search($a['name'], $fields);
                $posB = array_search($b['name'], $fields);
                return $posA <=> $posB;
            });
        }

        return $results;
    }

    /**
     * Normalize the data types of a database column array.
     *
     * @param array<string, mixed> $column The database column metadata array, including keys such as 'nullable',
     *                      'primary', 'auto_increment', 'length', 'precision', and 'scale'.
     * @return array<string, mixed> The normalized column array where boolean values are converted to PHP boolean
     *               types and numeric values are standardized to integers or null.
     */
    private function normalizeColumnTypes(array $column): array
    {
        // Normalize boolean values (MySQL returns '0'/'1' as strings)
        $column['nullable'] = $this->toBool($column['nullable']);
        $column['primary'] = $this->toBool($column['primary']);
        $column['auto_increment'] = $this->toBool($column['auto_increment']);

        // Normalize numeric values
        $column['length'] = $this->toIntOrNull($column['length']);
        $column['precision'] = $this->toIntOrNull($column['precision']);
        $column['scale'] = $this->toIntOrNull($column['scale']);

        // Extract ENUM values if present
        if ($column['type'] === 'enum' && isset($column['columnType'])) {
            $column['enumValues'] = $this->extractEnumValues($column['columnType']);
        }

        // Remove temporary columnType field
        unset($column['columnType']);

        return $column;
    }

    /**
     * Extract enum values from COLUMN_TYPE string.
     *
     * @param string $columnType E.g., "enum('draft','published','archived')"
     * @return array<int, string>|null Array of enum values or null if not an enum
     */
    private function extractEnumValues(string $columnType): ?array
    {
        // Check if it's an enum type
        if (!str_starts_with(strtolower($columnType), 'enum(')) {
            return null;
        }

        // Extract values: enum('active','inactive') → 'active','inactive'
        if (!preg_match('/^enum\((.*)\)$/i', $columnType, $matches)) {
            return null;
        }

        $valuesString = $matches[1];

        // Split by comma, but respect quotes
        // Pattern matches: 'value' or "value"
        if (!preg_match_all("/['\"]([^'\"]*)['\"]/", $valuesString, $valueMatches)) {
            return null;
        }

        return $valueMatches[1];
    }

    /**
     * Convert MySQL boolean values (0/1, '0'/'1') to PHP boolean
     */
    private function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return in_array($value, [1, '1', true], true);
    }

    /**
     * Convert value to integer or null if empty/null
     */
    private function toIntOrNull(mixed $value): ?int
    {
        if ($value === null || $value === '' || $value === '0') {
            return null;
        }

        return is_numeric($value) ? (int) $value : null;
    }

    public function indexes(string $table): ?array
    {
        $stmt = $this->pdo->prepare("
            SELECT 
                INDEX_NAME as name,
                COLUMN_NAME as column_name,
                NON_UNIQUE = 0 as is_unique,
                INDEX_TYPE as type,
                SEQ_IN_INDEX as sequence,
                SUB_PART as sub_part,
                NULLABLE as nullable,
                CASE 
                    WHEN INDEX_NAME = 'PRIMARY' THEN 'primary'
                    WHEN NON_UNIQUE = 0 THEN 'unique'
                    ELSE 'index'
                END as index_type
            FROM information_schema.STATISTICS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = :table
            ORDER BY INDEX_NAME, SEQ_IN_INDEX
        ");

        $stmt->execute(['table' => $table]);

        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Normalize boolean values for indexes
        foreach ($results as &$index) {
            $index['is_unique'] = $this->toBool($index['is_unique']);
        }

        return $results ?: null;
    }

    public function foreignKeys(string $table): ?array
    {
        $stmt = $this->pdo->prepare("
            SELECT 
                kcu.TABLE_NAME as container,
                kcu.CONSTRAINT_NAME as constraintName,
                kcu.COLUMN_NAME as constraintCol,
                kcu.REFERENCED_TABLE_NAME as refContainer,
                kcu.REFERENCED_COLUMN_NAME as refColumn,
                kcu.REFERENCED_TABLE_SCHEMA as refSchema,
                rc.UPDATE_RULE as onUpdate,
                rc.DELETE_RULE as onDelete,
                kcu.ORDINAL_POSITION as sequence
            FROM information_schema.KEY_COLUMN_USAGE kcu
            INNER JOIN information_schema.REFERENTIAL_CONSTRAINTS rc
                ON kcu.CONSTRAINT_NAME = rc.CONSTRAINT_NAME
                AND kcu.TABLE_SCHEMA = rc.CONSTRAINT_SCHEMA
            WHERE kcu.TABLE_SCHEMA = DATABASE()
              AND kcu.TABLE_NAME = :table
              AND kcu.REFERENCED_TABLE_NAME IS NOT NULL
            ORDER BY kcu.CONSTRAINT_NAME, kcu.ORDINAL_POSITION
        ");

        $stmt->execute(['table' => $table]);

        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return $results ?: null;
    }

    /**
     * Maps a given database field type to its corresponding PHP data type.
     *
     * @param string $fieldType The name of the database field type to be converted.
     * @return string|null The corresponding PHP data type or null if the mapping is not found.
     */
    public function fieldType(string $fieldType): ?string
    {
        // Remove size/precision info for clean mapping (e.g., "varchar(255)" -> "varchar")
        $cleanType = strtolower(trim($fieldType));
        $cleanType = preg_replace('/\([^)]*\)/', '', $cleanType);
        $cleanType = trim($cleanType ?? '');

        $types = [
            // Date/Time types
            'datetime' => 'datetime',
            'timestamp' => 'datetime',
            'date' => 'date',
            'time' => 'time',
            'year' => 'int',

            // Integer types
            'int' => 'int',
            'tinyint' => 'int',
            'smallint' => 'int',
            'mediumint' => 'int',
            'bigint' => 'int',
            'integer' => 'int',

            // Boolean types
            'bool' => 'bool',
            'boolean' => 'bool',
            'bit' => 'string',

            // Float/Decimal types
            'double' => 'float',
            'float' => 'float',
            'real' => 'float',
            'decimal' => 'float',
            'numeric' => 'float',

            // String types
            'char' => 'string',
            'varchar' => 'string',
            'text' => 'string',
            'tinytext' => 'string',
            'mediumtext' => 'string',
            'longtext' => 'string',
            'enum' => 'string',
            'set' => 'string',

            // Binary types (often used as strings in PHP)
            'binary' => 'string',
            'varbinary' => 'string',
            'blob' => 'string',
            'tinyblob' => 'string',
            'mediumblob' => 'string',
            'longblob' => 'string',

            // JSON type (MySQL 5.7+)
            'json' => 'array',

            // Geometry types (as strings for most use cases)
            'geometry' => 'string',
            'point' => 'string',
            'linestring' => 'string',
            'polygon' => 'string',
            'multipoint' => 'string',
            'multilinestring' => 'string',
            'multipolygon' => 'string',
            'geometrycollection' => 'string',
        ];

        return $types[$cleanType] ?? null;
    }
}
