<?php

declare(strict_types=1);

namespace CoolMS\Rql\Tests;

use CoolMS\Rql\Exception\RqlSecurityException;
use CoolMS\Rql\RqlContext;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * ORDER BY has its own whitelist, because sortable and filterable are
 * independent capabilities everywhere these contexts are built from.
 *
 * The bug: `RqlContext` exposed ONE `resolve()`, and every grid-derived context
 * filled its whitelist from the columns that declared a filter operator. A
 * column declared `sortable: true, filterable: false` was therefore absent from
 * it, so the very sort the FE column header advertised came back
 * `400 Field "memberCount" is not available for filtering.`
 *
 * The fix has to be one-directional: `$sortableFields` may only ADD. If it
 * subtracted -- "sortable means exactly the sortable list" -- then every field
 * that is filterable but not declared sortable would stop being orderable, and
 * a fix for a broken header would silently break working queries instead.
 */
final class RqlContextSortTest extends TestCase
{
    #[Test]
    public function aSortableOnlyFieldResolvesForSortingButNotForFiltering(): void
    {
        $ctx = new RqlContext(
            entityAlias: 'g',
            allowedFields: ['name'],
            sortableFields: ['memberCount'],
        );

        self::assertSame('g.memberCount', $ctx->resolveSort('memberCount'));

        // The column has no filter operator to offer, so filtering stays refused.
        $this->expectException(RqlSecurityException::class);
        (void) $ctx->resolve('memberCount');
    }

    /**
     * The one-directional guarantee, asserted rather than assumed: populating
     * `$sortableFields` must not turn the filter whitelist into the sort
     * whitelist. `identity:users` alone has five fields that are filterable
     * with `sortable: false` in the YAML.
     */
    #[Test]
    public function everyFilterableFieldStaysSortableEvenWhenSortableFieldsIsPopulated(): void
    {
        $ctx = new RqlContext(
            entityAlias: 'u',
            allowedFields: ['isActive', 'createdAt'],
            sortableFields: ['displayName'],
        );

        self::assertSame('u.isActive', $ctx->resolveSort('isActive'));
        self::assertSame('u.createdAt', $ctx->resolveSort('createdAt'));
        self::assertSame('u.displayName', $ctx->resolveSort('displayName'));
    }

    #[Test]
    public function sortFieldMapOverlaysFieldMapForSortingOnly(): void
    {
        $ctx = new RqlContext(
            entityAlias: 'g',
            allowedFields: ['label'],
            fieldMap: ['label' => 'g.label'],
            sortableFields: ['memberCount'],
            // The alias of a HIDDEN scalar select: valid in ORDER BY, and
            // deliberately NOT prefixed with the entity alias.
            sortFieldMap: ['memberCount' => 'memberCount'],
        );

        self::assertSame('memberCount', $ctx->resolveSort('memberCount'));
        self::assertSame('g.label', $ctx->resolveSort('label'), 'fieldMap still applies where sortFieldMap is silent');
    }

    #[Test]
    public function anUndeclaredFieldIsStillRefusedForSorting(): void
    {
        $ctx = new RqlContext(entityAlias: 'g', allowedFields: ['name'], sortableFields: ['memberCount']);

        $this->expectException(RqlSecurityException::class);
        (void) $ctx->resolveSort('password');
    }

    /**
     * The message names the capability that was refused. A rejected `sort=`
     * used to report the field as unavailable "for filtering", which sends
     * whoever reads the 400 to the wrong half of the grid config -- precisely
     * the wrong turn this bug's diagnosis had to walk back.
     */
    #[Test]
    public function theRefusalNamesSortingRatherThanFiltering(): void
    {
        $ctx = new RqlContext(entityAlias: 'g', allowedFields: ['name']);

        try {
            (void) $ctx->resolveSort('memberCount');
            self::fail('an undeclared sort field must be refused');
        } catch (RqlSecurityException $e) {
            self::assertSame('Field "memberCount" is not available for sorting.', $e->getMessage());
        }

        try {
            (void) $ctx->resolve('memberCount');
            self::fail('an undeclared filter field must be refused');
        } catch (RqlSecurityException $e) {
            self::assertSame('Field "memberCount" is not available for filtering.', $e->getMessage());
        }
    }

    /**
     * The injection guards are on the shared resolution path, not on the filter
     * branch of it -- a sort field is interpolated straight into ORDER BY.
     */
    #[Test]
    public function sortingKeepsTheTraversalDepthAndWhitelistGuards(): void
    {
        $ctx = new RqlContext(
            entityAlias: 'g',
            allowedFields: ['groups.id'],
            sortableFields: ['a.b.c'],
        );

        self::assertSame('groups.id', $ctx->resolveSort('groups.id'), 'single-level traversal is still allowed');

        // Whitelisted or not, depth > 1 never resolves.
        $this->expectException(RqlSecurityException::class);
        (void) $ctx->resolveSort('a.b.c');
    }

    #[Test]
    public function extrasSortingHonoursTheWildcardWhitelist(): void
    {
        $wildcard = new RqlContext(entityAlias: 'r', allowedFields: ['extras.*']);
        self::assertSame('extras.price', $wildcard->resolveSort('extras.price'));

        $narrow = new RqlContext(entityAlias: 'r', allowedFields: ['extras.price']);
        self::assertSame('extras.price', $narrow->resolveSort('extras.price'));

        $this->expectException(RqlSecurityException::class);
        (void) $narrow->resolveSort('extras.secret');
    }

    /**
     * Every context built before this change passed no `$sortableFields`, and
     * every one of them must keep sorting exactly what it sorted before.
     */
    #[Test]
    public function aContextWithoutSortableFieldsSortsItsFilterWhitelist(): void
    {
        $ctx = new RqlContext(
            entityAlias: 'n',
            allowedFields: ['title', 'sortOrder'],
            fieldMap: ['title' => 'n.title', 'sortOrder' => 'n.sortOrder'],
        );

        self::assertSame('n.title', $ctx->resolveSort('title'));
        self::assertSame('n.sortOrder', $ctx->resolveSort('sortOrder'));
    }
}
