<?php

declare(strict_types=1);

namespace CoolMS\Rql;

use CoolMS\Rql\Exception\RqlParseException;

/**
 * Parses URL query strings into immutable RqlQuery AST.
 *
 * Supported params:
 *   filter=field op value          (may appear multiple times, combined with AND)
 *   filter=field op val|field op v (pipe means OR within one filter)
 *   sort=field,-otherField         (comma-separated, - prefix = DESC)
 *   page=N                         (1-based)
 *   limit=N                        (capped at RqlQuery::MAX_LIMIT)
 *
 * Value types parsed:
 *   "quoted string"   parses as string
 *   123               parses as int
 *   12.5              parses as float
 *   true / false      parses as bool
 *   null              parses as null
 *   ["a","b"]         parses as array (for in/ni operators)
 */
final readonly class RqlParser
{
    #[\NoDiscard()]
    public function parse(string $queryString): RqlQuery
    {
        if ('' === $queryString) {
            return new RqlQuery();
        }

        // parse_str for scalar params (sort, page, limit)
        // Note: parse_str overwrites repeated keys -- we handle filter separately
        parse_str($queryString, $params);

        return new RqlQuery(
            filters: $this->parseFilters($this->extractFilterParams($queryString, $params)),
            sort: $this->parseSort(is_string($params['sort'] ?? null) ? $params['sort'] : ''),
            page: max(1, (int) ($params['page'] ?? 1)),
            limit: min(
                RqlQuery::MAX_LIMIT,
                max(1, (int) ($params['limit'] ?? RqlQuery::DEFAULT_LIMIT)),
            ),
        );
    }

    /**
     * Extract all filter values from the raw query string.
     *
     * Supports both:
     *   filter[]=val1&filter[]=val2 (PHP array notation -- parse_str handles)
     *   filter=val1&filter=val2 (repeated key -- parse_str loses all but last)
     *
     * @param array<int|string, array<mixed>|string> $parsedParams
     *
     * @return string[]
     */
    private function extractFilterParams(string $queryString, array $parsedParams): array
    {
        // parse_str already returned an array, i.e. filter[]=... notation
        if (is_array($parsedParams['filter'] ?? null)) {
            return $parsedParams['filter'];
        }

        // Manually collect all filter=... segments from the raw query string
        $filters = [];
        foreach (explode('&', $queryString) as $segment) {
            if (str_starts_with($segment, 'filter=')) {
                $filters[] = substr($segment, 7)
                        |> urldecode(...);
            }
        }

        return $filters;
    }

    /**
     * @param string[] $filterParams
     *
     * @return array<FilterNode|OrNode>
     */
    private function parseFilters(array $filterParams): array
    {
        $nodes = [];
        foreach ($filterParams as $param) {
            $param = trim((string) $param);
            if ('' === $param) {
                continue;
            }

            // Check for OR: "title cn "a"|description cn "b""
            // Split on | but NOT inside quoted strings or arrays
            $parts = $this->splitOnPipe($param);

            if (count($parts) > 1) {
                $orNodes = array_map(fn (string $p) => $this->parseSingleFilter(trim($p)), $parts);
                $nodes[] = new OrNode($orNodes);
            } else {
                $nodes[] = $this->parseSingleFilter($param);
            }
        }

        return $nodes;
    }

    private function parseSingleFilter(string $expr): FilterNode
    {
        // Pattern: fieldName operator value
        // field:    word chars + dots (for extras.key)
        // operator: 2-4 lowercase letters
        // value:    everything after (quoted string, number, bool, null, array)
        if (!preg_match('/^([\w.]+)\s+([a-z]{2,4})\s*(.*)$/s', trim($expr), $m)) {
            throw new RqlParseException("Invalid filter expression: $expr", $expr);
        }

        [, $field, $opStr, $rawValue] = $m;

        $op = FilterOp::tryFrom($opStr)
            ?? throw new RqlParseException("Unknown operator: $opStr", $opStr);

        // null/nn operators have no value
        $value = in_array($op, [FilterOp::Null, FilterOp::Nn], true)
            ? null
            : $this->parseValue(trim($rawValue), $op);

        return new FilterNode($field, $op, $value);
    }

    private function parseValue(string $raw, FilterOp $op): mixed
    {
        // Array literal: ["a","b",123]
        if (str_starts_with($raw, '[') && str_ends_with($raw, ']')) {
            if (!in_array($op, [FilterOp::In, FilterOp::Ni], true)) {
                throw new RqlParseException("Array values only valid for 'in'/'ni' operators");
            }

            return $this->parseArray($raw);
        }

        // Quoted string: "hello world"
        if (str_starts_with($raw, '"') && str_ends_with($raw, '"')) {
            return substr($raw, 1, -1)
                    |> stripslashes(...);
        }

        // Boolean literals
        if ('true' === $raw) {
            return true;
        }
        if ('false' === $raw) {
            return false;
        }
        if ('null' === $raw) {
            return null;
        }

        // Numeric
        if (ctype_digit($raw) || (str_starts_with($raw, '-') && ctype_digit(substr($raw, 1)))) {
            return (int) $raw;
        }
        if (is_numeric($raw)) {
            return (float) $raw;
        }

        // Unquoted string (allowed for simple values)
        return $raw;
    }

    /**
     * @return array<mixed>
     */
    private function parseArray(string $raw): array
    {
        $inner = substr($raw, 1, -1); // strip [ ]
        if ('' === trim($inner)) {
            return [];
        }
        // Simple CSV-like split, handles "a","b",123
        $items = [];
        foreach (explode(',', $inner) as $item) {
            $items[] = trim($item)
                    |> (fn (string $str) => $this->parseValue($str, FilterOp::Eq));
        }

        return $items;
    }

    /**
     * @return SortNode[]
     */
    private function parseSort(string $sortParam): array
    {
        if ('' === $sortParam) {
            return [];
        }
        $nodes = [];
        foreach (explode(',', $sortParam) as $field) {
            $field = trim($field);
            if ('' === $field) {
                continue;
            }
            if (str_starts_with($field, '-')) {
                $nodes[] = substr($field, 1)
                        |> (fn (string $str): SortNode => new SortNode($str, SortDirection::Desc));
            } else {
                $nodes[] = new SortNode($field, SortDirection::Asc);
            }
        }

        return $nodes;
    }

    /**
     * Split on | but not inside quoted strings or arrays.
     *
     * @return string[]
     */
    private function splitOnPipe(string $expr): array
    {
        $parts = [];
        $current = '';
        $depth = 0;
        $inStr = false;

        for ($i = 0, $len = strlen($expr); $i < $len; ++$i) {
            $ch = $expr[$i];
            if ('"' === $ch && (0 === $i || '\\' !== $expr[$i - 1])) {
                $inStr = !$inStr;
            } elseif (!$inStr && '[' === $ch) {
                ++$depth;
            } elseif (!$inStr && ']' === $ch) {
                --$depth;
            } elseif (!$inStr && 0 === $depth && '|' === $ch) {
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
