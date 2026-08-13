<?php

declare(strict_types=1);

namespace CoolMS\Rql;

/**
 * Boolean AND group of child nodes (Persvr RQL `and(...)`).
 *
 * A child may itself be a {@see FilterNode}, an {@see OrNode}, or a nested
 * AndNode -- the filter AST is arbitrarily nestable. The top-level
 * {@see RqlQuery::$filters} is already an implicit AND of its entries, so an
 * explicit AndNode is only needed when an AND group must sit INSIDE another
 * group (e.g. `or(and(eq(a,1),eq(b,2)), eq(c,3))`).
 */
final readonly class AndNode
{
    /** @param array<FilterNode|OrNode|AndNode> $nodes */
    public function __construct(
        public array $nodes,
    ) {
    }
}
