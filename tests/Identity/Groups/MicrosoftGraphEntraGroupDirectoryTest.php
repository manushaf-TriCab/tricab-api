<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Identity\Groups;

use PHPUnit\Framework\TestCase;
use TriCab\Api\Identity\Groups\EntraGroupNotFound;
use TriCab\Api\Identity\Groups\GraphNotConfigured;
use TriCab\Api\Identity\Groups\GraphPermissionNotGranted;
use TriCab\Api\Identity\Groups\MicrosoftGraphEntraGroupDirectory;
use TriCab\Api\Tests\Doubles\FakeJsonHttpClient;

/** The Graph reader against a scripted HTTP client: read-only, paged, and typed when consent is missing. */
final class MicrosoftGraphEntraGroupDirectoryTest extends TestCase
{
    private const TOKEN = ['status' => 200, 'body' => '{"access_token":"t","expires_in":3600}'];

    public function test_an_unconfigured_reader_says_so_without_calling_graph(): void
    {
        $http = new FakeJsonHttpClient();

        try {
            (new MicrosoftGraphEntraGroupDirectory($http, '', '', ''))->searchMailGroups('fin');
            self::fail('Expected GraphNotConfigured.');
        } catch (GraphNotConfigured $e) {
            self::assertSame('graph_not_configured', $e->outcome());
        }
        self::assertSame([], $http->calls);
    }

    public function test_graph_403_before_consent_is_the_typed_permission_error(): void
    {
        $http = new FakeJsonHttpClient();
        $http->queue = [self::TOKEN, ['status' => 403, 'body' => '{"error":{"code":"Authorization_RequestDenied"}}']];

        try {
            $this->reader($http)->transitiveMembers('g1');
            self::fail('Expected GraphPermissionNotGranted.');
        } catch (GraphPermissionNotGranted $e) {
            self::assertSame('permission_not_granted', $e->outcome());
            self::assertStringContainsString('GroupMember.Read.All', $e->getMessage());
        }
    }

    public function test_members_are_read_transitively_page_by_page_users_only(): void
    {
        $http = new FakeJsonHttpClient();
        $http->queue = [
            self::TOKEN,
            ['status' => 200, 'body' => json_encode([
                'value' => [['id' => 'u1', 'displayName' => 'Ann', 'mail' => 'ann@tricab.com', 'userPrincipalName' => 'ann@tricab.com', 'accountEnabled' => true]],
                '@odata.nextLink' => 'https://graph.microsoft.com/v1.0/next',
            ], JSON_THROW_ON_ERROR)],
            ['status' => 200, 'body' => json_encode([
                'value' => [['id' => 'u2', 'displayName' => 'Bob', 'mail' => null, 'userPrincipalName' => 'Bob@tricab.com', 'accountEnabled' => false]],
            ], JSON_THROW_ON_ERROR)],
        ];

        $members = $this->reader($http)->transitiveMembers('g1');

        self::assertSame(['u1', 'u2'], array_map(static fn ($m): string => $m->objectId, $members));
        self::assertFalse($members[1]->accountEnabled);
        self::assertSame(['bob@tricab.com'], $members[1]->emails());
        self::assertStringContainsString('/groups/g1/transitiveMembers/microsoft.graph.user', $http->calls[1]['url']);
        self::assertSame('https://graph.microsoft.com/v1.0/next', $http->calls[2]['url']);
        foreach ($http->calls as $call) {
            self::assertContains($call['kind'], ['get', 'form'], 'read-only: no write call');
        }
    }

    public function test_a_missing_group_is_null_for_find_and_not_found_for_members(): void
    {
        $http = new FakeJsonHttpClient();
        $http->queue = [self::TOKEN, ['status' => 404, 'body' => '{}'], ['status' => 404, 'body' => '{}']];
        $reader = $this->reader($http);

        self::assertNull($reader->findGroup('gone'));
        $this->expectException(EntraGroupNotFound::class);
        $reader->transitiveMembers('gone');
    }

    public function test_search_asks_for_mail_enabled_groups_with_the_advanced_query_header(): void
    {
        $http = new FakeJsonHttpClient();
        $http->queue = [self::TOKEN, ['status' => 200, 'body' => json_encode(['value' => [
            ['id' => 'g1', 'displayName' => 'Finance Controls', 'mail' => 'fc@tricab.com', 'description' => null],
        ]], JSON_THROW_ON_ERROR)]];

        $found = $this->reader($http)->searchMailGroups('Finance "x"');

        self::assertSame('Finance Controls', $found[0]->displayName);
        self::assertSame('eventual', $http->calls[1]['headers']['ConsistencyLevel']);
        self::assertStringContainsString(rawurlencode('mailEnabled eq true'), $http->calls[1]['url']);
        self::assertStringNotContainsString('%22x%22', $http->calls[1]['url'], 'quotes in the term are dropped');
    }

    private function reader(FakeJsonHttpClient $http): MicrosoftGraphEntraGroupDirectory
    {
        return new MicrosoftGraphEntraGroupDirectory($http, 'tenant', 'client', 'secret');
    }
}
