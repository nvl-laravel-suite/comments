<?php

declare(strict_types=1);

namespace Nvl\Comments\Relations;

use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Connection;
use Illuminate\Database\Grammar;
use LogicException;

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
     * PostgreSQL applies its default collation to the cast host key.
     * Null when the driver offers no supported indexable form.
     *
     * @param  string  $left  Grammar-wrapped qualified comments column
     * @param  string  $key  Grammar-wrapped qualified host key column
     */
    public static function indexable(Connection $connection, string $left, string $key): ?self
    {
        $driver = $connection->getDriverName();

        if ($driver === 'pgsql') {
            return new self($left, "CAST({$key} AS TEXT) COLLATE \"default\"", $driver, plain: true);
        }

        if ($driver === 'sqlite') {
            return new self($left, "CAST({$key} AS TEXT)", $driver, plain: true);
        }

        return null;
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
            default => throw new LogicException('Comment text comparisons require SQLite or PostgreSQL.'),
        };
    }

    public function getValue(Grammar $grammar): string
    {
        if ($this->plain) {
            return "{$this->left} = {$this->right}";
        }

        if ($this->valueComparison) {
            return match ($this->driver) {
                'pgsql' => "{$this->left} COLLATE \"C\" = CAST(? AS TEXT) COLLATE \"C\" ".
                    "AND OCTET_LENGTH({$this->left}) = OCTET_LENGTH(CAST(? AS TEXT))",
                'sqlite' => "{$this->left} = ? COLLATE BINARY ".
                    "AND LENGTH(CAST({$this->left} AS BLOB)) = LENGTH(CAST(? AS BLOB))",
                default => throw new LogicException('Comment text comparisons require SQLite or PostgreSQL.'),
            };
        }

        return match ($this->driver) {
            'pgsql' => "{$this->left} COLLATE \"C\" = {$this->right} COLLATE \"C\" ".
                "AND OCTET_LENGTH({$this->left}) = OCTET_LENGTH({$this->right})",
            'sqlite' => "{$this->left} = {$this->right} COLLATE BINARY ".
                "AND LENGTH(CAST({$this->left} AS BLOB)) = ".
                "LENGTH(CAST({$this->right} AS BLOB))",
            default => throw new LogicException('Comment text comparisons require SQLite or PostgreSQL.'),
        };
    }
}
