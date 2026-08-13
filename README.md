# coolms/rql

[![CI](https://github.com/coolms/rql/actions/workflows/ci.yml/badge.svg)](https://github.com/coolms/rql/actions/workflows/ci.yml)
[![PHP](https://img.shields.io/badge/php-%E2%89%A5%208.5-777bb4)](https://www.php.net/releases/8.5/en.php)
[![License](https://img.shields.io/badge/license-MIT-green)](LICENSE)

**Resource Query Language for PHP** -- parse client-supplied filter/sort/paginate
query strings into an immutable, type-safe AST, then translate that AST into a
database query with a field whitelist for safety.

Two query grammars, **one** AST, so you can adopt the terse function-call
dialect without breaking existing `?filter=` clients:

```
# Classic DSL                          # Persvr RQL (function-call)
?filter=title cn "hello"               ?contains(title,"hello")
 &filter=price gt 100                    &gt(price,100)
 &sort=-createdAt                        &sort(-createdAt)
 &page=1&limit=20                        &limit(20)
```

Both parse to the **same** `RqlQuery` value object. Turning that into an actual
query is your application's job -- the AST assumes no ORM, no query builder, and
no particular database.

- **Zero runtime dependencies** -- pure PHP 8.5 value objects, two parsers, and
  interfaces. No framework, no ORM.
- **Immutable** -- every node is a `final readonly` class.
- **Safe by construction** -- fields must be explicitly whitelisted per query
  context (`RqlContext`); an unlisted field throws, never leaks schema.

> **Scope of this package.** `coolms/rql` is the *pure* layer: the grammar
> parsers, the AST, the security context, and the repository contract. Two
> pieces are deliberately **not** here, because either one would pull in a
> dependency and end the zero-dependency promise:
>
> - a **translator**, which walks the AST into your query builder;
> - a **request adapter**, which lifts the query string off an HTTP request.
>
> Both are small, and both belong to your application -- which already knows
> which ORM and which HTTP layer it uses, and this package never needs to. The
> sections below document the package API first, then the contract each of
> those two pieces has to meet.

## Installation

```bash
composer require coolms/rql
```

Requires PHP `^8.5`.

---

## The two grammars

You can use **either** grammar, or both -- they emit an identical AST. Pick one
per project, or let a request adapter choose per request (see *Choosing a
grammar* below).

### 1. Classic DSL -- `field op value`

```
filter=<field> <op> <value>     # repeat filter= → AND
filter=a op x|b op y            # '|' within one param → OR group
sort=-created,title             # comma list; '-' prefix = DESC
page=2
limit=50
filter[]=a op x&filter[]=b op y # PHP array syntax also works
```

Parsed by [`RqlParser`](src/RqlParser.php). This is the back-compatible dialect
used by grid-style query builders.

### 2. Persvr RQL -- `op(field, value)`

Canonical [RQL](https://github.com/persvr/rql) (a de-facto spec, **not** an IETF
RFC; FIQL/OData are different dialects and not supported):

```
op(field, value)                # eq(price,10)
&                               # top-level '&' = implicit AND
and( ... ) / or( ... )              # boolean groups, arbitrarily nested
in(field,(a,b)) / in(field,a,b) # both list forms accepted
sort(-field,+other)             # ± prefix per field
limit(count) / limit(count,start)
page(n)
```

Parsed by [`RqlExpressionParser`](src/RqlExpressionParser.php).

The whole query string *is* the expression -- there is no `filter=` wrapper, and
boolean groups nest to any depth:

```
or(and(eq(status,"published"),gt(price,10)),eq(featured,true))&sort(-price)&limit(10)
```

---

## Operators

Both grammars share one operator set ([`FilterOp`](src/FilterOp.php)):

| Op     | Meaning                | DSL example                | RQL example              |
|--------|------------------------|----------------------------|--------------------------|
| `eq`   | equals                 | `status eq "published"`    | `eq(status,"published")` |
| `ne`   | not equals             | `status ne "draft"`        | `ne(status,"draft")`     |
| `gt`   | greater than           | `price gt 100`             | `gt(price,100)`          |
| `lt`   | less than              | `price lt 100`             | `lt(price,100)`          |
| `ge`   | greater than or equal  | `price ge 100`             | `ge(price,100)`          |
| `le`   | less than or equal     | `price le 100`             | `le(price,100)`          |
| `cn`   | contains (`LIKE %x%`)  | `title cn "news"`          | `contains(title,"news")` |
| `bw`   | begins with (`LIKE x%`)| `slug bw "2026-"`          | `bw(slug,"2026-")`       |
| `ew`   | ends with (`LIKE %x`)  | `email ew "@corp.com"`     | `ew(email,"@corp.com")`  |
| `in`   | in list                | `status in ["a","b"]`      | `in(status,("a","b"))`   |
| `ni`   | not in list            | `status ni ["a","b"]`      | `out(status,("a","b"))`  |
| `null` | is null                | `deletedAt null`           | `null(deletedAt)`        |
| `nn`   | is not null            | `deletedAt nn`             | `nn(deletedAt)`          |

**RQL operator aliases:** `contains`→`cn`, `out`→`ni`, `isnull`→`null`,
`notnull`→`nn`. **Null normalisation:** `eq(field,null)` becomes `IS NULL` and
`ne(field,null)` becomes `IS NOT NULL` (matching the DSL's `null`/`nn`).

`cn`/`bw`/`ew` are expected to be **case-insensitive**. The AST carries only the
operator, so this is part of the translator's contract: compare
`LOWER(col) LIKE :v` with the bound value already lower-cased.

## Value literals

Both grammars parse the same literal types:

| Literal            | Parses as | Notes                                             |
|--------------------|-----------|---------------------------------------------------|
| `"hello world"`    | string    | double-quoted; may contain `,` `&` `(` `)`        |
| `hello`            | string    | bare/unquoted string is allowed for simple values |
| `123` / `-5`       | int       |                                                   |
| `12.5`             | float     |                                                   |
| `true` / `false`   | bool      |                                                   |
| `null`             | null      |                                                   |
| `["a",1]` (DSL)    | array     | only for `in`/`ni`                                |
| `(a,b)` (RQL)      | array     | `in(f,(a,b))` **or** flat `in(f,a,b)`             |

Query strings are URL-decoded per term, so
`eq(title,%22hello%20world%22)` == `eq(title,"hello world")`.

## Sorting & pagination

- **Sort** -- comma/`sort()` list, `-` = DESC, `+` or none = ASC. First field
  has highest priority.
- **Pagination** -- `page` is 1-based; `limit` is capped at
  `RqlQuery::MAX_LIMIT` (200) and floored at 1 (default `DEFAULT_LIMIT` = 20).
- RQL `limit(count, start)` converts a row offset to a 1-based page:
  `limit(20,40)` → `limit=20, page=3`.

---

## AST reference

`parse()` returns an immutable [`RqlQuery`](src/RqlQuery.php):

```php
final readonly class RqlQuery {
    public array $filters;   // array<FilterNode|OrNode|AndNode> -- implicit AND
    public array $sort;      // SortNode[]
    public int   $page;      // 1-based
    public int   $limit;     // 1..MAX_LIMIT
}
```

Node types:

| Class                            | Shape                                        |
|----------------------------------|----------------------------------------------|
| [`FilterNode`](src/FilterNode.php) | `{ string $field, FilterOp $op, mixed $value }` -- one leaf condition. `isJsonField()`/`jsonKey()` detect `extras.*` JSON paths. |
| [`OrNode`](src/OrNode.php)        | `{ array $nodes }` -- OR of children.         |
| [`AndNode`](src/AndNode.php)      | `{ array $nodes }` -- AND of children.         |
| [`SortNode`](src/SortNode.php)    | `{ string $field, SortDirection $direction }` |
| [`SortDirection`](src/SortDirection.php) | enum `Asc` / `Desc`                    |

**The AST is arbitrarily nestable** -- an `OrNode`/`AndNode` child may itself be
a `FilterNode`, `OrNode`, or `AndNode`. The top-level `RqlQuery::$filters` is an
implicit AND, so an explicit `AndNode` is only needed *inside* another group.

```php
use CoolMS\Rql\RqlExpressionParser;

$query = (new RqlExpressionParser())->parse('or(and(eq(a,1),eq(b,2)),eq(c,3))');
// $query->filters === [ OrNode([ AndNode([FilterNode(a,Eq,1), FilterNode(b,Eq,2)]),
//                                FilterNode(c,Eq,3) ]) ]
```

---

## Parsing

```php
use CoolMS\Rql\RqlParser;            // classic DSL
use CoolMS\Rql\RqlExpressionParser;  // Persvr RQL

$dsl    = new RqlParser();
$persvr = new RqlExpressionParser();

$q1 = $dsl->parse('filter=price gt 100&sort=-createdAt&limit=20');
$q2 = $persvr->parse('gt(price,100)&sort(-createdAt)&limit(20)');
// $q1 and $q2 are equivalent RqlQuery objects.
```

Both parsers are pure and stateless -- construct once, reuse. Invalid syntax
throws [`RqlParseException`](src/Exception/RqlParseException.php) (map to HTTP
400).

### Choosing a grammar (request adapter)

A request adapter can read the raw `QUERY_STRING` and pick per request, which is
back-compat-safe:

- any recognised DSL param (`filter=` / `filter[]=` / `sort=` / `page=` /
  `limit=`) present → **classic** `RqlParser` (every existing client is untouched);
- otherwise, a query that is purely `name(...)` terms → **Persvr**
  `RqlExpressionParser`.

Sniff for a DSL `key=` param and fall back to the expression parser; or just
standardise on one grammar and call its parser directly. Reading the raw query
string rather than a pre-parsed parameter bag matters for the classic DSL,
because repeated `filter=` terms are the AND syntax and most parameter bags keep
only the last one.

---

## Applying to a database (security + translation)

Parsing is safe on its own, but you must not let a client filter/sort by an
arbitrary column. [`RqlContext`](src/RqlContext.php) is the whitelist + field-map:

```php
use CoolMS\Rql\RqlContext;

$ctx = new RqlContext(
    entityAlias:   'n',                              // root alias your query uses
    allowedFields: ['title', 'price', 'extras.*'],   // ONLY these may be queried
    fieldMap:      ['price' => 'n.priceCents'],       // logical name → query expression
);
```

- Any field not in `allowedFields` throws
  [`RqlSecurityException`](src/Exception/RqlSecurityException.php) (→ HTTP 400,
  deliberately not 403, so it doesn't confirm which columns exist).
- `extras.*` whitelists every JSON key under `extras`; or list a specific key.
- Relation traversal one level deep (`identifiers.value`) is supported; depth > 1
  is rejected.
- `fieldMap` lets you expose a stable public name over a differently-named column.

Your translator walks the AST against whatever query builder you use and returns
a paginated [`RqlResult`](src/RqlResult.php):

```php
$result = $visitor->apply($query, $queryBuilder, $ctx);

$result->items;         // array<object> -- the page of hydrated entities
$result->totalItems;    // total matching (COUNT, ignoring pagination)
$result->page;          // echoed
$result->limit;         // echoed
$result->totalPages();
$result->hasNextPage();
```

Implement [`RqlRepositoryInterface`](src/RqlRepositoryInterface.php) on a
repository to expose `findByRql(RqlQuery, RqlContext): RqlResult` as the standard
seam.

> **Writing a translator -- the one trap.** The boolean-group branch must be
> *exhaustive* over every case in `FilterOp`. A group builder that returns
> nothing for some operators does not error; it silently drops that alternative
> out of the OR/AND and returns the wrong rows. Assert on the generated query
> text, not just on the result set -- a dropped alternative shows up there as a
> missing clause, whereas a row count merely looks a little off.

## Exceptions

All extend `DomainException`; all map to **HTTP 400**.

| Exception                     | When                                             |
|-------------------------------|--------------------------------------------------|
| `RqlParseException`           | malformed grammar / unknown operator name        |
| `RqlSecurityException`        | field not in the `RqlContext` whitelist          |
| `RqlInvalidOperatorException` | operator not applicable to the field's SQL type (e.g. a LIKE-family op on a numeric field) |
| `RqlInvalidValueException`    | value can't be coerced to the field's DB type (e.g. a non-UUID string on a UUID column) |

---

## Status

Full-RQL is landing in phases:

- ✅ Classic DSL parser + AST + security context.
- ✅ Persvr function-call grammar (`RqlExpressionParser`), nestable `AndNode`.
- ✅ In-memory filter for sources with no query layer at all.
- ⏳ Projection/`select()` and richer aggregates.

## License

MIT © Dmitry Popov
