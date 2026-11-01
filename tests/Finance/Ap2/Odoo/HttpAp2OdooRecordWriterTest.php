<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Finance\Ap2\Odoo;

use PHPUnit\Framework\TestCase;
use TriCab\Api\Finance\Ap2\Odoo\HttpAp2OdooRecordWriter;
use TriCab\Api\Tests\Doubles\FakeJsonHttpClient;
use TriCab\Core\Finance\Ap2\Odoo\Ap2OdooPostingDisabled;
use TriCab\Core\Finance\Shared\Odoo\OdooError;

/** The one write path: nothing leaves while switched off; JSON-2 `create` with the key in one header only. */
final class HttpAp2OdooRecordWriterTest extends TestCase
{
    private FakeJsonHttpClient $http;

    protected function setUp(): void
    {
        $this->http = new FakeJsonHttpClient();
        $this->http->queue = [];
    }

    private function writer(bool $enabled = true): HttpAp2OdooRecordWriter
    {
        return new HttpAp2OdooRecordWriter($this->http, 'https://odoo-staging.example/', 'secret-key', [2, 4], $enabled);
    }

    public function testSwitchedOffNothingIsSent(): void
    {
        $this->expectException(Ap2OdooPostingDisabled::class);
        try {
            $this->writer(false)->create('account.move', ['move_type' => 'in_invoice']);
        } finally {
            self::assertSame([], $this->http->calls);
        }
    }

    public function testCreateCallsJson2AndAnswersTheId(): void
    {
        $this->http->queue[] = ['status' => 200, 'body' => '[812]'];

        self::assertSame(812, $this->writer()->create('account.move', ['move_type' => 'in_invoice', 'ref' => 'CM-2200']));

        $call = $this->http->calls[0];
        self::assertSame('https://odoo-staging.example/json/2/account.move/create', $call['url']);
        self::assertSame(['Authorization' => 'bearer secret-key'], $call['headers']);
        self::assertSame(['vals_list' => [['move_type' => 'in_invoice', 'ref' => 'CM-2200']], 'context' => ['allowed_company_ids' => [2, 4]]], $call['payload']);

        $this->http->queue[] = ['status' => 200, 'body' => '813'];
        self::assertSame(813, $this->writer()->create('ir.model.data', ['name' => 'triap_x']), 'a bare id is accepted too');
    }

    public function testOnlyBillsAndTheirExternalIdsMayBeCreated(): void
    {
        $this->expectException(OdooError::class);
        $this->expectExceptionMessage('Tri-AP does not create res.partner.bank records in Odoo.');
        $this->writer()->create('res.partner.bank', ['acc_number' => '1']);
    }

    public function testAnOdooErrorNamesTheStatusButNotTheKey(): void
    {
        $this->http->queue[] = ['status' => 403, 'body' => '{"name":"odoo.exceptions.AccessError","message":"You are not allowed to create \'Journal Entry\' (account.move) records.\nsecret"}'];

        try {
            $this->writer()->create('account.move', []);
            self::fail('expected an error');
        } catch (OdooError $e) {
            self::assertStringStartsWith('Odoo account.move.create returned HTTP 403: You are not allowed to create', $e->getMessage());
            self::assertStringNotContainsString('secret-key', $e->getMessage());
            self::assertStringNotContainsString("\n", $e->getMessage());
        }

        $this->http->queue[] = ['status' => 200, 'body' => '{"id": 5}'];
        $this->expectException(OdooError::class);
        $this->expectExceptionMessage('did not answer the new record id');
        $this->writer()->create('account.move', []);
    }
}
