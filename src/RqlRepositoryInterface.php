<?php

declare(strict_types=1);

namespace CoolMS\Rql;

interface RqlRepositoryInterface
{
    /**
     * Apply an RQL query to the repository's base QueryBuilder.
     * Returns paginated results with totalItems count.
     */
    public function findByRql(RqlQuery $query, RqlContext $context): RqlResult;
}
