<?php

declare(strict_types=1);

namespace CoolMS\Rql;

/**
 * Single filter condition: field op value.
 *
 * Examples:
 *   title cn "hello"       → FilterNode("title", Cn, "hello")
 *   price gt 100           → FilterNode("price", Gt, 100)
 *   extras.status eq "ok"  → FilterNode("extras.status", Eq, "ok")
 *   parentId null          → FilterNode("parentId", Null, null)
 */
final readonly class FilterNode
{
    public function __construct(
        public string $field,
        public FilterOp $op,
        public mixed $value,
    ) {
    }

    public function isJsonField(): bool
    {
        return str_starts_with($this->field, 'extras.');
    }

    public function jsonKey(): string
    {
        return substr($this->field, 7); // strip 'extras.'
    }
}
