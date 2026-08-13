<?php

declare(strict_types=1);

namespace CoolMS\Rql\Tests;

use CoolMS\Rql\Exception\RqlParseException;
use CoolMS\Rql\FilterNode;
use CoolMS\Rql\FilterOp;
use CoolMS\Rql\OrNode;
use CoolMS\Rql\RqlParser;
use CoolMS\Rql\RqlQuery;
use CoolMS\Rql\SortDirection;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RqlParserTest extends TestCase
{
    private RqlParser $parser;

    #[Test]
    public function emptyStringReturnsDefaultQuery(): void
    {
        $q = $this->parser->parse('');
        self::assertSame([], $q->filters);
        self::assertSame([], $q->sort);
        self::assertSame(1, $q->page);
        self::assertSame(RqlQuery::DEFAULT_LIMIT, $q->limit);
    }

    #[Test]
    public function parsesEqFilterWithQuotedString(): void
    {
        $q = $this->parser->parse('filter=title eq "hello world"');
        self::assertCount(1, $q->filters);
        $f = $q->filters[0];
        self::assertInstanceOf(FilterNode::class, $f);
        self::assertSame('title', $f->field);
        self::assertSame(FilterOp::Eq, $f->op);
        self::assertSame('hello world', $f->value);
    }

    #[Test]
    public function parsesGtFilterWithInteger(): void
    {
        $q = $this->parser->parse('filter=price gt 100');
        $f = $q->filters[0];
        assert($f instanceof FilterNode);
        self::assertSame(FilterOp::Gt, $f->op);
        self::assertSame(100, $f->value);
    }

    #[Test]
    public function parsesInFilterWithArray(): void
    {
        $q = $this->parser->parse('filter=status in ["published","draft"]');
        $f = $q->filters[0];
        assert($f instanceof FilterNode);
        self::assertSame(FilterOp::In, $f->op);
        self::assertSame(['published', 'draft'], $f->value);
    }

    #[Test]
    public function parsesNullOperatorWithoutValue(): void
    {
        $q = $this->parser->parse('filter=parentId null');
        $f = $q->filters[0];
        assert($f instanceof FilterNode);
        self::assertSame(FilterOp::Null, $f->op);
        self::assertNull($f->value);
    }

    #[Test]
    public function pipeCreatesOrNode(): void
    {
        $q = $this->parser->parse('filter=title cn "a"|description cn "b"');
        self::assertCount(1, $q->filters);
        self::assertInstanceOf(OrNode::class, $q->filters[0]);
        self::assertCount(2, $q->filters[0]->nodes);
    }

    #[Test]
    public function multipleFiltersAreAndCombined(): void
    {
        $q = $this->parser->parse('filter=status eq "published"&filter=price gt 100');
        self::assertCount(2, $q->filters);
        self::assertInstanceOf(FilterNode::class, $q->filters[0]);
        self::assertInstanceOf(FilterNode::class, $q->filters[1]);
    }

    #[Test]
    public function parsesSortAscending(): void
    {
        $q = $this->parser->parse('sort=createdAt');
        self::assertCount(1, $q->sort);
        self::assertSame('createdAt', $q->sort[0]->field);
        self::assertSame(SortDirection::Asc, $q->sort[0]->direction);
    }

    #[Test]
    public function parsesSortDescending(): void
    {
        $q = $this->parser->parse('sort=-createdAt');
        self::assertSame(SortDirection::Desc, $q->sort[0]->direction);
    }

    #[Test]
    public function parsesMultipleSortFields(): void
    {
        $q = $this->parser->parse('sort=-createdAt,title');
        self::assertCount(2, $q->sort);
    }

    #[Test]
    public function limitIsCappedAtMax(): void
    {
        $q = $this->parser->parse('limit=99999');
        self::assertSame(RqlQuery::MAX_LIMIT, $q->limit);
    }

    #[Test]
    public function pageMinimumIsOne(): void
    {
        $q = $this->parser->parse('page=0');
        self::assertSame(1, $q->page);
    }

    #[Test]
    public function extrasDotFieldDetected(): void
    {
        $q = $this->parser->parse('filter=extras.price gt 100');
        $f = $q->filters[0];
        assert($f instanceof FilterNode);
        self::assertTrue($f->isJsonField());
        self::assertSame('price', $f->jsonKey());
    }

    #[Test]
    public function unknownOperatorThrows(): void
    {
        $this->expectException(RqlParseException::class);
        // (void): the call is expected to THROW, so its return is intentionally
        // discarded — which is exactly what #[NoDiscard] asks callers to say.
        (void) $this->parser->parse('filter=title xyz "hello"');
    }

    protected function setUp(): void
    {
        $this->parser = new RqlParser();
    }
}
