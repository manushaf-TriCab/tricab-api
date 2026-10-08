<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Finance\Task;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use TriCab\Api\Finance\Task\InMemoryFinanceTaskMemberDirectory;
use TriCab\Api\Tests\Doubles\FixedClock;
use TriCab\Core\Finance\Module;
use TriCab\Core\Finance\Task\Domain\TaskActor;
use TriCab\Core\Finance\Task\Domain\TaskInvalid;
use TriCab\Core\Finance\Task\Domain\TaskMember;
use TriCab\Core\Finance\Task\Domain\TaskNotFound;
use TriCab\Core\Finance\Task\Service\TaskMembersService;
use TriCab\Core\Finance\Task\Service\TaskTime;

/** `/me`, `/members` and the job title (T2.5), on the in-memory directory. */
final class TaskMembersServiceTest extends TestCase
{
    private InMemoryFinanceTaskMemberDirectory $directory;

    private TaskMembersService $service;

    protected function setUp(): void
    {
        $this->directory = new InMemoryFinanceTaskMemberDirectory();
        $time = new TaskTime(new FixedClock(new DateTimeImmutable('2026-10-07 01:00:00', new DateTimeZone('UTC'))), 'Australia/Melbourne');
        $this->service = new TaskMembersService($this->directory, $time);
    }

    /** @param list<string> $permissions */
    private static function actor(array $permissions, int $id = 7): TaskActor
    {
        return new TaskActor($id, 'Jane Citizen', 'Jane.Citizen@Example.test', $permissions);
    }

    public function test_me_answers_the_portals_permission_strings_for_every_task_permission(): void
    {
        $everything = [Module::PERMISSION_ACCESS, Module::PERMISSION_TASK_ACCESS, ...Module::TASK_PERMISSIONS];
        $me = $this->service->me(self::actor($everything));

        $expected = array_values(Module::TASK_PORTAL_PERMISSIONS);
        sort($expected);
        self::assertSame($expected, $me['permissions']);
        self::assertCount(11, $expected);
        self::assertSame('7', $me['id']);
        self::assertSame('jane.citizen@example.test', $me['email']);
        self::assertSame('Jane Citizen', $me['name']);
        self::assertSame('Australia/Melbourne', $me['timezone']);
    }

    public function test_me_with_no_task_permission_lists_none_and_never_the_launcher_or_app_codes(): void
    {
        $me = $this->service->me(self::actor([Module::PERMISSION_ACCESS, Module::PERMISSION_TASK_ACCESS]));

        self::assertSame([], $me['permissions']);
        self::assertSame('user', $me['accessRole']);
    }

    public function test_the_access_role_follows_the_permissions_the_pages_test(): void
    {
        foreach (Module::TASK_ROLE_PERMISSIONS as $role => $permissions) {
            $expected = ['FINANCE_TASK_USER' => 'user', 'FINANCE_TASK_MANAGER' => 'manager', 'FINANCE_TASK_ADMIN' => 'admin'][$role];
            self::assertSame($expected, $this->service->me(self::actor($permissions))['accessRole'], $role);
        }
    }

    public function test_the_name_falls_back_to_the_email(): void
    {
        $me = $this->service->me(new TaskActor(3, '', 'Only.Mail@Example.test', []));

        self::assertSame('Only.Mail@Example.test', $me['name']);
    }

    public function test_members_are_listed_by_name_with_ids_as_text_and_the_portal_field_names(): void
    {
        $this->directory->add(2, 'zoe Zed', 'zoe@example.test', TaskMember::ROLE_MANAGER);
        $this->directory->add(1, 'Alan Able', 'Alan@Example.test');

        $list = $this->service->list();

        self::assertSame(['Alan Able', 'zoe Zed'], array_column($list, 'name'));
        self::assertSame('1', $list[0]['id']);
        self::assertSame('alan@example.test', $list[0]['email']);
        self::assertSame('user', $list[0]['access_role']);
        self::assertSame('manager', $list[1]['access_role']);
        self::assertSame(['id', 'email', 'full_name', 'name', 'job_title', 'role', 'access_role'], array_keys($list[0]));
        self::assertSame($list[0]['job_title'], $list[0]['role'], 'role is the job title, as the old pages read it');
    }

    public function test_a_job_title_is_trimmed_stored_and_shown(): void
    {
        $this->directory->add(5, 'Pat Poe', 'pat@example.test');

        self::assertSame(['user_id' => '5', 'job_title' => 'Senior Accountant'], $this->service->setJobTitle(5, "  Senior Accountant \u{00A0}"));
        self::assertSame('Senior Accountant', $this->service->list()[0]['job_title']);
        $this->service->setJobTitle(5, '');
        self::assertSame('', $this->service->list()[0]['job_title']);
    }

    public function test_a_job_title_is_at_most_200_characters(): void
    {
        $this->directory->add(5, 'Pat Poe', 'pat@example.test');

        $this->service->setJobTitle(5, str_repeat('é', 200));
        $this->expectException(TaskInvalid::class);
        $this->expectExceptionMessage('Job title must be at most 200 characters');
        $this->service->setJobTitle(5, str_repeat('é', 201));
    }

    public function test_the_target_must_be_a_member(): void
    {
        $this->directory->add(5, 'Pat Poe', 'pat@example.test');
        $this->directory->remove(5);

        $this->expectException(TaskNotFound::class);
        $this->expectExceptionMessage('Member not found');
        $this->service->setJobTitle(5, 'Anything');
    }

    public function test_a_platform_administrator_alone_holds_no_task_permission_and_is_no_member(): void
    {
        // Decision D3: ADMINISTRATOR holds FINANCE.ADMIN only. Nothing in Module gives it a Tri-Task permission,
        // and membership is "holds FINANCE.TASK.TASKS.READ", so it is not in the list and cannot be given a title.
        foreach (Module::TASK_PERMISSIONS as $code) {
            self::assertNotContains($code, Module::ADMINISTRATOR_PERMISSIONS);
        }
        $admin = $this->service->me(self::actor(Module::ADMINISTRATOR_PERMISSIONS));
        self::assertSame([], $admin['permissions']);
        self::assertSame([], $this->service->list());
        $this->expectException(TaskNotFound::class);
        $this->service->setJobTitle(7, 'Boss');
    }
}
