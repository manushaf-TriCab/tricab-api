<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Finance\Access;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use TriCab\Api\Finance\Access\InMemoryFinancePlatformUserLookup;
use TriCab\Api\Finance\Access\InMemoryFinancePortalUserMap;
use TriCab\Core\Finance\Shared\Access\FinancePortalUser;
use TriCab\Core\Finance\Shared\Access\FinancePortalUserExport;
use TriCab\Core\Finance\Shared\Access\FinancePortalUserMapping;
use TriCab\Core\Finance\Shared\Access\FinancePortalUserMatcher;

/**
 * The matching the mapping script does, against in-memory twins: portal users to platform
 * users by email and nothing else, with everything that cannot be matched listed.
 */
final class FinancePortalUserMatcherTest extends TestCase
{
    private const A = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private const B = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    private const C = 'cccccccccccccccccccccccccccccccc';

    private const D = 'dddddddddddddddddddddddddddddddd';

    private InMemoryFinancePortalUserMap $map;

    private static function at(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-10-07 02:00:00', new DateTimeZone('UTC'));
    }

    private static function user(string $id, string $email, string $name = 'Someone', bool $active = true): FinancePortalUser
    {
        return new FinancePortalUser($id, $email, $name, $active);
    }

    /** @param array<string, list<int>> $platform */
    private function matcher(array $platform): FinancePortalUserMatcher
    {
        $this->map ??= new InMemoryFinancePortalUserMap();

        return new FinancePortalUserMatcher(new InMemoryFinancePlatformUserLookup($platform), $this->map);
    }

    protected function setUp(): void
    {
        $this->map = new InMemoryFinancePortalUserMap();
    }

    public function test_a_portal_user_is_matched_by_email_and_one_with_no_platform_user_is_listed(): void
    {
        $plan = $this->matcher(['jane@tricab.com' => [11]])->plan([
            self::user(self::A, 'jane@tricab.com', 'Jane'),
            self::user(self::B, 'gone@tricab.com', 'Gone Away', false),
        ]);

        self::assertSame([['portalUserId' => self::A, 'email' => 'jane@tricab.com', 'name' => 'Jane', 'platformUserId' => 11]], $plan->toMap);
        self::assertSame([['portalUserId' => self::B, 'email' => 'gone@tricab.com', 'name' => 'Gone Away', 'active' => false]], $plan->unmatched);
        self::assertSame(['read' => 2, 'toMap' => 1, 'alreadyMapped' => 0, 'unmatched' => 1, 'conflicts' => 0, 'rejected' => 0], $plan->counts());
        self::assertSame([], $this->map->all(), 'planning writes nothing');
    }

    public function test_applying_writes_the_matches_and_running_again_changes_nothing(): void
    {
        $matcher = $this->matcher(['jane@tricab.com' => [11], 'bob@tricab.com' => [12]]);
        $users = [self::user(self::A, 'jane@tricab.com'), self::user(self::B, 'bob@tricab.com'), self::user(self::C, 'nobody@tricab.com')];

        self::assertSame(2, $matcher->apply($matcher->plan($users), self::at()));
        self::assertSame(11, $this->map->platformUserIdFor(self::A));
        self::assertSame(12, $this->map->platformUserIdFor(self::B));
        self::assertNull($this->map->platformUserIdFor(self::C));
        self::assertSame('jane@tricab.com', $this->map->all()[self::A]->recordedEmail);

        $again = $matcher->plan($users);
        self::assertSame(2, count($again->alreadyMapped));
        self::assertSame(0, $matcher->apply($again, self::at()));
        self::assertCount(2, $this->map->all());
    }

    public function test_an_email_held_by_two_platform_users_is_a_conflict_and_nobody_is_guessed(): void
    {
        $plan = $this->matcher(['twin@tricab.com' => [21, 22]])->plan([self::user(self::A, 'twin@tricab.com')]);

        self::assertSame([], $plan->toMap);
        self::assertCount(1, $plan->conflicts);
        self::assertStringContainsString('More than one platform user', $plan->conflicts[0]['reason']);
    }

