<?php
declare(strict_types=1);
namespace TriCab\Core\Tests\Expense\Application\Service;
use PHPUnit\Framework\TestCase;
use TriCab\Api\Expense\Persistence\FailClosedGraphFinanceGroupChecker;
use TriCab\Api\Expense\Persistence\NullExpenseGraphFinanceGroupChecker;
use TriCab\Api\Expense\Persistence\OrExpenseFinanceCapabilityResolver;
use TriCab\Core\Expense\Contract\ExpenseGraphFinanceGroupChecker;
use TriCab\Core\Expense\Domain\Entity\ExpenseProfile;
use TriCab\Core\Identity\Domain\ValueObject\UserId;
final class ExpenseFinanceResolverTest extends TestCase
{
    public function test_access_permission_alone_does_not_grant_finance(): void
    {
        $profile = new ExpenseProfile(UserId::fromInt(1), 'u', 'u@tricab.com', true, false, false, null, null);
        $resolver = new OrExpenseFinanceCapabilityResolver(new NullExpenseGraphFinanceGroupChecker());
        self::assertFalse($resolver->hasFinanceCapability(UserId::fromInt(1), $profile));
    }
    public function test_profile_finance_flag_grants_finance(): void
    {
        $profile = new ExpenseProfile(UserId::fromInt(1), 'u', 'u@tricab.com', true, true, false, null, null);
        $resolver = new OrExpenseFinanceCapabilityResolver(new NullExpenseGraphFinanceGroupChecker());
        self::assertTrue($resolver->hasFinanceCapability(UserId::fromInt(1), $profile));
    }
    public function test_graph_failure_does_not_grant_finance(): void
    {
        $throwing = new class implements ExpenseGraphFinanceGroupChecker {
            public function isMemberOfFinanceGroup(UserId $userId, string $email): bool
            {
                throw new \RuntimeException('graph down');
            }
        };
        $checker = new FailClosedGraphFinanceGroupChecker($throwing);
        $profile = new ExpenseProfile(UserId::fromInt(1), 'u', 'u@tricab.com', true, false, false, null, null);
        self::assertFalse($checker->isMemberOfFinanceGroup(UserId::fromInt(1), 'u@tricab.com'));
        $resolver = new OrExpenseFinanceCapabilityResolver($checker);
        self::assertFalse($resolver->hasFinanceCapability(UserId::fromInt(1), $profile));
    }
}
