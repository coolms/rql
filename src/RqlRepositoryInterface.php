<?php

declare(strict_types=1);

namespace CoolMS\Rql;

interface RqlRepositoryInterface
{
    /**
     * Apply an RQL query to the repository's base query.
     * Returns paginated results with totalItems count.
     *
     * The implementation owns the translation; this package only fixes the
     * shape of what comes back.
     */
    public function findByRql(RqlQuery $query, RqlContext $context): RqlResult;
}
