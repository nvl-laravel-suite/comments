<?php

declare(strict_types=1);

namespace Nvl\Comments\Relations;

use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Connection;
use Illuminate\Database\Grammar;

/**
 * Internal byte-exact expression comparing wrapped text columns or one value.
 */
final readonly class TextColumnComparison implements Expression
{
    public function __construct(
        private string $left,
        private string $right,
        private string $driver,
        private bool $valueComparison = false,
        private bool $plain = false,
    ) {}

    /**
     * Plain equality between a comments text column and a host key, which the
     * `(commentable_type, commentable_id)` index can serve next to the byte-exact check.
     *
     * The host key takes the comments column's collation explicitly, so a key column with
     * another collation neither fails the comparison nor stops the index from being used.
     * Null when the driver or the column's collation offers no safe form.
     *
     * @param  string  $left  Grammar-wrapped qualified comments column
     * @param  string  $key  Grammar-wrapped qualified host key column
     */
    public static function indexable(Connection $connection, string $table, string $column, string $left, string $key): ?self
    {
        $driver = $connection->getDriverName();

        if ($driver === 'pgsql') {
            return new self($left, "CAST({$key} AS TEXT) COLLATE \"default\"", $driver, plain: true);
        }

        if ($driver === 'sqlite') {
            return new self($left, "CAST({$key} AS TEXT)", $driver, plain: true);
        }

        if (! in_array($driver, ['mysql', 'mariadb'], true)) {
            return null;
        }

        $collation = collect($connection->getSchemaBuilder()->getColumns($table))->firstWhere('name', $column)['collation'] ?? null;
        if (! is_string($collation) || preg_match('/\A([a-z0-9]+)_[a-z0-9_]+\z/D', $collation, $matches) !== 1) {
            return null;
        }

        return new self($left, "CONVERT({$key} USING {$matches[1]}) COLLATE {$collation}", $driver, plain: true);
    }

    /**
     * Compare one grammar-wrapped column with two bound copies of a value.
     */
    public static function value(string $column, string $driver): self
    {
        return new self($column, '?', $driver, true);
    }

    /**
     * Cast one grammar-wrapped identifier column to the driver's text type.
     */
    public static function text(string $column, string $driver): string
    {
        return match ($driver) {
            'pgsql', 'sqlite' => "CAST({$column} AS TEXT)",
            'sqlsrv' => "CAST({$column} AS NVARCHAR(255))",
            default => "CAST({$column} AS CHAR)",
        };
    }

    public function getValue(Grammar $grammar): string
    {
        if ($this->plain) {
            return "{$this->left} = {$this->right}";
        }

        if ($this->valueComparison) {
            return match ($this->driver) {
                'mysql', 'mariadb' => "BINARY {$this->left} = BINARY ? ".
                    "AND OCTET_LENGTH({$this->left}) = OCTET_LENGTH(?)",
                'pgsql' => "{$this->left} COLLATE \"C\" = CAST(? AS TEXT) COLLATE \"C\" ".
                    "AND OCTET_LENGTH({$this->left}) = OCTET_LENGTH(CAST(? AS TEXT))",
                'sqlsrv' => "{$this->left} COLLATE Latin1_General_100_BIN2 = ".
                    'CAST(? AS NVARCHAR(255)) COLLATE Latin1_General_100_BIN2 '.
                    "AND DATALENGTH({$this->left}) = DATALENGTH(CAST(? AS NVARCHAR(255)))",
                default => "{$this->left} = ? COLLATE BINARY ".
                    "AND LENGTH(CAST({$this->left} AS BLOB)) = LENGTH(CAST(? AS BLOB))",
            };
        }

        return match ($this->driver) {
            'mysql', 'mariadb' => "BINARY {$this->left} = BINARY {$this->right} ".
                "AND OCTET_LENGTH({$this->left}) = OCTET_LENGTH({$this->right})",
            'pgsql' => "{$this->left} COLLATE \"C\" = {$this->right} COLLATE \"C\" ".
                "AND OCTET_LENGTH({$this->left}) = OCTET_LENGTH({$this->right})",
            'sqlsrv' => "{$this->left} COLLATE Latin1_General_100_BIN2 = ".
                "{$this->right} COLLATE Latin1_General_100_BIN2 ".
                "AND DATALENGTH({$this->left}) = DATALENGTH({$this->right})",
            default => "{$this->left} = {$this->right} COLLATE BINARY ".
                "AND LENGTH(CAST({$this->left} AS BLOB)) = ".
                "LENGTH(CAST({$this->right} AS BLOB))",
        };
    }
}
