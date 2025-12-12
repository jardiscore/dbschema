<?php

declare(strict_types=1);

namespace JardisCore\DbSchema;

use JardisCore\DbSchema\Reader\MySqlReader;
use JardisCore\DbSchema\Reader\PostgresReader;
use JardisCore\DbSchema\Reader\SqLiteReader;
use InvalidArgumentException;
use JardisPsr\DbSchema\DbSchemaInterface;
use PDO;

/**
 * Handles database schema management using various supported database drivers.
 * This class is responsible for delegating schema-related operations such as retrieving
 * tables, columns, indexes, foreign keys, and field types to the appropriate database-specific
 * reader implementation.
 */
class DbSchema implements DbSchemaInterface
{
    private PDO $pdo;
    private ?DbSchemaInterface $schemaReader = null;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    // Delegate all DbSchemaInterface methods to the appropriate reader
    public function tables(): ?array
    {
        return $this->schemaRepository()->tables();
    }

    public function columns(string $container, ?array $fields = null): ?array
    {
        return $this->schemaRepository()->columns($container, $fields);
    }

    public function indexes(string $container): ?array
    {
        return $this->schemaRepository()->indexes($container);
    }

    public function foreignKeys(string $container): ?array
    {
        return $this->schemaRepository()->foreignKeys($container);
    }

    public function fieldType(string $fieldType): ?string
    {
        return $this->schemaRepository()->fieldType($fieldType);
    }

    /**
     * Returns the PDO instance for direct database access.
     *
     * @return PDO
     */
    public function getPdo(): PDO
    {
        return $this->pdo;
    }

    /**
     * Factory method to create the appropriate schema reader based on the connection type
     */
    public function schemaRepository(): DbSchemaInterface
    {
        if ($this->schemaReader) {
            return $this->schemaReader;
        }

        $driver = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

        $this->schemaReader = match (strtolower($driver)) {
            'mysql' => new MySqlReader($this->pdo),
            'pgsql' => new PostgresReader($this->pdo),
            'sqlite' => new SqLiteReader($this->pdo),
            default => throw new InvalidArgumentException(
                "Unsupported database driver: {$driver}. Supported: mysql, pgsql, sqlite"
            )
        };

        return $this->schemaReader;
    }
}
