<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Finance\Ar;

use PHPUnit\Framework\TestCase;
use TriCab\Api\Finance\Ar\InMemoryArAssigneeDirectory;
use TriCab\Api\Tests\Doubles\FixedClock;
use TriCab\Core\Finance\Ar\Domain\ArActor;
use TriCab\Core\Finance\Ar\Service\ArMeService;
use TriCab\Core\Finance\Ar\Service\ArTime;
use TriCab\Core\Finance\Module;

/** `GET /me` and `GET /assignees` on the in-memory directory (T5.3). */
final class ArMeServiceTest extends TestCase
{
    private InMemoryArAssigneeDirectory $directory;

    private ArMeService $service;

    protected function setUp(): void
    {
        $this->directory = new InMemoryArAssigneeDirectory();
        $this->service = new ArMeService(
            $this->directory,
            new ArTime(FixedClock::utc('2026-10-08 01:02:03'), 'Australia/Melbourne'),
            'ar@example.test',
            'https://credit.example.test',
        );
    }

    public function test_me_answers_the_portals_permission_strings_the_mailbox_and_the_credit_notes_link(): void
    {
        $actor = new ArActor(12, 'Mia Operator', 'Mia@Example.test', [Module::PERMISSION_AR_ACCESS, Module::PERMISSION_AR_RECORDS_WRITE, Module::PERMISSION_AR_RECORDS_READ, Module::PERMISSION_ACCESS]);

        $me = $this->service->me($actor);

        self::assertSame('12', $me['id']);
        self::assertSame('Mia Operator', $me['name']);
        self::assertSame('Mia Operator', $me['full_name']);
        self::assertSame('mia@example.test', $me['email']);
        self::assertSame(['ar.records.read', 'ar.records.write'], $me['permissions'], 'sorted; FINANCE.ACCESS and FINANCE.AR.ACCESS have no portal string');
        self::assertSame('ar@example.test', $me['mailbox']);
        self::assertSame('https://credit.example.test', $me['credit_notes_url']);
        self::assertSame('Australia/Melbourne', $me['timezone']);
    }

    public function test_an_administrator_gets_the_admin_string_and_a_nameless_person_shows_their_address(): void
    {
        $actor = new ArActor(3, '', 'ada@example.test', [Module::PERMISSION_AR_ACCESS, ...Module::AR_PERMISSIONS]);

        $me = $this->service->me($actor);

        self::assertSame('ada@example.test', $me['name']);
        $permissions = $me['permissions'];
        self::assertIsArray($permissions);
        self::assertContains('ar.admin', $permissions);
        self::assertContains('ar.attachments.read', $permissions);
        self::assertCount(count(Module::AR_PORTAL_PERMISSIONS), $permissions);
    }

    public function test_a_person_with_the_app_but_no_records_permission_gets_an_empty_list_not_a_refusal(): void
    {
        $me = $this->service->me(new ArActor(4, 'Nina', 'nina@example.test', [Module::PERMISSION_AR_ACCESS]));

        self::assertSame([], $me['permissions']);
    }

    public function test_an_unset_credit_notes_link_is_the_empty_string_so_the_page_hides_it(): void
    {
        $service = new ArMeService($this->directory, new ArTime(FixedClock::utc('2026-10-08 01:02:03')), 'ar@example.test', '');

        self::assertSame('', $service->me(new ArActor(1, 'A', 'a@example.test', []))['credit_notes_url']);
    }

    public function test_me_says_whether_sending_is_switched_on_and_it_is_off_unless_told_otherwise(): void
    {
        $actor = new ArActor(1, 'A', 'a@example.test', []);
        $time = new ArTime(FixedClock::utc('2026-10-08 01:02:03'));

        self::assertFalse($this->service->me($actor)['sending_enabled']);
        self::assertTrue((new ArMeService($this->directory, $time, 'ar@example.test', '', true))->me($actor)['sending_enabled']);
        self::assertFalse((new ArMeService($this->directory, $time, 'ar@example.test', '', false))->me($actor)['sending_enabled']);
    }

    public function test_assignees_are_the_directory_by_name_with_text_ids_and_the_sources_two_keys(): void
    {
        $this->directory->add(9, 'Una User', 'UNA@example.test');
        $this->directory->add(4, 'Max Manager', 'max@example.test');
        $this->directory->add(7, '', 'noname@example.test');

        $rows = $this->service->assignees();

        // Ordered on the stored display name, as the SQL directory orders: a nameless person sorts first.
        self::assertSame([
            ['id' => '7', 'email' => 'noname@example.test', 'full_name' => 'noname@example.test'],
            ['id' => '4', 'email' => 'max@example.test', 'full_name' => 'Max Manager'],
            ['id' => '9', 'email' => 'una@example.test', 'full_name' => 'Una User'],
        ], $rows);
    }
}
