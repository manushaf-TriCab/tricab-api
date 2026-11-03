<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Identity\Groups;

use PHPUnit\Framework\TestCase;
use TriCab\Api\Identity\Groups\EntraGroup;
use TriCab\Api\Identity\Groups\EntraMember;
use TriCab\Api\Identity\Groups\GraphPermissionNotGranted;
use TriCab\Api\Identity\Groups\GroupException;
use TriCab\Api\Identity\Groups\GroupRecord;
use TriCab\Api\Identity\Groups\GroupService;
use TriCab\Api\Identity\Groups\GroupSync;
use TriCab\Api\Identity\Groups\InMemoryEntraGroupDirectory;
use TriCab\Api\Identity\Groups\InMemoryGroupStore;
use TriCab\Api\Identity\Groups\InMemoryGroupUserDirectory;

/** The group sync and the Administrator service, over fakes (no Graph, no database). */
final class GroupSyncTest extends TestCase
{
    private const FINANCE = 'aaaaaaaa-1111-4111-8111-000000000001';

    private InMemoryGroupUserDirectory $users;
    private InMemoryGroupStore $store;
    private InMemoryEntraGroupDirectory $entra;
    private GroupSync $sync;
    private GroupService $service;

    protected function setUp(): void
    {
        $this->users = new InMemoryGroupUserDirectory();
        $this->store = new InMemoryGroupStore($this->users);
        $this->entra = new InMemoryEntraGroupDirectory();
        $this->sync = new GroupSync($this->store, $this->entra, $this->users);
        $this->service = new GroupService($this->store, $this->users, $this->entra, $this->sync);
    }

    public function test_linking_a_group_syncs_its_members_matched_by_object_id_then_email(): void
    {
        $byOid = $this->users->add('ann.personal@example.test', 'Ann', 'active', 'oid-ann');
        $byEmail = $this->users->add('bob@tricab.com', 'Bob');
        $this->entra->withGroup($this->financeGroup(), [
            $this->member('oid-ann', 'Ann', 'ann@tricab.com'),
            $this->member('oid-bob', 'Bob', 'bob@tricab.com'),
        ]);

        $linked = $this->service->link(self::FINANCE, null);

        self::assertSame(2, $linked['sync']['added']);
        self::assertSame(0, $linked['sync']['createdPending']);
        self::assertSame(2, $this->users->count(), 'both matched existing users');
        $group = $this->requireGroup();
        self::assertSame([$byOid, $byEmail], array_column($this->store->members($group->id), 'userId'));
    }

    public function test_an_entra_member_with_no_platform_account_is_created_pending_and_added_and_never_activated(): void
    {
        $this->entra->withGroup($this->financeGroup(), [$this->member('oid-new', 'New Starter', 'new.starter@tricab.com')]);

        $result = $this->service->link(self::FINANCE, null);

        self::assertSame(1, $result['sync']['createdPending']);
        $detail = $result['group'];
        self::assertSame(1, $detail['memberCount']);
        self::assertSame(1, $detail['pendingMemberCount']);
        self::assertSame('pending', $detail['members'][0]['status']);
        self::assertSame('entra', $detail['members'][0]['source']);

        // A second pass changes nothing and still does not activate anyone.
        $again = $this->sync->syncAll();
        self::assertSame(0, $again['createdPending']);
        self::assertSame(0, $again['added']);
        $described = $this->users->describe([1]);
        self::assertSame('pending', $described[1]['status']);
        self::assertSame(1, $this->users->count());
    }

    public function test_members_who_left_entra_are_removed_and_new_ones_added(): void
    {
        $this->entra->withGroup($this->financeGroup(), [$this->member('o1', 'One', 'one@tricab.com'), $this->member('o2', 'Two', 'two@tricab.com')]);
        $this->service->link(self::FINANCE, null);

        $this->entra->setMembers(self::FINANCE, [$this->member('o2', 'Two', 'two@tricab.com'), $this->member('o3', 'Three', 'three@tricab.com')]);
        $result = $this->sync->syncAll();

        self::assertSame(1, $result['added']);
        self::assertSame(1, $result['removed']);
        $emails = array_column($this->service->get($this->requireGroup()->publicId)['members'], 'emailAddress');
        sort($emails);
        self::assertSame(['three@tricab.com', 'two@tricab.com'], $emails);
    }

    public function test_a_disabled_entra_account_is_not_created_but_an_existing_user_still_is_a_member(): void
    {
        $existing = $this->users->add('left@tricab.com', 'Left');
        $this->entra->withGroup($this->financeGroup(), [
            $this->member('o-left', 'Left', 'left@tricab.com', false),
            $this->member('o-gone', 'Gone', 'gone@tricab.com', false),
        ]);

        $result = $this->service->link(self::FINANCE, null);

        self::assertSame(1, $result['sync']['skippedDisabled']);
        self::assertSame([$existing], array_column($this->store->members($this->requireGroup()->id), 'userId'));
        self::assertSame(1, $this->users->count());
    }

