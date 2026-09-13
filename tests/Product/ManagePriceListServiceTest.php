<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Product;

use PHPUnit\Framework\TestCase;
use TriCab\Api\Product\Persistence\InMemory\InMemoryPriceListRepository;
use TriCab\Api\Product\Persistence\InMemory\InMemoryProductAuditRepository;
use TriCab\Api\Product\Persistence\InMemory\InMemoryProductStore;
use TriCab\Core\Identity\Application\DTO\UserManagement\ActorContext;
use TriCab\Core\Product\Application\Command\UpsertPriceListCommand;
use TriCab\Core\Product\Application\Exception\DuplicateLegacyPriceContext;
use TriCab\Core\Product\Application\Exception\DuplicatePriceListCode;
use TriCab\Core\Product\Application\Service\ManagePriceListService;
use TriCab\Core\Product\Application\Service\ProductAuditRecorder;
use TriCab\Core\Product\Domain\ValueObject\PriceListLegacyContext;
use TriCab\Core\Tests\Product\Doubles\PassthroughApplicationTransaction;

final class ManagePriceListServiceTest extends TestCase
{
    private ManagePriceListService $service;
    private ActorContext $actor;

    protected function setUp(): void
    {
        $store = new InMemoryProductStore();
        $this->service = new ManagePriceListService(
            new InMemoryPriceListRepository(),
            new PassthroughApplicationTransaction(),
            new ProductAuditRecorder(new InMemoryProductAuditRepository($store)),
        );
        $this->actor = new ActorContext(userId: 1, publicId: 'actor-1', emailAddress: 'admin@test.local');
    }

    public function testCreateAndResolveLegacyContext(): void
    {
        $created = $this->service->create($this->actor, new UpsertPriceListCommand(
            name: 'Australia Base Cost',
            code: 'TCA-BASE',
            currencyPublicId: '00000000-0000-0000-0000-000000000001',
            regionPublicId: null,
            marketPublicId: null,
            legacySourceCode: PriceListLegacyContext::SYSTEM_CODE,
            legacyContextType: PriceListLegacyContext::CONTEXT_TYPE,
            legacyContextCode: '2507',
            effectiveFromUtc: null,
            effectiveToUtc: null,
            isActive: true,
        ));

        $resolved = $this->service->resolveLegacy(
            PriceListLegacyContext::SYSTEM_CODE,
            PriceListLegacyContext::CONTEXT_TYPE,
            '2507',
        );

        self::assertSame($created->publicId, $resolved->publicId);
        self::assertSame('2507', $resolved->legacyContextCode);
    }

    public function testDuplicateCodeRejected(): void
    {
        $command = new UpsertPriceListCommand(
            name: 'First',
            code: 'TCA-BASE',
            currencyPublicId: '00000000-0000-0000-0000-000000000001',
            regionPublicId: null,
            marketPublicId: null,
            legacySourceCode: null,
            legacyContextType: null,
            legacyContextCode: null,
            effectiveFromUtc: null,
            effectiveToUtc: null,
            isActive: true,
        );
        $this->service->create($this->actor, $command);

        $this->expectException(DuplicatePriceListCode::class);
        $this->service->create($this->actor, $command);
    }

    public function testDuplicateLegacyContextRejected(): void
    {
        $base = static fn (string $code): UpsertPriceListCommand => new UpsertPriceListCommand(
            name: $code,
            code: $code,
            currencyPublicId: '00000000-0000-0000-0000-000000000001',
            regionPublicId: null,
            marketPublicId: null,
            legacySourceCode: PriceListLegacyContext::SYSTEM_CODE,
            legacyContextType: PriceListLegacyContext::CONTEXT_TYPE,
            legacyContextCode: '2507',
            effectiveFromUtc: null,
            effectiveToUtc: null,
            isActive: true,
        );

        $this->service->create($this->actor, $base('LIST-A'));

        $this->expectException(DuplicateLegacyPriceContext::class);
        $this->service->create($this->actor, $base('LIST-B'));
    }
}
