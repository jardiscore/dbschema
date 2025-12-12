<?php

declare(strict_types=1);

namespace JardisCore\DbSchema\Exporter\Ddl;

use RuntimeException;

/**
 * Resolves table dependencies using topological sorting.
 *
 * Analyzes foreign key relationships to determine the correct order for
 * creating tables, ensuring that referenced tables are created before
 * tables that reference them.
 */
class DependencyResolver
{
    /**
     * Performs topological sort on tables based on foreign key dependencies.
     *
     * @param array<string, array{
     *     columns: array<int, array<string, mixed>>,
     *     indexes: array<int, array<string, mixed>>|null,
     *     foreignKeys: array<int, array<string, mixed>>|null
     * }> $tableData
     * @return array<int, string> Sorted table names (dependencies first)
     * @throws RuntimeException If circular dependencies are detected
     */
    public function sortByDependency(array $tableData): array
    {
        // Build dependency graph
        $graph = $this->buildDependencyGraph($tableData);

        // Perform topological sort using Kahn's algorithm
        return $this->topologicalSort($graph, array_keys($tableData));
    }

    /**
     * Builds a dependency graph from foreign key relationships.
     *
     * @param array<string, array{
     *     columns: array<int, array<string, mixed>>,
     *     indexes: array<int, array<string, mixed>>|null,
     *     foreignKeys: array<int, array<string, mixed>>|null
     * }> $tableData
     * @return array<string, array<int, string>> Map of table => [dependent tables]
     */
    private function buildDependencyGraph(array $tableData): array
    {
        $graph = [];

        // Initialize all tables in graph
        foreach (array_keys($tableData) as $table) {
            $graph[$table] = [];
        }

        // Build edges: if table A references table B, then B -> A (B must come before A)
        foreach ($tableData as $tableName => $data) {
            if (empty($data['foreignKeys'])) {
                continue;
            }

            foreach ($data['foreignKeys'] as $fk) {
                $referencedTable = $fk['refContainer'];

                // Only add dependency if referenced table is in our export set
                if (isset($graph[$referencedTable])) {
                    // Self-references are handled separately (can be created with deferred FK check)
                    if ($referencedTable !== $tableName) {
                        $graph[$referencedTable][] = $tableName;
                    }
                }
            }
        }

        return $graph;
    }

    /**
     * Performs topological sort using Kahn's algorithm.
     *
     * @param array<string, array<int, string>> $graph Dependency graph
     * @param array<int, string> $allTables All table names
     * @return array<int, string> Sorted table names
     * @throws RuntimeException If circular dependencies detected
     */
    private function topologicalSort(array $graph, array $allTables): array
    {
        $sorted = [];
        $inDegree = [];

        // Calculate in-degree (number of dependencies) for each table
        foreach ($allTables as $table) {
            $inDegree[$table] = 0;
        }

        foreach ($graph as $dependencies) {
            foreach ($dependencies as $dependentTable) {
                $inDegree[$dependentTable]++;
            }
        }

        // Queue of tables with no dependencies
        $queue = [];
        foreach ($inDegree as $table => $degree) {
            if ($degree === 0) {
                $queue[] = $table;
            }
        }

        // Process queue
        while (!empty($queue)) {
            // Sort queue alphabetically for deterministic output
            sort($queue);
            $current = array_shift($queue);
            $sorted[] = $current;

            // Reduce in-degree for dependent tables
            foreach ($graph[$current] as $dependentTable) {
                $inDegree[$dependentTable]--;

                if ($inDegree[$dependentTable] === 0) {
                    $queue[] = $dependentTable;
                }
            }
        }

        // Check for circular dependencies
        if (count($sorted) !== count($allTables)) {
            $remaining = array_diff($allTables, $sorted);
            throw new RuntimeException(
                'Circular dependency detected in tables: ' . implode(', ', $remaining)
            );
        }

        return $sorted;
    }
}