    public function test_sync_never_alters_platform_groups(): void
    {
        $ann = $this->users->add('ann@tricab.com', 'Ann');
        $this->users->add('bob@tricab.com', 'Bob');
        $platform = $this->service->create('Warehouse', null, null);
        $this->service->addMember($platform['publicId'], $this->users->describe([$ann])[$ann]['publicId'], null);
        $this->entra->withGroup($this->financeGroup(), [$this->member('o-bob', 'Bob', 'bob@tricab.com')]);
        $this->service->link(self::FINANCE, null);
        $this->entra->setMembers(self::FINANCE, []);

        $this->sync->syncGroup($this->requireGroup(), true);

        $platformRecord = $this->store->findByPublicId($platform['publicId']);
        self::assertInstanceOf(GroupRecord::class, $platformRecord);
        self::assertSame([['userId' => $ann, 'source' => 'platform']], array_map(
            static fn (array $m): array => ['userId' => $m['userId'], 'source' => $m['source']],
            $this->store->members($platformRecord->id),
        ));
        self::assertNull($platformRecord->lastSyncedAt, 'a platform group is never even stamped as synced');
        self::assertSame([], $this->store->members($this->requireGroup()->id));
    }

    public function test_sync_removes_only_entra_rows_even_in_a_mixed_group(): void
    {
        $this->users->add('ann@tricab.com', 'Ann');
        $this->entra->withGroup($this->financeGroup(), [$this->member('o-ann', 'Ann', 'ann@tricab.com')]);
        $this->service->link(self::FINANCE, null);
        $group = $this->requireGroup();
        $hand = $this->users->add('hand@tricab.com', 'Hand');
        $this->store->addMember($group->id, $hand, GroupRecord::SOURCE_PLATFORM, null);

        $this->entra->setMembers(self::FINANCE, []);
        $this->sync->syncGroup($group, true);

        self::assertSame([$hand], array_column($this->store->members($group->id), 'userId'));
    }

    public function test_entra_linked_members_cannot_be_edited_by_hand(): void
    {
        $this->users->add('ann@tricab.com', 'Ann');
        $this->entra->withGroup($this->financeGroup(), [$this->member('o-ann', 'Ann', 'ann@tricab.com')]);
        $linked = $this->service->link(self::FINANCE, null);
        $group = $linked['group'];
        $userPublicId = $group['members'][0]['userPublicId'];

        foreach ([
            fn () => $this->service->addMember($group['publicId'], $userPublicId, null),
            fn () => $this->service->removeMember($group['publicId'], $userPublicId),
        ] as $attempt) {
            try {
                $attempt();
                self::fail('Expected a read-only refusal.');
            } catch (GroupException $e) {
                self::assertSame('GROUP_MEMBERS_READ_ONLY', $e->errorCode());
                self::assertSame(409, $e->status);
            }
        }
        self::assertTrue($group['membersReadOnly']);
        self::assertCount(1, $this->store->members($this->requireGroup()->id));
    }

    public function test_a_zero_member_read_removes_nobody_is_guarded_and_still_allows_additions(): void
    {
        $this->entra->withGroup($this->financeGroup(), [$this->member('o1', 'One', 'one@tricab.com'), $this->member('o2', 'Two', 'two@tricab.com')]);
        $this->service->link(self::FINANCE, null);
        $this->entra->setMembers(self::FINANCE, []);

        $result = $this->sync->syncAll();

        self::assertSame(0, $result['removed']);
        self::assertSame(1, $result['guarded']);
        self::assertSame('guarded', $result['outcome']);
        self::assertCount(2, $this->store->members($this->requireGroup()->id));
        $group = $this->requireGroup();
        self::assertSame('guarded', $group->lastSyncOutcome);
        self::assertStringContainsString('no one was removed', (string) $group->lastSyncMessage);

        // A short read that also brings someone new: the addition still happens.
        $this->entra->setMembers(self::FINANCE, [$this->member('o3', 'Three', 'three@tricab.com')]);
        $again = $this->sync->syncAll();
        self::assertSame(1, $again['added']);
        self::assertSame(0, $again['removed']);
        self::assertCount(3, $this->store->members($this->requireGroup()->id));
    }

