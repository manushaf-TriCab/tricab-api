<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Finance\Odoo;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use TriCab\Api\Finance\Odoo\HttpOdooClient;
use TriCab\Api\Finance\Odoo\UndeployedOdooClient;
use TriCab\Api\Tests\Doubles\FakeJsonHttpClient;
use TriCab\Core\Finance\Shared\Odoo\OdooError;

/**
 * The JSON-2 client against a fake HTTP client: no Odoo, no network, and a dummy key that
 * the tests then search every error for.
 */
final class HttpOdooClientTest extends TestCase
{
    private const KEY = 'dummy-odoo-key-do-not-leak';

    private FakeJsonHttpClient $http;

    protected function setUp(): void
    {
        $this->http = new FakeJsonHttpClient();
        $this->http->queue = [];
    }

    private function client(): HttpOdooClient
    {
        return new HttpOdooClient($this->http, 'https://odoo.example.test/', self::KEY, [2, 4]);
    }

    /**
     * @return array{status: int, body: string}
     */
    private static function rows(int $count, int $from = 1): array
    {
        $rows = [];
        for ($i = 0; $i < $count; $i++) {
            $rows[] = ['id' => $from + $i, 'name' => 'Record ' . ($from + $i)];
        }

        return ['status' => 200, 'body' => json_encode($rows, JSON_THROW_ON_ERROR)];
    }

    public function test_it_posts_a_search_read_with_the_key_the_context_and_the_paging(): void
    {
        $this->http->queue = [self::rows(3)];

        $rows = $this->client()->searchRead('res.partner', [['supplier_rank', '>', 0]], ['name', 'ref']);

        self::assertCount(3, $rows);
        $call = $this->http->calls[0];
        self::assertSame('https://odoo.example.test/json/2/res.partner/search_read', $call['url'], 'the trailing slash on the base URL is dropped');
        self::assertSame('bearer ' . self::KEY, $call['headers']['Authorization']);
        self::assertSame(
            [
                'domain' => [['supplier_rank', '>', 0]],
                'fields' => ['name', 'ref'],
                'limit' => 500,
                'offset' => 0,
                'order' => 'id',
                'context' => ['allowed_company_ids' => [2, 4], 'active_test' => false],
            ],
            $call['payload'],
        );
        self::assertStringNotContainsString(self::KEY, $call['url'], 'the key is in a header and never in a URL');
    }

    public function test_it_reads_page_after_page_until_a_short_page(): void
    {
        $this->http->queue = [self::rows(500, 1), self::rows(500, 501), self::rows(7, 1001)];

        $rows = $this->client()->searchRead('res.partner', [], ['name']);

        self::assertCount(1007, $rows);
        self::assertSame([0, 500, 1000], array_map(static fn (array $call): mixed => $call['payload']['offset'] ?? null, $this->http->calls));
    }

    public function test_an_exactly_full_last_page_costs_one_more_read_and_ends_on_the_empty_one(): void
    {
        $this->http->queue = [self::rows(500, 1), ['status' => 200, 'body' => '[]']];

        self::assertCount(500, $this->client()->searchRead('res.partner', [], ['name']));
        self::assertCount(2, $this->http->calls);
    }

    public function test_an_http_error_names_the_model_the_status_and_a_clean_cut_of_odoos_message(): void
    {
        $message = "Access denied\nfor " . str_repeat('x', 400);
        $this->http->queue = [['status' => 403, 'body' => json_encode(['message' => $message], JSON_THROW_ON_ERROR)]];

        try {
            $this->client()->searchRead('account.account', [], ['code']);
            self::fail('A 403 must fail.');
        } catch (OdooError $error) {
            self::assertStringStartsWith('Odoo account.account.search_read returned HTTP 403: Access denied for xxx', $error->getMessage());
            self::assertLessThanOrEqual(80 + 200, strlen($error->getMessage()));
            self::assertStringNotContainsString("\n", $error->getMessage());
            self::assertStringNotContainsString(self::KEY, $error->getMessage());
        }
    }

    public function test_an_error_with_no_message_is_the_status_alone(): void
    {
        $this->http->queue = [['status' => 502, 'body' => '<html>bad gateway</html>']];

        $this->expectException(OdooError::class);
        $this->expectExceptionMessage('Odoo res.company.search_read returned HTTP 502');
        $this->client()->searchRead('res.company', [], ['name']);
    }

    public function test_an_unreachable_odoo_says_only_what_kind_of_failure_it_was(): void
    {
        $this->http->queue = [new RuntimeException('cURL error 28 talking to ' . self::KEY)];

        try {
            $this->client()->searchRead('res.partner', [], ['name']);
            self::fail('An unreachable Odoo must fail.');
        } catch (OdooError $error) {
            self::assertSame('Odoo res.partner.search_read unreachable: RuntimeException', $error->getMessage());
            self::assertStringNotContainsString(self::KEY, $error->getMessage());
        }
    }

    public function test_an_answer_that_is_not_a_list_of_records_is_an_error(): void
    {
        foreach (['not json', '{"result": []}', '[1, 2]', '"text"'] as $body) {
            $this->http->queue = [['status' => 200, 'body' => $body]];
            try {
                $this->client()->searchRead('res.partner', [], ['name']);
                self::fail("$body must be refused");
            } catch (OdooError $error) {
                self::assertStringContainsString('res.partner.search_read answered with', $error->getMessage());
            }
        }
    }

    public function test_the_undeployed_client_refuses_and_touches_nothing(): void
    {
        try {
            (new UndeployedOdooClient())->searchRead('res.partner', [], ['name']);
            self::fail('An unconfigured client must refuse.');
        } catch (OdooError $error) {
            self::assertSame(UndeployedOdooClient::REASON, $error->getMessage());
        }
        self::assertSame([], $this->http->calls);
    }
}
