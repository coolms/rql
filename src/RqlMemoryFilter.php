<?php

declare(strict_types=1);

namespace CoolMS\Rql;

/**
 * Applies an {@see RqlQuery} (filters + sort) to an in-memory row set -- the
 * counterpart to a database translator, for file/static/collection sources
 * that aren't backed by a query at all.
 *
 * A "row" is an associative array; a {@see FilterNode}'s `field` matches a row
 * key directly (no `extras.` dot-navigation -- flatten before calling if a
 * nested value must be filterable). Boolean groups nest arbitrarily
 * ({@see OrNode}/{@see AndNode}). Does NOT paginate -- callers slice the returned
 * array using `array_slice($result, $query->offset(), $query->limit)`.
 */
final readonly class RqlMemoryFilter
{
    /**
     * @param array<array<string, mixed>> $rows
     *
     * @return array<array<string, mixed>>
     */
    #[\NoDiscard('apply() returns the filtered rows; it does not filter in place.')]
    public function apply(array $rows, RqlQuery $query): array
    {
        // ONE pass, all filters, short-circuiting on the first that rejects.
        // Filtering per node -- an `array_filter` + `array_values` each -- walked
        // and rebuilt the whole set once per filter, so three filters meant
        // three passes and three copies of a set that only shrinks.
        if ([] !== $query->filters) {
            $kept = [];
            foreach ($rows as $row) {
                foreach ($query->filters as $node) {
                    if (!$this->matchesNode($row, $node)) {
                        continue 2;   // this row is out; skip the remaining filters
                    }
                }
                $kept[] = $row;
            }
            $rows = $kept;
        }

        // ONE sort, comparing keys in priority order. This was a separate `usort`
        // per key applied in reverse -- correct, since PHP's sort has been stable
        // since 8.0, but it paid a full n·log n pass for every key.
        //
        // Warning: The comparison is INLINE on purpose. Factoring it into a
        // private `compareBy()` reads better and measured SLOWER than the code
        // it replaced (35.5ms vs 31.0ms on 5 214 rows): a comparator runs once
        // per comparison, so a method call per key per comparison outweighed
        // the whole extra sort pass it saved. Inlined it is 22.9ms.
        if ([] !== $query->sort) {
            usort($rows, function (array $a, array $b) use ($query): int {
                foreach ($query->sort as $sort) {
                    $va = $a[$sort->field] ?? '';
                    $vb = $b[$sort->field] ?? '';
                    $cmp = is_numeric($va) && is_numeric($vb)
                        ? ($va <=> $vb)
                        : strcmp((string) $va, (string) $vb);
                    if (SortDirection::Desc === $sort->direction) {
                        $cmp = -$cmp;
                    }
                    if (0 !== $cmp) {
                        return $cmp;
                    }
                }

                return 0;   // equal on every key -- a stable sort keeps input order
            });
        }

        return $rows;
    }

    /** @param array<string, mixed> $row */
    private function matchesNode(array $row, FilterNode|OrNode|AndNode $node): bool
    {
        // Warning: Plain loops, deliberately. `array_any`/`array_all` express
        // this more clearly and were tried here -- they were MEASURABLY SLOWER,
        // because the callback captures `$row` and so a fresh closure is
        // allocated on every call, and this runs once per row per node. Reach
        // for them in cold paths, not in here.
        if ($node instanceof OrNode) {
            foreach ($node->nodes as $child) {
                if ($this->matchesNode($row, $child)) {
                    return true;
                }
            }

            return false;
        }

        if ($node instanceof AndNode) {
            foreach ($node->nodes as $child) {
                if (!$this->matchesNode($row, $child)) {
                    return false;
                }
            }

            return true;
        }

        $value = $row[$node->field] ?? null;
        $filter = $node->value;

        return match ($node->op) {
            FilterOp::Eq => $value === $filter,
            FilterOp::Ne => $value !== $filter,
            FilterOp::Gt => $value > $filter,
            FilterOp::Lt => $value < $filter,
            FilterOp::Ge => $value >= $filter,
            FilterOp::Le => $value <= $filter,
            FilterOp::Cn => str_contains((string) $value, (string) $filter),
            FilterOp::Bw => str_starts_with((string) $value, (string) $filter),
            FilterOp::Ew => str_ends_with((string) $value, (string) $filter),
            FilterOp::Null => null === $value || '' === $value,
            FilterOp::Nn => null !== $value && '' !== $value,
            FilterOp::In => in_array($value, (array) $filter, true),
            FilterOp::Ni => !in_array($value, (array) $filter, true),
        };
    }
}
