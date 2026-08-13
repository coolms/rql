<?php

declare(strict_types=1);

namespace CoolMS\Rql;

use CoolMS\Rql\Exception\RqlParseException;

/**
 * Parses a Persvr-style RQL expression query string into an immutable
 * {@see RqlQuery} AST — the SECOND front-end parser alongside the
 * `filter=field op value` DSL {@see RqlParser}; both emit the SAME AST, which
 * one recursive translator turns into DQL — "two parsers, one AST".
 *
 * Grammar (phase 1 — filters + sort + limit):
 *   query := term ('&' term)*                 (top-level '&' = implicit AND)
 *   term  := NAME '(' args ')'
 *   args  := arg (',' arg)*
 *   arg   := term | value | '(' value,* ')'   (nested term for and/or; list for in/out)
 *
 * Comparison ops (map to FilterOp): eq ne lt le gt ge cn(contains) bw ew
 *   in out(=ni) null(field) nn(field). `eq(field,null)`/`ne(field,null)`
 *   normalise to null/nn. Boolean groups: and(...) or(...).
 *   Control: sort(±field,...) limit(count[,start]) page(n).
 *
 * Value literals (same as the DSL): "quoted" | int | float | true | false | null | bare.
 */
final readonly class RqlExpressionParser
{
    /** @var array<string, string> Operator-name aliases → canonical FilterOp value. */
    private const array OP_ALIASES = [
        'contains' => 'cn',
        'out' => 'ni',
        'notnull' => 'nn',
        'isnull' => 'null',
    ];

    #[\NoDiscard()]
    public function parse(string $query): RqlQuery
    {
        $query = trim($query);
        if ('' === $query) {
            return new RqlQuery();
        }

        $filters = [];
        $sort = [];
        $page = 1;
        $limit = RqlQuery::DEFAULT_LIMIT;

        foreach ($this->splitTopLevel($query, '&') as $rawTerm) {
            $term = trim(urldecode(trim($rawTerm)));
            if ('' === $term) {
                continue;
            }

            [$name, $argsStr] = $this->parseCall($term);
            $canonical = strtolower($name);

            if ('sort' === $canonical) {
                $sort = $this->parseSort($argsStr);

                continue;
            }
            if ('limit' === $canonical) {
                [$limit, $page] = $this->parseLimit($argsStr);

                continue;
            }
            if ('page' === $canonical) {
                $page = max(1, (int) trim($argsStr));

                continue;
            }

            $filters[] = $this->parseNode($name, $argsStr);
        }

        return new RqlQuery(
            filters: $filters,
            sort: $sort,
            page: $page,
            limit: min(RqlQuery::MAX_LIMIT, max(1, $limit)),
        );
    }

    private function parseNode(string $name, string $argsStr): FilterNode|OrNode|AndNode
    {
        $canonical = strtolower($name);

        // Boolean group: and(...) / or(...) — children are themselves terms.
        if ('and' === $canonical || 'or' === $canonical) {
            $children = [];
            foreach ($this->splitTopLevel($argsStr, ',') as $childTerm) {
                $childTerm = trim($childTerm);
                if ('' === $childTerm) {
                    continue;
                }
                [$cName, $cArgs] = $this->parseCall($childTerm);
                $children[] = $this->parseNode($cName, $cArgs);
            }

            return 'and' === $canonical ? new AndNode($children) : new OrNode($children);
        }

        // Comparison op.
        $op = $this->resolveOp($canonical);
        $args = $this->splitTopLevel($argsStr, ',');
        $field = trim($args[0] ?? '');
        if ('' === $field) {
            throw new RqlParseException("RQL comparison is missing a field: {$name}({$argsStr})", $argsStr);
        }

        // null/nn take no value.
        if (FilterOp::Null === $op || FilterOp::Nn === $op) {
            return new FilterNode($field, $op, null);
        }

        // in/ni accept an array — either `in(f,(a,b))` or `in(f,a,b)`.
        if (FilterOp::In === $op || FilterOp::Ni === $op) {
            $rest = array_slice($args, 1);
            $first = isset($rest[0]) ? trim($rest[0]) : '';
            if (1 === count($rest) && str_starts_with($first, '(') && str_ends_with($first, ')')) {
                $inner = substr($first, 1, -1);
                $rest = '' === trim($inner) ? [] : $this->splitTopLevel($inner, ',');
            }

            return new FilterNode($field, $op, array_map(fn (string $v): mixed => $this->parseValue(trim($v)), $rest));
        }

        $value = $this->parseValue(trim($args[1] ?? ''));

        // `eq(field,null)` / `ne(field,null)` normalise to IS NULL / IS NOT NULL.
        if (null === $value && FilterOp::Eq === $op) {
            return new FilterNode($field, FilterOp::Null, null);
        }
        if (null === $value && FilterOp::Ne === $op) {
            return new FilterNode($field, FilterOp::Nn, null);
        }

        return new FilterNode($field, $op, $value);
    }

    private function resolveOp(string $name): FilterOp
    {
        $canonical = self::OP_ALIASES[$name] ?? $name;

        return FilterOp::tryFrom($canonical)
            ?? throw new RqlParseException("Unknown RQL operator: {$name}", $name);
    }

    /**
     * @return SortNode[]
     */
    private function parseSort(string $argsStr): array
    {
        $nodes = [];
        foreach ($this->splitTopLevel($argsStr, ',') as $field) {
            $field = trim($field);
            if ('' === $field) {
                continue;
            }
            if (str_starts_with($field, '-')) {
                $nodes[] = new SortNode(substr($field, 1), SortDirection::Desc);
            } else {
                $nodes[] = new SortNode(ltrim($field, '+'), SortDirection::Asc);
            }
        }

        return $nodes;
    }

    /**
     * `limit(count)` or `limit(count, start)` (start = row offset → 1-based page).
     *
     * @return array{int, int} [limit, page]
     */
    private function parseLimit(string $argsStr): array
    {
        $args = $this->splitTopLevel($argsStr, ',');
        $limit = max(1, (int) trim($args[0] ?? (string) RqlQuery::DEFAULT_LIMIT));
        $start = isset($args[1]) ? max(0, (int) trim($args[1])) : 0;

        return [$limit, intdiv($start, $limit) + 1];
    }

    private function parseValue(string $raw): mixed
    {
        if (str_starts_with($raw, '"') && str_ends_with($raw, '"') && strlen($raw) >= 2) {
            return stripslashes(substr($raw, 1, -1));
        }
        if ('true' === $raw) {
            return true;
        }
        if ('false' === $raw) {
            return false;
        }
        if ('null' === $raw) {
            return null;
        }
        if (ctype_digit($raw) || (str_starts_with($raw, '-') && ctype_digit(substr($raw, 1)))) {
            return (int) $raw;
        }
        if (is_numeric($raw)) {
            return (float) $raw;
        }

        return $raw;
    }

    /**
     * Extract `name(args)` from a term; `args` is everything between the first
     * `(` and the matching final `)` (nested parens preserved for the caller).
     *
     * @return array{string, string} [name, argsString]
     */
    private function parseCall(string $expr): array
    {
        $expr = trim($expr);
        $open = strpos($expr, '(');
        if (false === $open || !str_ends_with($expr, ')')) {
            throw new RqlParseException("Invalid RQL term (expected name(...)): {$expr}", $expr);
        }
        $name = substr($expr, 0, $open);
        if (1 !== preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $name)) {
            throw new RqlParseException("Invalid RQL operator name: {$name}", $name);
        }

        return [$name, substr($expr, $open + 1, -1)];
    }

    /**
     * Split on $delim at paren-depth 0 and outside double-quoted strings.
     *
     * @return string[]
     */
    private function splitTopLevel(string $s, string $delim): array
    {
        $parts = [];
        $current = '';
        $depth = 0;
        $inStr = false;

        for ($i = 0, $len = strlen($s); $i < $len; ++$i) {
            $ch = $s[$i];
            if ('"' === $ch && (0 === $i || '\\' !== $s[$i - 1])) {
                $inStr = !$inStr;
            } elseif (!$inStr && '(' === $ch) {
                ++$depth;
            } elseif (!$inStr && ')' === $ch) {
                --$depth;
            } elseif (!$inStr && 0 === $depth && $delim === $ch) {
                $parts[] = $current;
                $current = '';

                continue;
            }
            $current .= $ch;
        }
        $parts[] = $current;

        return $parts;
    }
}
