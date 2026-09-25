<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\Expense\Application\Service;

use PHPUnit\Framework\TestCase;
use TriCab\Api\Expense\Persistence\InMemoryExpenseProfileRepository;
use TriCab\Core\Expense\Application\Service\LinkLegacyExpenseApproverResult;
use TriCab\Core\Expense\Application\Service\LinkLegacyExpenseApproversService;
use TriCab\Core\Expense\Domain\Entity\ExpenseProfile;
use TriCab\Core\Identity\Domain\ValueObject\UserId;

final class LinkLegacyExpenseApproversServiceTest extends TestCase
{
    private InMemoryExpenseProfileRepository $profiles;
    private LinkLegacyExpenseApproversService $service;

    protected function setUp(): void
    {
        $this->profiles = new InMemoryExpenseProfileRepository();
        $this->service = new LinkLegacyExpenseApproversService($this->profiles);

        $this->profiles->save(new ExpenseProfile(
            UserId::fromInt(1),
            'thilan',
            'thilanh@tricab.com',
            true,
            false,
            false,
            null,
            null,
        ));
        $this->profiles->save(new ExpenseProfile(
            UserId::fromInt(2),
            'mgr',
            'manager@tricab.com',
            true,
            false,
            false,
            null,
            null,
        ));
        $this->profiles->save(new ExpenseProfile(
            UserId::fromInt(3),
            'inactive',
            'inactive@tricab.com',
            false,
            false,
            false,
            null,
            null,
        ));
    }

    public function test_wouldLinkThenApplyThenAlreadyLinked(): void
    {
        $byEmail = [
            'thilanh@tricab.com' => [['userId' => 1, 'email_norm' => 'thilanh@tricab.com']],
            'manager@tricab.com' => [['userId' => 2, 'email_norm' => 'manager@tricab.com']],
        ];
        $loginToEmail = ['thilan' => 'thilanh@tricab.com', 'mgr' => 'manager@tricab.com'];
        $te = [
            'login_norm' => 'thilan',
            'email_norm' => 'thilanh@tricab.com',
            'is_active' => 1,
            'manager_login' => 'mgr',
        ];

        $first = $this->service->classifyOne($te, $byEmail, $loginToEmail);
        self::assertSame(LinkLegacyExpenseApproverResult::WOULD_LINK, $first->classification);

        $saved = $this->service->apply($first);
        self::assertSame(2, $saved->managerUserId()?->toInt());
        self::assertSame('mgr', $saved->managerLegacyLogin());

        $second = $this->service->classifyOne($te, $byEmail, $loginToEmail);
        self::assertSame(LinkLegacyExpenseApproverResult::ALREADY_LINKED, $second->classification);
    }

    public function test_conflictDoesNotOverwrite(): void
    {
        $this->profiles->save(new ExpenseProfile(
            UserId::fromInt(1),
            'thilan',
            'thilanh@tricab.com',
            true,
            false,
            false,
            null,
            UserId::fromInt(99),
        ));
        $byEmail = [
            'thilanh@tricab.com' => [['userId' => 1, 'email_norm' => 'thilanh@tricab.com']],
            'manager@tricab.com' => [['userId' => 2, 'email_norm' => 'manager@tricab.com']],
        ];
        $result = $this->service->classifyOne(
            [
                'login_norm' => 'thilan',
                'email_norm' => 'thilanh@tricab.com',
                'is_active' => 1,
                'manager_login' => 'mgr',
            ],
            $byEmail,
            ['mgr' => 'manager@tricab.com'],
        );
        self::assertSame(LinkLegacyExpenseApproverResult::CONFLICT, $result->classification);
        self::assertSame(99, $this->profiles->findByUserId(UserId::fromInt(1))?->managerUserId()?->toInt());
    }

    public function test_missingEmployeeManagerInactiveAmbiguous(): void
    {
        $byEmail = [
            'orphan@tricab.com' => [['userId' => 50, 'email_norm' => 'orphan@tricab.com']],
            'manager@tricab.com' => [['userId' => 2, 'email_norm' => 'manager@tricab.com']],
            'dup@tricab.com' => [
                ['userId' => 10, 'email_norm' => 'dup@tricab.com'],
                ['userId' => 11, 'email_norm' => 'dup@tricab.com'],
            ],
        ];

        self::assertSame(
            LinkLegacyExpenseApproverResult::EMPLOYEE_NOT_FOUND,
            $this->service->classifyOne(
                ['login_norm' => 'x', 'email_norm' => 'missing@tricab.com', 'is_active' => 1, 'manager_login' => 'mgr'],
                $byEmail,
                ['mgr' => 'manager@tricab.com'],
            )->classification,
        );

        self::assertSame(
            LinkLegacyExpenseApproverResult::MANAGER_NOT_FOUND,
            $this->service->classifyOne(
                ['login_norm' => 'thilan', 'email_norm' => 'thilanh@tricab.com', 'is_active' => 1, 'manager_login' => 'nobody'],
                [
                    'thilanh@tricab.com' => [['userId' => 1, 'email_norm' => 'thilanh@tricab.com']],
                ],
                [],
            )->classification,
        );

        self::assertSame(
            LinkLegacyExpenseApproverResult::INACTIVE_SKIPPED,
            $this->service->classifyOne(
                ['login_norm' => 'inactive', 'email_norm' => 'inactive@tricab.com', 'is_active' => 1, 'manager_login' => 'mgr'],
                [
                    'inactive@tricab.com' => [['userId' => 3, 'email_norm' => 'inactive@tricab.com']],
                    'manager@tricab.com' => [['userId' => 2, 'email_norm' => 'manager@tricab.com']],
                ],
                ['mgr' => 'manager@tricab.com'],
            )->classification,
        );

        self::assertSame(
            LinkLegacyExpenseApproverResult::AMBIGUOUS,
            $this->service->classifyOne(
                ['login_norm' => 'dup', 'email_norm' => 'dup@tricab.com', 'is_active' => 1, 'manager_login' => 'mgr'],
                $byEmail,
                ['mgr' => 'manager@tricab.com'],
            )->classification,
        );

        self::assertSame(
            LinkLegacyExpenseApproverResult::NO_LEGACY_MANAGER,
            $this->service->classifyOne(
                ['login_norm' => 'thilan', 'email_norm' => 'thilanh@tricab.com', 'is_active' => 1, 'manager_login' => ''],
                ['thilanh@tricab.com' => [['userId' => 1, 'email_norm' => 'thilanh@tricab.com']]],
                [],
            )->classification,
        );
    }
}