    public function test_a_read_under_the_ratio_is_guarded_but_a_normal_shrink_is_not(): void
    {
        $members = [];
        foreach (['a', 'b', 'c', 'd'] as $n) {
            $members[] = $this->member('o-' . $n, $n, $n . '@tricab.com');
        }
        $this->entra->withGroup($this->financeGroup(), $members);
        $this->service->link(self::FINANCE, null);

        $this->entra->setMembers(self::FINANCE, array_slice($members, 0, 1));
        self::assertSame('guarded', $this->sync->syncAll()['outcome']);
        self::assertCount(4, $this->store->members($this->requireGroup()->id));

        $this->entra->setMembers(self::FINANCE, array_slice($members, 0, 2));
        $ok = $this->sync->syncAll();
        self::assertSame('ok', $ok['outcome'], 'exactly half is not under the ratio');
        self::assertSame(2, $ok['removed']);
        self::assertSame('ok', $this->requireGroup()->lastSyncOutcome);
    }

    public function test_sync_now_with_the_override_accepts_a_real_shrink(): void
    {
        $this->entra->withGroup($this->financeGroup(), [$this->member('o1', 'One', 'one@tricab.com'), $this->member('o2', 'Two', 'two@tricab.com')]);
        $group = $this->service->link(self::FINANCE, null)['group'];
        $this->entra->setMembers(self::FINANCE, []);

        $guarded = $this->service->syncNow($group['publicId']);
        self::assertSame('guarded', $guarded['sync']['status']);
        self::assertNotNull($guarded['sync']['warning']);
        self::assertSame(2, $guarded['group']['memberCount']);
        self::assertSame('guarded', $guarded['group']['lastSyncOutcome']);

        $accepted = $this->service->syncNow($group['publicId'], true);
        self::assertSame('ok', $accepted['sync']['status']);
        self::assertSame(2, $accepted['sync']['removed']);
        self::assertSame(0, $accepted['group']['memberCount']);
        self::assertSame('ok', $accepted['group']['lastSyncOutcome']);
    }

    public function test_a_group_deleted_in_entra_becomes_unlinked_and_keeps_its_members(): void
    {
        $this->entra->withGroup($this->financeGroup(), [$this->member('o-ann', 'Ann', 'ann@tricab.com')]);
        $this->service->link(self::FINANCE, null);
        $this->entra->removeGroup(self::FINANCE);

        $result = $this->sync->syncAll();

        self::assertSame(1, $result['unlinked']);
        $group = $this->requireGroup();
        self::assertTrue($group->isUnlinked);
        self::assertNotNull($group->unlinkedAt);
        self::assertCount(1, $this->store->members($group->id), 'members are kept so workspace grants are not silently lost');
        self::assertSame('unlinked', $group->lastSyncOutcome);
        self::assertSame([], $this->store->linkedEntraGroups());

        $again = $this->sync->syncAll();
        self::assertSame(0, $again['groups'], 'an unlinked group is no longer read');
    }

    public function test_relinking_an_unlinked_group_reuses_it(): void
    {
        $this->entra->withGroup($this->financeGroup(), []);
        $first = $this->service->link(self::FINANCE, null)['group'];
        $unlinked = $this->service->unlink($first['publicId']);
        self::assertTrue($unlinked['isUnlinked']);

        $again = $this->service->link(self::FINANCE, null)['group'];

        self::assertSame($first['publicId'], $again['publicId']);
        self::assertFalse($again['isUnlinked']);
    }

    public function test_linking_the_same_group_twice_is_refused(): void
    {
        $this->entra->withGroup($this->financeGroup(), []);
        $this->service->link(self::FINANCE, null);

        $this->expectException(GroupException::class);
        $this->expectExceptionMessage('already linked');
        $this->service->link(self::FINANCE, null);
    }

    public function test_without_the_graph_permission_a_pass_is_a_typed_outcome_and_changes_nothing(): void
    {
        $this->entra->withGroup($this->financeGroup(), [$this->member('o-ann', 'Ann', 'ann@tricab.com')]);
        $this->service->link(self::FINANCE, null);
        $before = $this->store->members($this->requireGroup()->id);
        $this->entra->denyPermission();
        $this->entra->setMembers(self::FINANCE, []);

        $result = $this->sync->syncAll();

        self::assertSame(GraphPermissionNotGranted::OUTCOME, $result['outcome']);
        self::assertSame(0, $result['synced']);
        self::assertSame($before, $this->store->members($this->requireGroup()->id), 'nothing was changed');
        $group = $this->requireGroup();
        self::assertSame('permission_not_granted', $group->lastSyncOutcome);
        self::assertStringContainsString('GroupMember.Read.All', (string) $group->lastSyncMessage);
        self::assertFalse($group->isUnlinked, 'a missing permission must not unlink anything');
    }

    public function test_with_nothing_linked_a_pass_never_asks_graph(): void
    {
        $this->entra->denyPermission();

        $result = $this->sync->syncAll();

        self::assertSame('ok', $result['outcome']);
        self::assertSame(0, $result['groups']);
        self::assertSame(0, $this->entra->reads);
    }

