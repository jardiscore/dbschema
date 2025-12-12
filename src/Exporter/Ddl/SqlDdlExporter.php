<?php

declare(strict_types=1);

namespace JardisCore\DbSchema\Exporter\Ddl;

use JardisPsr\DbSchema\DbSchemaInterface;

/**
 * Exports SQL DDL statements from database schema metadata.
 *
 * This exporter creates complete SQL scripts for recreating database schemas,
 * including proper dependency ordering for foreign key constraints.
 */
readonly class SqlDdlExporter
{
    public function __construct(
        private DbSchemaInterface $schema,
        private DdlDialectInterface $dialect
    ) {
    }

    /**
     * Generates a complete SQL DDL script for the specified tables.
     *
     * @param array<int, string> $tables List of table names to include in the export
     * @return string Complete SQL script with DROP, CREATE, INDEX, and FOREIGN KEY statements
     */
    public function generate(array $tables): string
    {
        $output = [];

        // 1. Header with metadata
        $output[] = $this->generateHeader($tables);

        // 2. Begin transaction
        $output[] = $this->dialect->beginTransaction();
        $output[] = '';

        // 3. Collect table metadata
        $tableData = $this->collectTableMetadata($tables);

        // 4. Sort tables by dependency (tables without foreign keys first)
        $sortedTables = $this->sortTablesByDependency($tableData);

        // 5. DROP TABLE statements (reverse order)
        $output[] = '-- Drop existing tables';
        foreach (array_reverse($sortedTables) as $tableName) {
            $output[] = $this->dialect->dropTableStatement($tableName);
        }
        $output[] = '';

        // 6. CREATE TABLE statements
        $output[] = '-- Create tables';
        foreach ($sortedTables as $tableName) {
            $data = $tableData[$tableName];
            $primaryKeys = $this->extractPrimaryKeys($data['columns']);
            $output[] = $this->dialect->createTableStatement($tableName, $data['columns'], $primaryKeys);
            $output[] = '';
        }

        // 7. CREATE INDEX statements
        $output[] = '-- Create indexes';
        foreach ($sortedTables as $tableName) {
            $data = $tableData[$tableName];
            if (!empty($data['indexes'])) {
                $indexStatements = $this->generateIndexStatements($tableName, $data['indexes']);
                if (!empty($indexStatements)) {
                    $output[] = implode("\n", $indexStatements);
                    $output[] = '';
                }
            }
        }

        // 8. ALTER TABLE ... ADD FOREIGN KEY statements
        $output[] = '-- Add foreign key constraints';
        foreach ($sortedTables as $tableName) {
            $data = $tableData[$tableName];
            if (!empty($data['foreignKeys'])) {
                foreach ($data['foreignKeys'] as $fk) {
                    $output[] = $this->dialect->createForeignKeyStatement($tableName, $fk);
                }
                $output[] = '';
            }
        }

        // 9. Commit transaction
        $output[] = $this->dialect->commitTransaction();

        return implode("\n", $output);
    }

    /**
     * Generates a header comment with metadata about the export.
     *
     * @param array<int, string> $tables
     * @return string
     */
    private function generateHeader(array $tables): string
    {
        $timestamp = date('Y-m-d H:i:s');
        $tableCount = count($tables);
        $tableList = implode(', ', $tables);

        return <<<HEADER
-- ============================================================================
-- SQL DDL Export
-- Generated: {$timestamp}
-- Exporter: JardisCore\DbSchema\Exporter\SqlDdlExporter
-- Tables: {$tableCount} ({$tableList})
-- ============================================================================

HEADER;
    }

    /**
     * Collects all metadata for the specified tables.
     *
     * @param array<int, string> $tables
     * @return array<string, array{
     *     columns: array<int, array<string, mixed>>,
     *     indexes: array<int, array<string, mixed>>|null,
     *     foreignKeys: array<int, array<string, mixed>>|null
     * }>
     */
    private function collectTableMetadata(array $tables): array
    {
        $metadata = [];

        foreach ($tables as $table) {
            $metadata[$table] = [
                'columns' => $this->schema->columns($table) ?? [],
                'indexes' => $this->schema->indexes($table),
                'foreignKeys' => $this->schema->foreignKeys($table),
            ];
        }

        return $metadata;
    }

    /**
     * Sorts tables by foreign key dependencies using topological sort.
     *
     * @param array<string, array{
     *     columns: array<int, array<string, mixed>>,
     *     indexes: array<int, array<string, mixed>>|null,
     *     foreignKeys: array<int, array<string, mixed>>|null
     * }> $tableData
     * @return array<int, string> Sorted table names (dependencies first)
     */
    private function sortTablesByDependency(array $tableData): array
    {
        $resolver = new DependencyResolver();
        return $resolver->sortByDependency($tableData);
    }

    /**
     * Extracts primary key column names from column metadata.
     *
     * @param array<int, array<string, mixed>> $columns
     * @return array<int, string>
     */
    private function extractPrimaryKeys(array $columns): array
    {
        $primaryKeys = [];

        foreach ($columns as $column) {
            if ($column['primary'] === true) {
                $primaryKeys[] = $column['name'];
            }
        }

        return $primaryKeys;
    }

    /**
     * Generates CREATE INDEX statements for a table, filtering out primary key indexes.
     *
     * @param string $tableName
     * @param array<int, array<string, mixed>> $indexes
     * @return array<int, string>
     */
    private function generateIndexStatements(string $tableName, array $indexes): array
    {
        $statements = [];
        $processedIndexes = [];

        foreach ($indexes as $index) {
            $indexName = $index['name'];

            // Skip if already processed (multi-column indexes appear multiple times)
            if (in_array($indexName, $processedIndexes)) {
                continue;
            }

            $statement = $this->dialect->createIndexStatement($tableName, $index);
            if (!empty($statement)) {
                $statements[] = $statement;
                $processedIndexes[] = $indexName;
            }
        }

        return $statements;
    }
}
