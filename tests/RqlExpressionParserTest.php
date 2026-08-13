<?php

declare(strict_types=1);

namespace CoolMS\Rql\Tests;

use CoolMS\Rql\AndNode;
use CoolMS\Rql\Exception\RqlParseException;
use CoolMS\Rql\FilterNode;
use CoolMS\Rql\FilterOp;
use CoolMS\Rql\OrNode;
use CoolMS\Rql\RqlExpressionParser;
use CoolMS\Rql\RqlQuery;
use CoolMS\Rql\SortDirection;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Persvr-style RQL grammar (`eq(foo,3)&lt(price,10)`) -> the SAME RqlQuery AST
 * the classic DSL emits -- two parsers, one AST.
 */
final class RqlExpressionParserTest extends TestCase
{
    private RqlExpressionParser $parser;

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
    public function parsesSingleComparison(): void
    {
        $q = $this->parser->parse('eq(title,"hello world")');
        self::assertCount(1, $q->filters);
        $f = $q->filters[0];
        self::assertInstanceOf(FilterNode::class, $f);
        self::assertSame('title', $f->field);
        self::assertSame(FilterOp::Eq, $f->op);
        self::assertSame('hello world', $f->value);
    }

    #[Test]
    public function topLevelAmpersandIsImplicitAnd(): void
    {
        // The user's canonical example.
        $q = $this->parser->parse('eq(foo,3)&lt(price,10)');
        self::assertCount(2, $q->filters);

        $foo = $q->filters[0];
        assert($foo instanceof FilterNode);
        self::assertSame('foo', $foo->field);
        self::assertSame(FilterOp::Eq, $foo->op);
        self::assertSame(3, $foo->value);

        $price = $q->filters[1];
        assert($price instanceof FilterNode);
        self::assertSame('price', $price->field);
        self::assertSame(FilterOp::Lt, $price->op);
        self::assertSame(10, $price->value);
    }

    #[Test]
    public function parsesFloatAndBoolAndNullLiterals(): void
    {
        $q = $this->parser->parse('eq(rate,12.5)&eq(active,true)&eq(hidden,false)');
        self::assertCount(3, $q->filters);
        $vals = [];
        foreach ($q->filters as $f) {
            self::assertInstanceOf(FilterNode::class, $f);
            $vals[] = $f->value;
        }
        self::assertSame([12.5, true, false], $vals);
    }

    #[Test]
    public function orGroupBecomesOrNode(): void
    {
        $q = $this->parser->parse('or(eq(a,1),eq(b,2))');
        self::assertCount(1, $q->filters);
        $or = $q->filters[0];
        self::assertInstanceOf(OrNode::class, $or);
        self::assertCount(2, $or->nodes);
    }

    #[Test]
    public function andGroupBecomesAndNode(): void
    {
        $q = $this->parser->parse('and(eq(a,1),eq(b,2))');
        self::assertCount(1, $q->filters);
        $and = $q->filters[0];
        self::assertInstanceOf(AndNode::class, $and);
        self::assertCount(2, $and->nodes);
    }

    #[Test]
    public function nestedGroupsAreArbitrarilyNestable(): void
    {
        // or(and(eq(a,1),eq(b,2)), eq(c,3))
        $q = $this->parser->parse('or(and(eq(a,1),eq(b,2)),eq(c,3))');
        $or = $q->filters[0];
        self::assertInstanceOf(OrNode::class, $or);
        self::assertCount(2, $or->nodes);
        self::assertInstanceOf(AndNode::class, $or->nodes[0]);
        self::assertCount(2, $or->nodes[0]->nodes);
        self::assertInstanceOf(FilterNode::class, $or->nodes[1]);
    }

    #[Test]
    public function inAcceptsParenthesisedList(): void
    {
        $q = $this->parser->parse('in(status,("published","draft"))');
        $f = $q->filters[0];
        assert($f instanceof FilterNode);
        self::assertSame(FilterOp::In, $f->op);
        self::assertSame(['published', 'draft'], $f->value);
    }

    #[Test]
    public function inAcceptsFlatMultiArgList(): void
    {
        $q = $this->parser->parse('in(age,25,35)');
        $f = $q->filters[0];
        assert($f instanceof FilterNode);
        self::assertSame(FilterOp::In, $f->op);
        self::assertSame([25, 35], $f->value);
    }

