<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Product\Persistence;

use PHPUnit\Framework\TestCase;
use TriCab\Api\Product\Persistence\AzureSqlProductSearchRepository;
use TriCab\Api\Tests\Doubles\FakeSqlConnection;
use TriCab\Core\Product\Application\DTO\ProductSearchCriteria;

/**
 * The free-text half of product search used to bind one '%whole query%' against
 * every column, so any query of more than one word matched nothing unless a single
 * column happened to contain the whole phrase. These pin the replacement.
 */
final class AzureSqlProductSearchTokenisationTest extends TestCase
{
    public function test_each_token_gets_its_own_column_or_block(): void
    {
        $connection = new FakeSqlConnection();
        $connection->queueFetch([['Total' => 0]]);
        $connection->queueFetch([]);

        $repository = new AzureSqlProductSearchRepository($connection);
        $repository->search(new ProductSearchCriteria(query: 'marine lszh cable'));

        $sql = $connection->calls[0]['sql'];
        $params = $connection->calls[0]['params'];

        self::assertSame('%marine%', $params[':t0Name']);
        self::assertSame('%lszh%', $params[':t1Name']);
        self::assertSame('%cable%', $params[':t2Name']);
        self::assertSame(3, substr_count($sql, 'p.[ProductName] LIKE :t'));
        // Three token blocks joined by AND inside a single OR arm.
        self::assertSame(2, substr_count($sql, "\n    AND ("));
    }

    public function test_the_whole_query_is_still_tried_as_an_identity_match(): void
    {
        $connection = new FakeSqlConnection();
        $connection->queueFetch([['Total' => 0]]);
        $connection->queueFetch([]);

        $repository = new AzureSqlProductSearchRepository($connection);
        $repository->search(new ProductSearchCriteria(query: 'BHA-PATN/4C4RE'));

        $params = $connection->calls[0]['params'];

        self::assertSame('BHA-PATN/4C4RE', $params[':exactInternal']);
        self::assertSame('BHA-PATN/4C4RE%', $params[':prefixInternal']);
        self::assertSame('BHA-PATN/4C4RE', $params[':exactSlug']);
        self::assertSame('%BHA-PATN/4C4RE%', $params[':t0Code']);
    }

    public function test_repeated_tokens_are_bound_once(): void
    {
        $connection = new FakeSqlConnection();
        $connection->queueFetch([['Total' => 0]]);
        $connection->queueFetch([]);

        $repository = new AzureSqlProductSearchRepository($connection);
        $repository->search(new ProductSearchCriteria(query: 'cable cable'));

        $sql = $connection->calls[0]['sql'];

        self::assertSame(1, substr_count($sql, 'p.[ProductName] LIKE :t'));
        self::assertArrayNotHasKey(':t1Name', $connection->calls[0]['params']);
    }

    public function test_like_wildcards_are_escaped_so_a_stray_percent_does_not_widen_the_search(): void
    {
        $connection = new FakeSqlConnection();
        $connection->queueFetch([['Total' => 0]]);
        $connection->queueFetch([]);

        $repository = new AzureSqlProductSearchRepository($connection);
        $repository->search(new ProductSearchCriteria(query: '50%'));

        $params = $connection->calls[0]['params'];

        self::assertSame('%50~%%', $params[':t0Name']);
        self::assertSame('50~%%', $params[':prefixInternal']);
        // The exact comparison is not a LIKE, so it keeps the raw text.
        self::assertSame('50%', $params[':exactInternal']);
    }

    public function test_region_and_status_scopes_survive_the_rewrite(): void
    {
        $connection = new FakeSqlConnection();
        $connection->queueFetch([['Total' => 0]]);
        $connection->queueFetch([]);

        $repository = new AzureSqlProductSearchRepository($connection);
        $repository->search(new ProductSearchCriteria(
            query: 'cable',
            regionCode: 'AU',
            publishedOnly: true,
            statusCodes: ['published', 'draft'],
        ));

        $sql = $connection->calls[0]['sql'];
        $params = $connection->calls[0]['params'];

        self::assertStringContainsString("ps.[StatusCode] = N'published'", $sql);
        self::assertStringContainsString('LOWER(ps.[StatusCode]) IN (:allowedStatus0, :allowedStatus1)', $sql);
        self::assertStringContainsString('[product].[ProductRegions]', $sql);
        self::assertSame('AU', $params[':regionCode']);
    }
}
