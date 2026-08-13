<?php

declare(strict_types=1);

namespace CoolMS\Rql;

interface RqlRepositoryInterface
{
    /**
     * Apply an RQL query to the repository's base query.
     * Returns paginated results with totalItems count.
     *
     * The implementation owns the translation entirely — this package has no
     * opinion on how the AST becomes a query, only on what comes back.
     */
    public function findByRql(RqlQuery $query, RqlContext $context): RqlResult;
}