    public function test_platform_group_crud_and_name_rules(): void
    {
        $ann = $this->users->add('ann@tricab.com', 'Ann');
        $annId = $this->users->describe([$ann])[$ann]['publicId'];
        $created = $this->service->create('  Warehouse  ', ' Pickers ', 7);
        self::assertSame('Warehouse', $created['name']);
        self::assertSame('platform', $created['source']);
        self::assertFalse($created['membersReadOnly']);

        $this->expectGroupError('GROUP_NAME_TAKEN', fn () => $this->service->create('warehouse', null, null));
        $this->expectGroupError('VALIDATION_FAILED', fn () => $this->service->create('   ', null, null));

        $withMember = $this->service->addMember($created['publicId'], $annId, 7);
        self::assertSame(1, $withMember['memberCount']);
        self::assertSame('platform', $withMember['members'][0]['source']);
        $this->service->addMember($created['publicId'], $annId, 7);
        self::assertSame(1, $this->service->get($created['publicId'])['memberCount'], 'adding twice is harmless');

        $this->expectGroupError('GROUP_USER_NOT_FOUND', fn () => $this->service->addMember($created['publicId'], '99999999-0000-4000-8000-000000000000', null));

        $renamed = $this->service->update($created['publicId'], 'Warehouse AU', null);
        self::assertSame('Warehouse AU', $renamed['name']);

        $afterRemove = $this->service->removeMember($created['publicId'], $annId);
        self::assertSame(0, $afterRemove['memberCount']);
        $this->expectGroupError('GROUP_MEMBER_NOT_FOUND', fn () => $this->service->removeMember($created['publicId'], $annId));

        $this->service->delete($created['publicId']);
        $this->expectGroupError('GROUP_NOT_FOUND', fn () => $this->service->get($created['publicId']));
    }

    public function test_an_entra_group_cannot_be_edited_or_deleted_only_unlinked(): void
    {
        $this->entra->withGroup($this->financeGroup(), []);
        $group = $this->service->link(self::FINANCE, null)['group'];

        $this->expectGroupError('GROUP_NOT_PLATFORM', fn () => $this->service->update($group['publicId'], 'Renamed', null));
        $this->expectGroupError('GROUP_NOT_PLATFORM', fn () => $this->service->delete($group['publicId']));
    }

    public function test_sync_now_on_a_platform_group_is_refused_and_lookup_skips_unlinked_groups(): void
    {
        $platform = $this->service->create('Finance team', null, null);
        $this->expectGroupError('GROUP_NOT_ENTRA', fn () => $this->service->syncNow($platform['publicId']));

        $this->entra->withGroup($this->financeGroup(), []);
        $linked = $this->service->link(self::FINANCE, null)['group'];
        self::assertCount(2, $this->service->lookup('finance'));
        $this->service->unlink($linked['publicId']);
        self::assertSame(['Finance team'], array_column($this->service->lookup('finance'), 'name'));
        $this->expectGroupError('GROUP_UNLINKED', fn () => $this->service->syncNow($linked['publicId']));
    }

    public function test_sync_now_without_permission_records_why_on_the_group_and_raises_the_typed_error(): void
    {
        $this->entra->withGroup($this->financeGroup(), []);
        $group = $this->service->link(self::FINANCE, null)['group'];
        $this->entra->denyPermission();

        try {
            $this->service->syncNow($group['publicId']);
            self::fail('Expected GraphPermissionNotGranted.');
        } catch (GraphPermissionNotGranted) {
            self::assertSame('permission_not_granted', $this->requireGroup()->lastSyncOutcome);
        }
    }

    public function test_entra_search_marks_groups_that_are_already_linked(): void
    {
        $this->entra->withGroup($this->financeGroup(), []);
        $this->entra->withGroup(new EntraGroup('bbbbbbbb-2222-4222-8222-000000000002', 'Finance Warriors', 'fw@tricab.com', null), []);
        $this->service->link(self::FINANCE, null);

        $found = $this->service->searchEntra('finance');

        self::assertSame([true, false], array_column($found, 'linked'));
    }

    private function financeGroup(): EntraGroup
    {
        return new EntraGroup(self::FINANCE, 'Finance Controls', 'financecontrols@tricab.com', 'Finance');
    }

    private function member(string $oid, string $name, string $mail, bool $enabled = true): EntraMember
    {
        return new EntraMember($oid, $name, $mail, $mail, $enabled);
    }

    private function requireGroup(): GroupRecord
    {
        $group = $this->store->findByEntraGroupId(self::FINANCE);
        self::assertInstanceOf(GroupRecord::class, $group);

        return $group;
    }

    private function expectGroupError(string $code, callable $call): void
    {
        try {
            $call();
            self::fail('Expected ' . $code);
        } catch (GroupException $e) {
            self::assertSame($code, $e->errorCode());
        }
    }
}
