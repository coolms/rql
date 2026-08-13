<?php

declare(strict_types=1);

namespace CoolMS\Rql;

/**
 * OR group of filter conditions.
 *
 * Created by the classic DSL when a single filter param contains a | separator:
 *   filter=title cn "a"|description cn "b"
 *   → OrNode([FilterNode("title", Cn, "a"), FilterNode("description", Cn, "b")])
 *
 * Or by the Persvr grammar's `or(...)`. A child may itself be a FilterNode, an
 * {@see AndNode}, or a nested OrNode -- the filter AST is arbitrarily nestable.
 */
final readonly class OrNode
{
    /**
     * @param array<FilterNode|OrNode|AndNode> $nodes
     */
    public function __construct(
        public array $nodes,
    ) {
    }
}
