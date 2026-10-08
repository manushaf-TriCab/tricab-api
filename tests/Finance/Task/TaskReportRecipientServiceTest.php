<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Finance\Task;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TriCab\Api\Finance\Task\InMemoryFinanceTaskMemberDirectory;
use TriCab\Api\Finance\Task\InMemoryTaskReportRecipientStore;
use TriCab\Api\Tests\Doubles\FixedClock;
use TriCab\Core\Finance\Task\Domain\TaskConflict;
use TriCab\Core\Finance\Task\Domain\TaskInvalid;
use TriCab\Core\Finance\Task\Domain\TaskNotFound;
use TriCab\Core\Finance\Task\Service\TaskReportRecipientService;
use TriCab\Core\Finance\Task\Service\TaskTime;

/** The daily summary's recipients (routes 48 to 50), on the twin (T2.9). */
final class TaskReportRecipientServiceTest extends TestCase
{
    private InMemoryFinanceTaskMemberDirectory $directory;

    private TaskReportRecipientService $service;

    protected function setUp(): void
    {
        $this->directory = new InMemoryFinanceTaskMemberDirectory();
        $this->directory->add(11, 'Una User', 'una@example.test');
        $this->service = new TaskReportRecipientService(
            new InMemoryTaskReportRecipientStore(),
            $this->directory,
            new TaskTime(FixedClock::utc('2026-10-07 01:00:00'), 'Australia/Melbourne'),
        );
    }

    public function test_an_address_is_added_lower_cased_with_the_name_given(): void
    {
        $r = $this->service->add(['email' => '  Boss@Example.test ', 'name' => ' The Boss ']);

        self::assertSame('boss@example.test', $r['email']);
        self::assertSame('The Boss', $r['name']);
        self::assertNull($r['platform_user_id']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', Typed::string($r['id']));
        self::assertSame('2026-10-07T01:00:00.000000+00:00', $r['created_date']);
    }

    public function test_the_address_of_a_member_is_linked_and_names_the_recipient_when_no_name_is_given(): void
    {
        $r = $this->service->add(['email' => 'UNA@example.test']);

        self::assertSame('11', $r['platform_user_id']);
        self::assertSame('Una User', $r['name']);
        $named = $this->service->add(['email' => 'una.other@example.test', 'name' => 'Given']);
        self::assertSame('Given', $named['name']);
    }

    /** @return iterable<string, array{mixed}> */
    public static function badAddresses(): iterable
    {
        yield 'none' => [null];
        yield 'blank' => ['   '];
        yield 'no at' => ['boss.example.test'];
        yield 'no dot' => ['boss@example'];
        yield 'a space' => ['bo ss@example.test'];
        yield 'a comma' => ['a@example.test,b@example.test'];
        yield 'a semicolon' => ['a@example.test;b@example.test'];
        yield 'angle brackets' => ['<a@example.test>'];
    }

    #[DataProvider('badAddresses')]
    public function test_a_bad_address_is_refused(mixed $email): void
    {
        try {
            $this->service->add(['email' => $email]);
            self::fail('Expected a refusal.');
        } catch (TaskInvalid $e) {
            self::assertSame('Enter a valid email address', $e->getMessage());
        }
        self::assertSame([], $this->service->list());
    }

    public function test_an_address_is_unique_in_any_case(): void
    {
        $this->service->add(['email' => 'boss@example.test']);

        try {
            $this->service->add(['email' => 'BOSS@example.test']);
            self::fail('Expected a conflict.');
        } catch (TaskConflict $e) {
            self::assertSame('That address is already a recipient', $e->getMessage());
            self::assertSame(409, $e->httpStatus());
        }
    }

    public function test_recipients_list_by_email_and_a_delete_is_hard_and_a_404_when_gone(): void
    {
        $z = $this->service->add(['email' => 'zed@example.test']);
        $this->service->add(['email' => 'amy@example.test']);

        self::assertSame(['amy@example.test', 'zed@example.test'], array_column($this->service->list(), 'email'));
        self::assertSame(['deleted' => true], $this->service->remove(Typed::string($z['id'])));
        self::assertSame(['amy@example.test'], array_column($this->service->list(), 'email'));
        $this->expectException(TaskNotFound::class);
        $this->expectExceptionMessage('Recipient not found');
        $this->service->remove(Typed::string($z['id']));
    }

    public function test_a_long_name_is_cut_to_200_characters(): void
    {
        self::assertSame(200, mb_strlen(Typed::string($this->service->add(['email' => 'a@example.test', 'name' => str_repeat('é', 300)])['name'])));
    }
}
