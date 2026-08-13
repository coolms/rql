<?php

declare(strict_types=1);

namespace CoolMS\Rql\Tests;

use CoolMS\Rql\AndNode;
use CoolMS\Rql\FilterNode;
use CoolMS\Rql\FilterOp;
use CoolMS\Rql\OrNode;
use CoolMS\Rql\RqlMemoryFilter;
use CoolMS\Rql\RqlQuery;
use CoolMS\Rql\SortDirection;
use CoolMS\Rql\SortNode;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RqlMemoryFilterTest extends TestCase
{
    private RqlMemoryFilter $filter;

    /** @var list<array<string, mixed>> */
    private static array $rows = [
        ['id' => 1, 'name' => 'Alice', 'age' => 30, 'active' => true],
        ['id' => 2, 'name' => 'Bob', 'age' => 25, 'active' => false],
        ['id' => 3, 'name' => 'Carol', 'age' => 35, 'active' => true],
        ['id' => 4, 'name' => 'Dave', 'age' => 25, 'active' => true],
    ];

    // ── No-op ──────────────────────────────────────────────────────────────────

    #[Test]
    public function emptyQueryReturnsAllRows(): void
    {
        $result = $this->filter->apply(self::$rows, new RqlQuery());
        self::assertCount(4, $result);
    }

    // ── Equality filters ───────────────────────────────────────────────────────

    #[Test]
    public function eqFilterReturnsMatchingRows(): void
    {
        $query = new RqlQuery(filters: [new FilterNode('active', FilterOp::Eq, false)]);
        $result = $this->filter->apply(self::$rows, $query);
        self::assertCount(1, $result);
        self::assertSame('Bob', $result[0]['name']);
    }

    #[Test]
    public function neFilterExcludesMatchingRows(): void
    {
        $query = new RqlQuery(filters: [new FilterNode('active', FilterOp::Ne, false)]);
        $result = $this->filter->apply(self::$rows, $query);
        self::assertCount(3, $result);
    }

    // ── Comparison filters ─────────────────────────────────────────────────────

    #[Test]
    public function gtFilterReturnsGreaterRows(): void
    {
        $query = new RqlQuery(filters: [new FilterNode('age', FilterOp::Gt, 25)]);
        $result = $this->filter->apply(self::$rows, $query);
        // Alice (30) and Carol (35)
        self::assertCount(2, $result);
    }

    #[Test]
    public function ltFilterReturnsLesserRows(): void
    {
        $query = new RqlQuery(filters: [new FilterNode('age', FilterOp::Lt, 30)]);
        $result = $this->filter->apply(self::$rows, $query);
        // Bob (25) and Dave (25)
        self::assertCount(2, $result);
    }

    #[Test]
    public function geFilterIsInclusive(): void
    {
        $query = new RqlQuery(filters: [new FilterNode('age', FilterOp::Ge, 30)]);
        $result = $this->filter->apply(self::$rows, $query);
        // Alice (30) and Carol (35)
        self::assertCount(2, $result);
    }

    #[Test]
    public function leFilterIsInclusive(): void
    {
        $query = new RqlQuery(filters: [new FilterNode('age', FilterOp::Le, 25)]);
        $result = $this->filter->apply(self::$rows, $query);
        // Bob (25) and Dave (25)
        self::assertCount(2, $result);
    }

    // ── String filters ─────────────────────────────────────────────────────────

    #[Test]
    public function cnFilterMatchesSubstring(): void
    {
        $query = new RqlQuery(filters: [new FilterNode('name', FilterOp::Cn, 'ar')]);
        $result = $this->filter->apply(self::$rows, $query);
        // Carol
        self::assertCount(1, $result);
        self::assertSame('Carol', $result[0]['name']);
    }

    #[Test]
    public function bwFilterMatchesPrefix(): void
    {
        $query = new RqlQuery(filters: [new FilterNode('name', FilterOp::Bw, 'A')]);
        $result = $this->filter->apply(self::$rows, $query);
        self::assertCount(1, $result);
        self::assertSame('Alice', $result[0]['name']);
    }

    #[Test]
    public function ewFilterMatchesSuffix(): void
    {
        $query = new RqlQuery(filters: [new FilterNode('name', FilterOp::Ew, 'e')]);
        $result = $this->filter->apply(self::$rows, $query);
        // Alice, Dave
        self::assertCount(2, $result);
    }

    // ── Null / not-null ────────────────────────────────────────────────────────

    #[Test]
    public function nullFilterMatchesNullAndEmptyString(): void
    {
        $rows = [
            ['id' => 1, 'bio' => null],
            ['id' => 2, 'bio' => ''],
            ['id' => 3, 'bio' => 'has bio'],
        ];
        $query = new RqlQuery(filters: [new FilterNode('bio', FilterOp::Null, null)]);
        $result = $this->filter->apply($rows, $query);
        self::assertCount(2, $result);
    }

    #[Test]
    public function nnFilterExcludesNullAndEmptyString(): void
    {
        $rows = [
            ['id' => 1, 'bio' => null],
            ['id' => 2, 'bio' => ''],
            ['id' => 3, 'bio' => 'has bio'],
        ];
        $query = new RqlQuery(filters: [new FilterNode('bio', FilterOp::Nn, null)]);
        $result = $this->filter->apply($rows, $query);
        self::assertCount(1, $result);
        self::assertSame('has bio', $result[0]['bio']);
    }

    // ── In / Not-in ────────────────────────────────────────────────────────────

    #[Test]
    public function inFilterMatchesList(): void
    {
        $query = new RqlQuery(filters: [new FilterNode('age', FilterOp::In, [25, 35])]);
        $result = $this->filter->apply(self::$rows, $query);
        // Bob (25), Carol (35), Dave (25)
        self::assertCount(3, $result);
    }

    #[Test]
    public function niFilterExcludesList(): void
    {
        $query = new RqlQuery(filters: [new FilterNode('age', FilterOp::Ni, [25, 35])]);
        $result = $this->filter->apply(self::$rows, $query);
        // Alice (30)
        self::assertCount(1, $result);
    }

    // ── OR node ────────────────────────────────────────────────────────────────

    #[Test]
    public function orNodeMatchesAnyChild(): void
    {
        $or = new OrNode([
            new FilterNode('name', FilterOp::Eq, 'Alice'),
            new FilterNode('name', FilterOp::Eq, 'Bob'),
        ]);
        $query = new RqlQuery(filters: [$or]);
        $result = $this->filter->apply(self::$rows, $query);
        self::assertCount(2, $result);
    }

    #[Test]
    public function orNodeReturnsEmptyWhenNoMatch(): void
    {
        $or = new OrNode([
            new FilterNode('name', FilterOp::Eq, 'Nobody'),
        ]);
        $query = new RqlQuery(filters: [$or]);
        $result = $this->filter->apply(self::$rows, $query);
        self::assertCount(0, $result);
    }

    // ── AND node ───────────────────────────────────────────────────────────────

    #[Test]
    public function andNodeMatchesOnlyWhenEveryChildMatches(): void
    {
        // age == 25 AND active == true -> only Dave (Bob is 25 but inactive).
        $and = new AndNode([
            new FilterNode('age', FilterOp::Eq, 25),
            new FilterNode('active', FilterOp::Eq, true),
        ]);
        $query = new RqlQuery(filters: [$and]);
        $result = $this->filter->apply(self::$rows, $query);
        self::assertCount(1, $result);
        self::assertSame('Dave', $result[0]['name']);
    }

    #[Test]
    public function nestedAndInsideOrMatchesEitherBranch(): void
    {
        // or(and(age==35), eq(name,'Bob')) -> Carol (35) OR Bob.
        $or = new OrNode([
            new AndNode([new FilterNode('age', FilterOp::Eq, 35)]),
            new FilterNode('name', FilterOp::Eq, 'Bob'),
        ]);
        $query = new RqlQuery(filters: [$or]);
        $result = $this->filter->apply(self::$rows, $query);
        self::assertCount(2, $result);
        self::assertSame(['Bob', 'Carol'], self::sortedNames($result));
    }

    // ── Multiple filters (AND logic) ───────────────────────────────────────────

    #[Test]
    public function multipleFiltersAreAndedTogether(): void
    {
        $query = new RqlQuery(filters: [
            new FilterNode('age', FilterOp::Eq, 25),
            new FilterNode('active', FilterOp::Eq, true),
        ]);
        $result = $this->filter->apply(self::$rows, $query);
        // Only Dave (25, active=true)
        self::assertCount(1, $result);
        self::assertSame('Dave', $result[0]['name']);
    }

    // ── Sorting ────────────────────────────────────────────────────────────────

    #[Test]
    public function sortsAscByStringField(): void
    {
        $query = new RqlQuery(sort: [new SortNode('name', SortDirection::Asc)]);
        $result = $this->filter->apply(self::$rows, $query);
        self::assertSame(['Alice', 'Bob', 'Carol', 'Dave'], array_column($result, 'name'));
    }

    #[Test]
    public function sortsDescByStringField(): void
    {
        $query = new RqlQuery(sort: [new SortNode('name', SortDirection::Desc)]);
        $result = $this->filter->apply(self::$rows, $query);
        self::assertSame(['Dave', 'Carol', 'Bob', 'Alice'], array_column($result, 'name'));
    }

    #[Test]
    public function sortsNumericFieldNumerically(): void
    {
        $query = new RqlQuery(sort: [new SortNode('age', SortDirection::Asc)]);
        $result = $this->filter->apply(self::$rows, $query);
        $ages = array_column($result, 'age');
        self::assertSame([25, 25, 30, 35], $ages);
    }

    #[Test]
    public function multiSortAppliesInPriorityOrder(): void
    {
        // Primary sort: age ASC; secondary sort: name ASC (for ties)
        $query = new RqlQuery(sort: [
            new SortNode('age', SortDirection::Asc),
            new SortNode('name', SortDirection::Asc),
        ]);
        $result = $this->filter->apply(self::$rows, $query);
        $names = array_column($result, 'name');
        // age 25: Bob, Dave (alphabetical); age 30: Alice; age 35: Carol
        self::assertSame(['Bob', 'Dave', 'Alice', 'Carol'], $names);
    }

    // ── Filter + sort combined ─────────────────────────────────────────────────

    #[Test]
    public function filterThenSortWorksTogether(): void
    {
        $query = new RqlQuery(
            filters: [new FilterNode('active', FilterOp::Eq, true)],
            sort: [new SortNode('name', SortDirection::Desc)],
        );
        $result = $this->filter->apply(self::$rows, $query);
        // Alice, Carol, Dave (active=true), sorted desc by name
        self::assertSame(['Dave', 'Carol', 'Alice'], array_column($result, 'name'));
    }

    // ── Empty input ────────────────────────────────────────────────────────────

    #[Test]
    public function emptyRowsReturnsEmpty(): void
    {
        $query = new RqlQuery(filters: [new FilterNode('id', FilterOp::Eq, 1)]);
        $result = $this->filter->apply([], $query);
        self::assertSame([], $result);
    }

    protected function setUp(): void
    {
        $this->filter = new RqlMemoryFilter();
    }

    /**
     * @param array<array<string, mixed>> $rows
     *
     * @return list<string>
     */
    private static function sortedNames(array $rows): array
    {
        $names = array_map(static fn (array $r): string => (string) $r['name'], $rows);
        sort($names);

        return $names;
    }
}
