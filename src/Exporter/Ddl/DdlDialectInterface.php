<?php

declare(strict_types=1);

namespace JardisCore\DbSchema\Exporter\Ddl;

/**
 * Interface for database dialect-specific DDL statement generation.
 *
 * Implementations provide database-specific SQL DDL syntax for creating tables,
 * indexes, and foreign key constraints. Each database dialect (MySQL, PostgreSQL, SQLite)
 * has its own syntax variations that are handled by implementing classes.
 */
interface DdlDialectInterface
{
    /**
     * Maps a database type to dialect-specific SQL type definition.
     *
     * @param string $dbType The base database type (e.g., 'varchar', 'int')
     * @param int|null $length Character maximum length for string types
     * @param int|null $precision Numeric precision
     * @param int|null $scale Numeric scale
     * @return string The complete SQL type definition (e.g., 'VARCHAR(255)', 'INT(11)')
     */
    public function typeMapping(string $dbType, ?int $length, ?int $precision, ?int $scale): string;

    /**
     * Generates a CREATE TABLE statement.
     *
     * @param string $tableName The name of the table
     * @param array<int, array<string, mixed>> $columns Column definitions from DbSchemaInterface::columns()
     * @param array<int, string> $primaryKeys List of primary key column names
     * @return string Complete CREATE TABLE SQL statement
     */
    public function createTableStatement(string $tableName, array $columns, array $primaryKeys): string;

    /**
     * Generates a CREATE INDEX statement.
     *
     * @param string $tableName The name of the table
     * @param array<string, mixed> $index Index definition from DbSchemaInterface::indexes()
     * @return string Complete CREATE INDEX SQL statement
     */
    public function createIndexStatement(string $tableName, array $index): string;

    /**
     * Generates an ALTER TABLE ... ADD FOREIGN KEY statement.
     *
     * @param string $tableName The name of the table
     * @param array<string, mixed> $foreignKey Foreign key definition from DbSchemaInterface::foreignKeys()
     * @return string Complete ALTER TABLE SQL statement
     */
    public function createForeignKeyStatement(string $tableName, array $foreignKey): string;

    /**
     * Generates a DROP TABLE IF EXISTS statement.
     *
     * @param string $tableName The name of the table to drop
     * @return string Complete DROP TABLE SQL statement
     */
    public function dropTableStatement(string $tableName): string;

    /**
     * Returns the SQL statement to begin a transaction.
     *
     * @return string Transaction start statement (e.g., 'BEGIN;' or 'START TRANSACTION;')
     */
    public function beginTransaction(): string;

    /**
     * Returns the SQL statement to commit a transaction.
     *
     * @return string Transaction commit statement
     */
    public function commitTransaction(): string;
}
