<?php

declare(strict_types=1);

namespace CoolMS\Rql;

use CoolMS\Rql\Exception\RqlSecurityException;
use NoDiscard;

/**
 * Carries configuration for a specific RQL application context.
 *
 * Controls:
 *   - Which fields may appear in filter/sort (security whitelist)
 *   - How logical field names map to query expressions
 *
 * Filtering and sorting are SEPARATE capabilities and get separate lists:
 * a field can be orderable without being filterable (a computed projection
 * with no meaningful filter operator), so `$sortableFields` extends the
 * whitelist for ORDER BY only. See {@see resolveSort}.
 *
 * Example:
 *   new RqlContext(
 *       entityAlias:    'n',
 *       allowedFields:  ['title', 'sortOrder', 'isVisible', 'extras.price'],
 *       fieldMap:       ['title' => 'n.title', 'sortOrder' => 'n.sortOrder'],
 *       sortableFields: ['memberCount'],
 *       sortFieldMap:   ['memberCount' => 'memberCount'],
 *   )
 */
final readonly class RqlContext
{
    /**
     * @param string                $entityAlias      root alias for the entity (e.g. 'n', 'node', 'u'),
     *                                                prefixed onto any field the map does not cover
     * @param string[]              $allowedFields    Whitelist of filterable fields (sortable as well)
     * @param array<string, string> $fieldMap         Logical name → query expression
     * @param string|null           $dynamicTypeAlias alias of the dynamic type whose JSON extras are
     *                                                filterable, threaded through to platform visitors
     *                                                so they can look up the SQL type declared for a
     *                                                field and emit index-aligned CAST expressions
     * @param string[]              $sortableFields   Fields that are sortable but NOT filterable -- ADDED
     *                                                to `$allowedFields` for ORDER BY, never subtracted
     * @param array<string, string> $sortFieldMap     Logical name → ORDER BY expression, overlaid on
     *                                                `$fieldMap`; carries aliases that exist only in the
     *                                                sorted query (e.g. a `HIDDEN` scalar select)
     */
    public function __construct(
        public string $entityAlias,
        public array $allowedFields = [],
        public array $fieldMap = [],
        public ?string $dynamicTypeAlias = null,
        public array $sortableFields = [],
        public array $sortFieldMap = [],
    ) {
    }

    /**
     * Return a copy that resolves `$field` to `$expression` when sorting.
     *
     * For repositories that ORDER BY a projection they build themselves -- a
     * correlated `HIDDEN` scalar select, whose alias is valid ONLY in the query
     * that added it. The subquery and the alias it introduces then stay in one
     * place instead of being split between the repository and whoever built the
     * context. Does NOT widen the whitelist: a field the caller may not sort by
     * is still refused.
     */
    #[NoDiscard('withSortAlias() returns a NEW context; the receiver is unchanged.')]
    public function withSortAlias(string $field, string $expression): self
    {
        return new self(
            entityAlias: $this->entityAlias,
            allowedFields: $this->allowedFields,
            fieldMap: $this->fieldMap,
            dynamicTypeAlias: $this->dynamicTypeAlias,
            sortableFields: $this->sortableFields,
            sortFieldMap: [$field => $expression] + $this->sortFieldMap,
        );
    }

    /**
     * Resolve a logical field name to its query expression, for filtering.
     *
     * @throws RqlSecurityException when field is not in the whitelist
     */
    #[NoDiscard()]
    public function resolve(string $field): string
    {
        return $this->resolveAgainst($field, $this->allowedFields, $this->fieldMap, 'filtering');
    }

    /**
     * Resolve a logical field name to its query expression, for sorting.
     *
     * A field you may ORDER BY is not necessarily one you may filter on: a
     * computed projection like `memberCount` has no filter operator at all,
     * and routing sort through {@see resolve} rejected those with a 400.
     *
     * `$sortableFields` only ever ADDS -- everything filterable stays sortable,
     * so populating it cannot break a query that already worked.
     *
     * @throws RqlSecurityException when the field is in neither whitelist
     */
    #[NoDiscard()]
    public function resolveSort(string $field): string
    {
        return $this->resolveAgainst(
            $field,
            [...$this->allowedFields, ...$this->sortableFields],
            $this->sortFieldMap + $this->fieldMap,
            'sorting',
        );
    }

    /**
     * @param string[]              $allowed
     * @param array<string, string> $map
     *
     * @throws RqlSecurityException
     */
    private function resolveAgainst(string $field, array $allowed, array $map, string $purpose): string
    {
        // extras.* fields are allowed if 'extras.*' or specific key is whitelisted
        if (str_starts_with($field, 'extras.')) {
            $isAllowed = in_array('extras.*', $allowed, true)
                || in_array($field, $allowed, true);
            if (!$isAllowed) {
                throw new RqlSecurityException($field, $purpose);
            }

            // Left unmapped -- JSON extraction is engine-specific, so the
            // translator handles it.
            return $field;
        }

        // Reject traversal depth > 1 (e.g. a.b.c) -- never valid, prevents injection.
        if (substr_count($field, '.') > 1) {
            throw new RqlSecurityException($field, $purpose);
        }

        // Security whitelist -- covers both plain and dot-notation relation fields.
        if (!in_array($field, $allowed, true)) {
            throw new RqlSecurityException($field, $purpose);
        }

        // Dot-notation relation traversal (e.g. 'identifiers.value', 'groups.name').
        // Left as-is -- building the EXISTS subquery needs join metadata that
        // only the translator has.
        if (str_contains($field, '.')) {
            return $field;
        }

        // Plain field -- apply transparent mapping or default alias prefix.
        return $map[$field] ?? ($this->entityAlias . '.' . $field);
    }
}
