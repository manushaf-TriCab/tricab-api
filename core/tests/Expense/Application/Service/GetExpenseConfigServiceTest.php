<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\Expense\Application\Service;

use PHPUnit\Framework\TestCase;
use TriCab\Api\Expense\Persistence\InMemoryExpenseCostCentreOptionsPort;
use TriCab\Api\Expense\Persistence\InMemoryExpenseProfileRepository;
use TriCab\Api\Expense\Persistence\ProfileFinanceCapabilityResolver;
use TriCab\Core\Expense\Application\DTO\ExpenseActor;
use TriCab\Core\Expense\Application\DTO\ExpenseFeatureFlags;
use TriCab\Core\Expense\Application\Service\ExpenseAccessService;
use TriCab\Core\Expense\Application\Service\GetExpenseConfigService;
use TriCab\Core\Expense\Domain\Entity\ExpenseProfile;
use TriCab\Core\Expense\Module;
use TriCab\Core\Identity\Domain\ValueObject\UserId;

final class GetExpenseConfigServiceTest extends TestCase
{
    public function test_configIncludesCostCentresFromPort(): void
    {
        $service = $this->service(new InMemoryExpenseCostCentreOptionsPort([
            'AdminInT, Port Melbourne',
            'Warehouse, Dandenong',
            '',
        ]));

        $config = $service->execute($this->actor());

        self::assertTrue($config['costCentresAvailable']);
        self::assertSame(
            [
                ['code' => 'AdminInT, Port Melbourne', 'name' => 'AdminInT, Port Melbourne'],
                ['code' => 'Warehouse, Dandenong', 'name' => 'Warehouse, Dandenong'],
            ],
            $config['costCentres'],
        );
        self::assertNotEmpty($config['categories']);
        self::assertSame('Freight In - Air', $config['categories'][0]['name']);
        self::assertSame(['assigned' => false, 'displayName' => null], $config['approver']);
    }

    public function test_unavailablePortReportsCostCentresUnavailable(): void
    {
        $service = $this->service(new InMemoryExpenseCostCentreOptionsPort([], false));
        $config = $service->execute($this->actor());

        self::assertFalse($config['costCentresAvailable']);
        self::assertSame([], $config['costCentres']);
    }

    private function service(InMemoryExpenseCostCentreOptionsPort $port): GetExpenseConfigService
    {
        $profiles = new InMemoryExpenseProfileRepository();
        $profiles->save(new ExpenseProfile(
            UserId::fromInt(42),
            'te-user',
            'te@tricab.com',
            true,
            false,
            false,
            null,
            null,
        ));

        return new GetExpenseConfigService(
            new ExpenseAccessService($profiles, new ProfileFinanceCapabilityResolver()),
            $port,
            ExpenseFeatureFlags::disabledIntegrations(),
        );
    }

    private function actor(): ExpenseActor
    {
        return new ExpenseActor(
            UserId::fromInt(42),
            'pub-42',
            'te@tricab.com',
            [Module::PERMISSION_ACCESS],
            ['employee'],
        );
    }
}