    public function test_two_portal_users_cannot_claim_one_platform_user(): void
    {
        $plan = $this->matcher(['a@tricab.com' => [31], 'b@tricab.com' => [31]])->plan([
            self::user(self::A, 'a@tricab.com'),
            self::user(self::B, 'b@tricab.com'),
        ]);

        self::assertSame([self::A], array_column($plan->toMap, 'portalUserId'));
        self::assertSame([self::B], array_column($plan->conflicts, 'portalUserId'));
        self::assertStringContainsString(self::A, $plan->conflicts[0]['reason']);
    }

    public function test_a_platform_user_already_mapped_from_another_portal_user_is_a_conflict(): void
    {
        $this->map->add(new FinancePortalUserMapping(self::D, 'old@tricab.com', 41, self::at()));

        $plan = $this->matcher(['new@tricab.com' => [41]])->plan([self::user(self::A, 'new@tricab.com')]);

        self::assertSame([], $plan->toMap);
        self::assertSame([self::A], array_column($plan->conflicts, 'portalUserId'));
    }

    public function test_a_portal_user_mapped_before_to_someone_else_is_reported_and_left_alone(): void
    {
        $this->map->add(new FinancePortalUserMapping(self::A, 'jane@tricab.com', 11, self::at()));

        $matcher = $this->matcher(['jane@tricab.com' => [99]]);
        $plan = $matcher->plan([self::user(self::A, 'jane@tricab.com')]);

        self::assertSame([self::A], array_column($plan->conflicts, 'portalUserId'));
        self::assertSame(0, $matcher->apply($plan, self::at()));
        self::assertSame(11, $this->map->platformUserIdFor(self::A));
    }

    public function test_the_map_refuses_a_second_row_for_a_portal_user_or_a_platform_user(): void
    {
        self::assertTrue($this->map->add(new FinancePortalUserMapping(self::A, 'a@tricab.com', 1, self::at())));
        self::assertFalse($this->map->add(new FinancePortalUserMapping(self::A, 'a@tricab.com', 2, self::at())));
        self::assertFalse($this->map->add(new FinancePortalUserMapping(self::B, 'b@tricab.com', 1, self::at())));
        self::assertSame(1, $this->map->platformUserIdFor(self::A));
    }

    public function test_an_export_in_json_is_read_with_its_unusable_rows_reported(): void
    {
        $json = json_encode(['users' => [
            ['id' => self::A, 'email' => ' Jane@Tricab.com ', 'full_name' => 'Jane', 'is_active' => 1],
            ['id' => self::B, 'email' => 'bob@tricab.com', 'full_name' => 'Bob', 'is_active' => 0],
            ['id' => '', 'email' => 'noid@tricab.com'],
            ['id' => self::C, 'email' => 'not an email'],
            ['id' => self::A, 'email' => 'dup@tricab.com'],
            ['id' => 'bad id!', 'email' => 'x@tricab.com'],
        ]], JSON_THROW_ON_ERROR);

        $export = FinancePortalUserExport::parse($json, 'json');

        self::assertSame(['jane@tricab.com', 'bob@tricab.com'], array_map(static fn (FinancePortalUser $u): string => $u->email, $export['users']));
        self::assertTrue($export['users'][0]->active);
        self::assertFalse($export['users'][1]->active);
        self::assertSame([3, 4, 5, 6], array_column($export['rejected'], 'row'));
    }

    public function test_an_export_in_csv_with_a_byte_order_mark_and_quoted_names_is_read(): void
    {
        $csv = "\xEF\xBB\xBFid,email,full_name,is_active\r\n" . self::A . ",jane@tricab.com,\"Smith, Jane\",true\r\n" . self::B . ",bob@tricab.com,Bob,false\r\n\r\n";

        $export = FinancePortalUserExport::parse($csv, 'csv');

        self::assertCount(2, $export['users']);
        self::assertSame('Smith, Jane', $export['users'][0]->name);
        self::assertFalse($export['users'][1]->active);
        self::assertSame([], $export['rejected']);
    }

    public function test_a_file_that_is_not_an_export_gives_no_users(): void
    {
        self::assertSame(['users' => [], 'rejected' => []], FinancePortalUserExport::parse('not json', 'json'));
        self::assertSame(['users' => [], 'rejected' => []], FinancePortalUserExport::parse('', 'csv'));
    }
}
