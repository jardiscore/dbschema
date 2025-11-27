# Jardis DbSchema
![Build Status](https://github.com/jardisCore/dbschema/actions/workflows/ci.yml/badge.svg)
[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)](LICENSE)
[![PHP Version](https://img.shields.io/badge/PHP-%3E%3D8.2-777BB4.svg)](https://www.php.net/)

A flexible package for analyzing and managing database schemas (PDO-based) with support for **MySQL/MariaDB**, **PostgreSQL**, and **SQLite**.

---

## Overview

This package provides a unified API to consistently retrieve schema information from different database systems. It includes specific implementations for common database drivers (MySQL/MariaDB, PostgreSQL, SQLite).

**Purpose:**

- Unified retrieval of schema information (tables, columns, indexes, foreign keys)
- Standardization of data structures across different database systems
- Field filtering with preserved order
- Type mapping from database types to PHP types
- High testability and interchangeability

---

## Requirements

- **PHP** >= 8.2
- **ext-pdo** (PDO extension)
- **jardispsr/dbschema** ^1.0 (PSR interface)
- **jardiscore/dotenv** ^1.0

---

## Supported Database Types

The package includes schema repository implementations for the following database types:

- **MySQL / MariaDB** → `JardisCore\DbSchema\repository\MySql`
- **PostgreSQL** → `JardisCore\DbSchema\repository\Postgres`
- **SQLite** → `JardisCore\DbSchema\repository\SqLite`

Each repository class implements `JardisPsr\DbSchema\DbSchemaInterface` and handles database-specific schema queries, returning results in consistent, normalized structures.

---

## Installation

Using Composer:

```bash
composer require jardiscore/dbschema
```

For local development with Docker:

```bash
git clone https://github.com/jardiscore/dbschema.git
cd dbschema
make start      # Start database containers
make install    # Install dependencies
```

---

## Configuration

DbSchema works with an existing PDO connection:

```php
use PDO;
use JardisCore\DbSchema\DbSchema;

$pdo = new PDO('mysql:host=localhost;dbname=test', 'user', 'password');
$schema = new DbSchema($pdo);
```

---

## Examples

### Basic Usage

```php
use JardisCore\DbSchema\DbSchema;
use PDO;

// Create PDO connection
$pdo = new PDO('mysql:host=localhost;dbname=mydb', 'user', 'pass');
$schema = new DbSchema($pdo);

// Retrieve all tables
$tables = $schema->tables();
print_r($tables);
// Returns: [['name' => 'users', 'type' => 'BASE TABLE'], ...]

// Retrieve all columns of a table
$columns = $schema->columns('users');
print_r($columns);
// Returns: [
//   ['name' => 'id', 'type' => 'int', 'nullable' => false, 'primary' => true, ...],
//   ['name' => 'name', 'type' => 'varchar', 'length' => 255, ...],
//   ...
// ]

// Retrieve specific columns (order preserved!)
$specificColumns = $schema->columns('users', ['id', 'name', 'email']);
print_r($specificColumns);
// Returns columns in the exact order specified: id, name, email

// Retrieve indexes of a table
$indexes = $schema->indexes('users');
print_r($indexes);

// Retrieve foreign keys of a table
$foreignKeys = $schema->foreignKeys('orders');
print_r($foreignKeys);

// Get PHP type mapping for a database type
$phpType = $schema->fieldType('VARCHAR');
echo $phpType; // "string"

$phpType = $schema->fieldType('int');
echo $phpType; // "int"
```

### Column Information Structure

Each column returned by `columns()` includes:

- **name**: Column name
- **type**: Database data type (e.g., 'varchar', 'int', 'datetime')
- **length**: Maximum length for character types
- **precision**: Numeric precision
- **scale**: Numeric scale
- **nullable**: Boolean indicating if NULL is allowed
- **default**: Default value
- **primary**: Boolean indicating if it's a primary key
- **auto_increment**: Boolean indicating auto-increment status

---

## Development

### Docker Setup

The project uses Docker for local development with the following services:

- **MySQL** (mysql_test)
- **MariaDB** (mariadb_test)
- **PostgreSQL** (postgres_test)
- **PHP CLI** (phpcli) - PHP 8.3 with Xdebug

### Available Make Commands

**Docker Management:**
```bash
make start           # Start all database containers
make stop            # Stop and remove all containers
make restart         # Restart all containers
make status          # Show container status
make clean           # Stop containers and clean up volumes
make remove          # Remove all Docker resources
```

**Composer:**
```bash
make install         # Run composer install
make update          # Run composer update
make autoload        # Dump autoload
```

**Quality Assurance:**
```bash
make phpunit                  # Run all tests
make phpunit-coverage         # Run tests with text coverage
make phpunit-coverage-html    # Run tests with HTML coverage
make phpunit-reports          # Run tests with XML reports
make phpstan                  # Run PHPStan (level 8)
make phpcs                    # Run code style checks
```

**Development:**
```bash
make shell           # Open shell in PHP container
make logs            # Show container logs
```

---

## Testing

The project includes comprehensive PHPUnit tests for all database types:

- **Unit tests**: Testing individual components
- **Integration tests**: Testing against real databases (MySQL, MariaDB, PostgreSQL, SQLite)
- **Coverage**: Aiming for 100% code coverage

Run tests:
```bash
make start           # Start databases first
make phpunit         # Run all tests
```

---

## Code Quality

The repository maintains high code quality standards:

* **PHPStan** - Static analysis at level 8
* **PHPCS** - PSR-12 coding standards
* **PHPUnit** - Comprehensive test coverage
* **Pre-commit hooks** - Automated quality checks

### CI/CD Pipeline

GitHub Actions workflow includes:

1. Dependency installation
2. Code style checks (`make phpcs`)
3. Static analysis (`make phpstan`)
4. Test execution with coverage (`make phpunit-coverage`)

---

## Architecture

### DbSchemaInterface

All repository classes implement `JardisPsr\DbSchema\DbSchemaInterface`:

```php
interface DbSchemaInterface
{
    public function tables(): ?array;
    public function columns(string $table, ?array $fields = null): ?array;
    public function indexes(string $table): ?array;
    public function foreignKeys(string $table): ?array;
    public function fieldType(string $fieldType): ?string;
}
```

### Repository Pattern

The package follows the repository pattern with database-specific implementations that handle:

- Database-specific SQL queries
- Result normalization (boolean values, numeric types)
- Consistent data structures across all database types

---

## License

This project is licensed under the **MIT License** – see `LICENSE` in the repository.

---

## Support

- **Issues**: https://github.com/jardiscore/dbschema/issues
- **Email**: jardisCore@headgent.dev

---

Enjoy and succeed with **DbSchema**!
