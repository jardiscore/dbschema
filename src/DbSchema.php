<?php

declare(strict_types=1);

namespace JardisCore\DbSchema;

use JardisCore\DbSchema\repository\MySql;
use JardisCore\DbSchema\repository\Postgres;
use JardisCore\DbSchema\repository\SqLite;
use InvalidArgumentException;
use JardisPsr\DbSchema\DbSchemaInterface;
use PDO;

/**
 * Handles database schema management using various supported database drivers.
 * This class is responsible for delegating schema-related operations such as retrieving
 * tables, columns, indexes, foreign keys, and field types to the appropriate database-specific
 * implementation.
 */
class DbSchema implements DbSchemaInterface
{
    private PDO $pdo;
    private ?DbSchemaInterface $schemaRepository = null;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    // Delegate all DbSchemaInterface methods to the appropriate repository
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
     * Factory method to create the appropriate schema repository based on the connection type
     */
    private function schemaRepository(): DbSchemaInterface
    {
        if ($this->schemaRepository) {
            return $this->schemaRepository;
        }

        $driver = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

        $this->schemaRepository = match (strtolower($driver)) {
            'mysql' => new MySql($this->pdo),
            'pgsql' => new Postgres($this->pdo),
            'sqlite' => new SqLite($this->pdo),
            default => throw new InvalidArgumentException(
                "Unsupported database driver: {$driver}. Supported: mysql, pgsql, sqlite"
            )
        };

        return $this->schemaRepository;
    }
}
