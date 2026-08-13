<?php

declare(strict_types=1);

namespace CoolMS\Rql;

/**
 * Paginated query result.
 */
final readonly class RqlResult
{
    /**
     * @param array<object> $items
     */
    public function __construct(
        public array $items,
        public int $totalItems,
        public int $page,
        public int $limit,
    ) {
    }

    #[\NoDiscard()]
    public function totalPages(): int
    {
        return $this->limit > 0
            ? (int) ceil($this->totalItems / $this->limit)
            : 1;
    }

    #[\NoDiscard()]
    public function hasNextPage(): bool
    {
        return $this->page < $this->totalPages();
    }
}
