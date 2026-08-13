<?php

declare(strict_types=1);

namespace CoolMS\Rql;

/**
 * Immutable value object representing a parsed RQL query.
 *
 * Built by either front-end parser (RqlParser = classic DSL,
 * RqlExpressionParser = Persvr function-call grammar) and handed to a
 * translator the host supplies; this package ships none, so it assumes no
 * ORM and no query language. The top-level $filters array is an implicit AND;
 * entries may be leaf FilterNodes or (nestable) OrNode/AndNode groups.
 */
final readonly class RqlQuery
{
    public const int DEFAULT_LIMIT = 20;

    public const int MAX_LIMIT = 200;

    /**
     * @param array<FilterNode|OrNode|AndNode> $filters
     * @param SortNode[]                       $sort
     */
    public function __construct(
        public array $filters = [],
        public array $sort = [],
        public int $page = 1,
        public int $limit = self::DEFAULT_LIMIT,
    ) {
    }

    #[\NoDiscard()]
    public function isEmpty(): bool
    {
        return [] === $this->filters && [] === $this->sort;
    }

    #[\NoDiscard()]
    public function offset(): int
    {
        return ($this->page - 1) * $this->limit;
    }
}
