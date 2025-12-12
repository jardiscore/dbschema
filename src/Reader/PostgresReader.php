<?php

declare(strict_types=1);

namespace JardisCore\DbSchema\Reader;

use JardisPsr\DbSchema\DbSchemaInterface;
use PDO;

/**
 * PostgreSQL database schema reader.
 *
 * This class provides functionality for reading and analyzing the schema of a PostgreSQL
 * database. It implements the DbSchemaInterface and allows querying information about
 * tables, columns, indexes, and foreign key constraints within the database.
 */
class PostgresReader implements DbSchemaInterface
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function tables(): ?array
    {
        $stmt = $this->pdo->prepare("
            SELECT 
                t.table_name AS name,
                t.table_type AS type
            FROM information_schema.tables t
            LEFT JOIN pg_class c ON c.relname = t.table_name
            WHERE t.table_schema = 'public'
              AND t.table_type = 'BASE TABLE'
            ORDER BY t.table_name
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
        $sql = <<<SQL
    SELECT
      cols.column_name                         AS name,
      cols.data_type                           AS type,
      cols.udt_name                            AS udt_name,
      cols.character_maximum_length            AS length,
      cols.numeric_precision                   AS "precision",
      cols.numeric_scale                       AS scale,
      (cols.is_nullable = 'YES')               AS nullable,
      cols.column_default                      AS "default",
      COALESCE(pk.is_primary, false)           AS "primary",
      (cols.column_default LIKE 'nextval(%' OR
       cols.is_identity = 'YES')               AS auto_increment
    FROM information_schema.columns cols
    LEFT JOIN (
      SELECT
        kcu.table_schema,
        kcu.table_name,
        kcu.column_name,
        TRUE AS is_primary
      FROM information_schema.table_constraints tc
      JOIN information_schema.key_column_usage kcu
        ON  tc.constraint_name = kcu.constraint_name
        AND tc.table_schema    = kcu.table_schema
        AND tc.table_name      = kcu.table_name
      WHERE tc.constraint_type = 'PRIMARY KEY'
    ) pk
      ON  pk.table_schema = cols.table_schema
      AND pk.table_name   = cols.table_name
      AND pk.column_name  = cols.column_name
    WHERE cols.table_schema = :schema
      AND cols.table_name   = :table
    ORDER BY cols.ordinal_position;
    SQL;

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['schema' => 'public', 'table' => $table]);

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
     * Normalizes the properties of a database column for consistent data handling.
     *
     * @param array<string, mixed> $column An associative array representing column attributes,
     *                      containing keys such as 'nullable', 'primary',
     *                      'auto_increment', 'length', 'precision', and 'scale'.
     * @return array<string, mixed> The normalized column attributes with boolean and numeric values properly converted.
     */
    private function normalizeColumnTypes(array $column): array
    {
        // PostgresSQL already returns proper boolean types, but ensure consistency
        $column['nullable'] = (bool) $column['nullable'];
        $column['primary'] = (bool) $column['primary'];
        $column['auto_increment'] = (bool) $column['auto_increment'];

        // Normalize numeric values
        $column['length'] = $this->toIntOrNull($column['length']);
        $column['precision'] = $this->toIntOrNull($column['precision']);
        $column['scale'] = $this->toIntOrNull($column['scale']);

        // Handle PostgreSQL ENUMs (USER-DEFINED types)
        if ($column['type'] === 'USER-DEFINED' && isset($column['udt_name'])) {
            $column['type'] = 'enum';
            $column['enumValues'] = $this->fetchEnumValues($column['udt_name']);
        }

        // Remove temporary udt_name field
        unset($column['udt_name']);

        return $column;
    }

    /**
     * Fetch enum values for a PostgreSQL enum type.
     *
     * @param string $enumTypeName The name of the enum type
     * @return array<int, string>|null Array of enum values or null on error
     */
    private function fetchEnumValues(string $enumTypeName): ?array
    {
        $stmt = $this->pdo->prepare("
            SELECT e.enumlabel
            FROM pg_type t
            JOIN pg_enum e ON t.oid = e.enumtypid
            WHERE t.typname = :typname
            ORDER BY e.enumsortorder
        ");

        $stmt->execute(['typname' => $enumTypeName]);

        $values = $stmt->fetchAll(PDO::FETCH_COLUMN);

        return $values ?: null;
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

    public function foreignKeys(string $table): ?array
    {
        $stmt = $this->pdo->prepare("
            SELECT 
                tc.table_name AS \"container\",
                tc.constraint_name AS \"constraintName\",
                kcu.column_name AS \"constraintCol\",
                ccu.table_name AS \"refContainer\",
                ccu.column_name AS \"refColumn\",
                ccu.table_schema AS \"refSchema\",
                rc.update_rule AS \"onUpdate\",
                rc.delete_rule AS \"onDelete\",
                kcu.ordinal_position AS \"sequence\"
            FROM information_schema.table_constraints AS tc
            JOIN information_schema.key_column_usage AS kcu
                ON tc.constraint_name = kcu.constraint_name
                AND tc.table_schema = kcu.table_schema
            JOIN information_schema.constraint_column_usage AS ccu
                ON ccu.constraint_name = tc.constraint_name
                AND ccu.table_schema = tc.table_schema
            JOIN information_schema.referential_constraints AS rc
                ON tc.constraint_name = rc.constraint_name
                AND tc.table_schema = rc.constraint_schema
            WHERE tc.constraint_type = 'FOREIGN KEY'
              AND tc.table_schema = 'public'
              AND tc.table_name = :table
            ORDER BY tc.constraint_name, kcu.ordinal_position
        ");

        $stmt->execute(['table' => $table]);

        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return $results ?: null;
    }

    public function indexes(string $table): ?array
    {
        $stmt = $this->pdo->prepare("
            SELECT 
                i.indexname AS \"name\",
                a.attname AS \"column_name\",
                ix.indisunique AS \"is_unique\",
                am.amname AS \"type\",
                a.attnum AS \"sequence\",
                NULL AS \"sub_part\",
                NULL AS \"nullable\",
                CASE 
                    WHEN ix.indisprimary THEN 'primary'
                    WHEN ix.indisunique THEN 'unique'
                    ELSE 'index'
                END as \"index_type\"
            FROM pg_indexes i
            JOIN pg_class t ON t.relname = i.tablename
            JOIN pg_class ic ON ic.relname = i.indexname
            JOIN pg_index ix ON ix.indexrelid = ic.oid
            JOIN pg_attribute a ON a.attrelid = t.oid AND a.attnum = ANY(ix.indkey)
            JOIN pg_am am ON am.oid = ic.relam
            WHERE i.schemaname = 'public'
              AND i.tablename = :table
            ORDER BY i.indexname, a.attnum
        ");

        $stmt->execute(['table' => $table]);

        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return $results ?: null;
    }


    /**
     * Maps a given PostgresSQL field type to its corresponding PHP data type.
     *
     * @param string $fieldType The name of the database field type to be converted.
     * @return string|null The corresponding PHP data type or null if the mapping is not found.
     */
    public function fieldType(string $fieldType): ?string
    {
        // Remove size/precision info for clean mapping
        $cleanType = strtolower(trim($fieldType));
        $cleanType = preg_replace('/\([^)]*\)/', '', $cleanType);
        $cleanType = trim($cleanType ?? '');

        $types = [
            // Date/Time types
            'timestamp' => 'datetime',
            'timestamptz' => 'datetime',
            'timestamp with time zone' => 'datetime',
            'timestamp without time zone' => 'datetime',
            'date' => 'date',
            'time' => 'time',
            'timetz' => 'time',
            'time with time zone' => 'time',
            'time without time zone' => 'time',
            'interval' => 'string',

            // Integer types
            'integer' => 'int',
            'int' => 'int',
            'int4' => 'int',
            'smallint' => 'int',
            'int2' => 'int',
            'bigint' => 'int',
            'int8' => 'int',
            'serial' => 'int',
            'bigserial' => 'int',
            'smallserial' => 'int',

            // Boolean types
            'boolean' => 'bool',
            'bool' => 'bool',

            // Float/Decimal types
            'real' => 'float',
            'float4' => 'float',
            'double precision' => 'float',
            'float8' => 'float',
            'numeric' => 'float',
            'decimal' => 'float',
            'money' => 'string',

            // String/Text types
            'character varying' => 'string',
            'varchar' => 'string',
            'character' => 'string',
            'char' => 'string',
            'text' => 'string',
            'name' => 'string',

            // Binary types
            'bytea' => 'string',

            // JSON types
            'json' => 'array',
            'jsonb' => 'array',

            // Array types
            'array' => 'string',

            // UUID type
            'uuid' => 'string',

            // Network types
            'inet' => 'string',
            'cidr' => 'string',
            'macaddr' => 'string',
            'macaddr8' => 'string',

            // Geometric types
            'point' => 'string',
            'line' => 'string',
            'lseg' => 'string',
            'box' => 'string',
            'path' => 'string',
            'polygon' => 'string',
            'circle' => 'string',

            // Range types
            'int4range' => 'string',
            'int8range' => 'string',
            'numrange' => 'string',
            'tsrange' => 'string',
            'tstzrange' => 'string',
            'daterange' => 'string',

            // Other types
            'xml' => 'string',
            'tsvector' => 'string',
            'tsquery' => 'string',
        ];

        return $types[$cleanType] ?? null;
    }
}
