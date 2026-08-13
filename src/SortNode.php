<?php

declare(strict_types=1);

namespace CoolMS\Rql;

/**
 * Sort directive: field + direction.
 *
 * Examples:
 *   sort=createdAt    → SortNode("createdAt", Asc)
 *   sort=-createdAt   → SortNode("createdAt", Desc)
 */
final readonly class SortNode
{
    public function __construct(
        public string $field,
        public SortDirection $direction,
    ) {
    }
}