    #[Test]
    public function outAliasMapsToNi(): void
    {
        $q = $this->parser->parse('out(age,25,35)');
        $f = $q->filters[0];
        assert($f instanceof FilterNode);
        self::assertSame(FilterOp::Ni, $f->op);
        self::assertSame([25, 35], $f->value);
    }

    #[Test]
    public function containsAliasMapsToCn(): void
    {
        $q = $this->parser->parse('contains(title,"news")');
        $f = $q->filters[0];
        assert($f instanceof FilterNode);
        self::assertSame(FilterOp::Cn, $f->op);
        self::assertSame('news', $f->value);
    }

    #[Test]
    public function nullOperatorTakesNoValue(): void
    {
        $q = $this->parser->parse('null(deletedAt)');
        $f = $q->filters[0];
        assert($f instanceof FilterNode);
        self::assertSame(FilterOp::Null, $f->op);
        self::assertNull($f->value);
    }

    #[Test]
    public function eqNullNormalisesToIsNull(): void
    {
        $q = $this->parser->parse('eq(deletedAt,null)');
        $f = $q->filters[0];
        assert($f instanceof FilterNode);
        self::assertSame(FilterOp::Null, $f->op);
        self::assertNull($f->value);
    }

    #[Test]
    public function neNullNormalisesToIsNotNull(): void
    {
        $q = $this->parser->parse('ne(deletedAt,null)');
        $f = $q->filters[0];
        assert($f instanceof FilterNode);
        self::assertSame(FilterOp::Nn, $f->op);
        self::assertNull($f->value);
    }

    #[Test]
    public function parsesSortWithSignPrefixes(): void
    {
        $q = $this->parser->parse('sort(-createdAt,+title,name)');
        self::assertCount(3, $q->sort);
        self::assertSame('createdAt', $q->sort[0]->field);
        self::assertSame(SortDirection::Desc, $q->sort[0]->direction);
        self::assertSame('title', $q->sort[1]->field);
        self::assertSame(SortDirection::Asc, $q->sort[1]->direction);
        self::assertSame('name', $q->sort[2]->field);
        self::assertSame(SortDirection::Asc, $q->sort[2]->direction);
    }

    #[Test]
    public function limitCountOnly(): void
    {
        $q = $this->parser->parse('limit(15)');
        self::assertSame(15, $q->limit);
        self::assertSame(1, $q->page);
    }

    #[Test]
    public function limitCountAndStartMapsToPage(): void
    {
        // start=40, limit=20 -> page 3 (rows 40-59).
        $q = $this->parser->parse('limit(20,40)');
        self::assertSame(20, $q->limit);
        self::assertSame(3, $q->page);
    }

    #[Test]
    public function limitIsCappedAtMax(): void
    {
        $q = $this->parser->parse('limit(99999)');
        self::assertSame(RqlQuery::MAX_LIMIT, $q->limit);
    }

    #[Test]
    public function pageTerm(): void
    {
        $q = $this->parser->parse('page(4)');
        self::assertSame(4, $q->page);
    }

    #[Test]
    public function quotedValueMayContainCommaAndAmpersand(): void
    {
        $q = $this->parser->parse('eq(title,"a, b & c")');
        $f = $q->filters[0];
        assert($f instanceof FilterNode);
        self::assertSame('a, b & c', $f->value);
    }

    #[Test]
    public function urlEncodedTermsAreDecoded(): void
    {
        // eq(title,"hello world") url-encoded.
        $q = $this->parser->parse('eq(title,%22hello%20world%22)');
        $f = $q->filters[0];
        assert($f instanceof FilterNode);
        self::assertSame('hello world', $f->value);
    }

    #[Test]
    public function extrasDotFieldIsPreserved(): void
    {
        $q = $this->parser->parse('gt(extras.price,100)');
        $f = $q->filters[0];
        assert($f instanceof FilterNode);
        self::assertTrue($f->isJsonField());
        self::assertSame('price', $f->jsonKey());
    }

    #[Test]
    public function unknownOperatorThrows(): void
    {
        $this->expectException(RqlParseException::class);
        (void) $this->parser->parse('xyz(title,1)');
    }

    #[Test]
    public function malformedTermThrows(): void
    {
        $this->expectException(RqlParseException::class);
        (void) $this->parser->parse('eq title 1');
    }

    protected function setUp(): void
    {
        $this->parser = new RqlExpressionParser();
    }
}
