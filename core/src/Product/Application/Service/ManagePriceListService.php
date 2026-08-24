<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Service;

use TriCab\Core\Identity\Application\DTO\UserManagement\ActorContext;
use TriCab\Core\Product\Application\Command\PriceListStatusCommand;
use TriCab\Core\Product\Application\Command\UpsertPriceListCommand;
use TriCab\Core\Product\Application\DTO\PriceListRecord;
use TriCab\Core\Product\Application\DTO\PriceListUsage;
use TriCab\Core\Product\Application\Exception\DuplicateLegacyPriceContext;
use TriCab\Core\Product\Application\Exception\DuplicatePriceListCode;
use TriCab\Core\Product\Application\Exception\PriceListInUse;
use TriCab\Core\Product\Application\Exception\PriceListNotFound;
use TriCab\Core\Product\Contract\PriceListRepository;
use TriCab\Core\Product\Domain\ValueObject\PriceListLegacyContext;
use TriCab\Core\Shared\Persistence\ApplicationTransaction;

final class ManagePriceListService
{
    public function __construct(
        private readonly PriceListRepository $priceLists,
        private readonly ApplicationTransaction $transaction,
        private readonly ProductAuditRecorder $audit,
    ) {
    }

    /** @param array{search?: ?string, activeOnly?: ?bool, includeDeleted?: bool} $filters */
    public function list(array $filters = []): array
    {
        return $this->priceLists->listAdmin($filters);
    }

    public function get(string $publicId): PriceListRecord
    {
        return $this->require($publicId, true);
    }

    public function getUsage(string $publicId): PriceListUsage
    {
        $this->require($publicId, true);

        return $this->priceLists->getUsage($publicId);
    }

    public function resolveLegacy(string $sourceCode, string $contextType, string $contextCode): PriceListRecord
    {
        $record = $this->priceLists->resolveByLegacyContext(
            $this->normalizeLegacySource($sourceCode),
            $this->normalizeLegacyContextType($contextType),
            $this->normalizeLegacyContextCode($contextCode),
        );
        if ($record === null) {
            throw PriceListNotFound::forPublicId(sprintf('%s:%s:%s', $sourceCode, $contextType, $contextCode));
        }

        return $record;
    }

    public function create(ActorContext $actor, UpsertPriceListCommand $command): PriceListRecord
    {
        return $this->transaction->run(function () use ($actor, $command): PriceListRecord {
            $code = $this->normalizeCode($command->code);
            $name = $this->requireName($command->name);
            if ($this->priceLists->codeExists($code)) {
                throw DuplicatePriceListCode::forCode($code);
            }
            $legacy = $this->normalizeLegacyMapping($command);
            if ($legacy !== null && $this->priceLists->legacyContextExists(
                $legacy['sourceCode'],
                $legacy['contextType'],
                $legacy['contextCode'],
            )) {
                throw DuplicateLegacyPriceContext::forContext(
                    $legacy['sourceCode'],
                    $legacy['contextType'],
                    $legacy['contextCode'],
                );
            }

            $created = $this->priceLists->create(
                $this->payloadFromCommand($command, $code, $name, $legacy),
                $this->audit->actorUserId($actor),
            );

            $this->audit->record(
                actor: $actor,
                productPublicId: null,
                entityTypeCode: 'price-list',
                entityId: null,
                actionCode: 'created',
                newValues: ['publicId' => $created->publicId, 'code' => $created->code],
            );

            return $created;
        });
    }

    public function update(ActorContext $actor, UpsertPriceListCommand $command): PriceListRecord
    {
        $publicId = $command->priceListPublicId ?? '';
        if ($publicId === '') {
            throw new \InvalidArgumentException('priceListPublicId is required for update.');
        }

        return $this->transaction->run(function () use ($actor, $command, $publicId): PriceListRecord {
            $this->require($publicId, true);
            $code = $this->normalizeCode($command->code);
            $name = $this->requireName($command->name);
            if ($this->priceLists->codeExists($code, $publicId)) {
                throw DuplicatePriceListCode::forCode($code);
            }
            $legacy = $this->normalizeLegacyMapping($command);
            if ($legacy !== null && $this->priceLists->legacyContextExists(
                $legacy['sourceCode'],
                $legacy['contextType'],
                $legacy['contextCode'],
                $publicId,
            )) {
                throw DuplicateLegacyPriceContext::forContext(
                    $legacy['sourceCode'],
                    $legacy['contextType'],
                    $legacy['contextCode'],
                );
            }

            $updated = $this->priceLists->update(
                $publicId,
                $this->payloadFromCommand($command, $code, $name, $legacy),
                $this->audit->actorUserId($actor),
            );

            $this->audit->record(
                actor: $actor,
                productPublicId: null,
                entityTypeCode: 'price-list',
                entityId: null,
                actionCode: 'updated',
                newValues: ['publicId' => $updated->publicId, 'code' => $updated->code],
            );

            return $updated;
        });
    }

    public function activate(ActorContext $actor, PriceListStatusCommand $command): PriceListRecord
    {
        return $this->setStatus($actor, $command, true);
    }

    public function deactivate(ActorContext $actor, PriceListStatusCommand $command): PriceListRecord
    {
        return $this->setStatus($actor, $command, false);
    }

    public function archive(ActorContext $actor, PriceListStatusCommand $command): PriceListRecord
    {
        return $this->transaction->run(function () use ($actor, $command): PriceListRecord {
            $record = $this->require($command->priceListPublicId, true);
            $usage = $this->priceLists->getUsage($command->priceListPublicId);
            $total = $usage->productPriceCount + $usage->variantPriceCount + $usage->priceListCustomerCount;
            if ($total > 0) {
                throw PriceListInUse::cannotArchive($command->priceListPublicId, $total);
            }

            $archived = $this->priceLists->archive(
                $command->priceListPublicId,
                $command->expectedRowVersion,
                $this->audit->actorUserId($actor),
            );

            $this->audit->record(
                actor: $actor,
                productPublicId: null,
                entityTypeCode: 'price-list',
                entityId: null,
                actionCode: 'archived',
                newValues: ['publicId' => $record->publicId],
            );

            return $archived;
        });
    }

    public function restore(ActorContext $actor, PriceListStatusCommand $command): PriceListRecord
    {
        return $this->transaction->run(function () use ($actor, $command): PriceListRecord {
            $restored = $this->priceLists->restore(
                $command->priceListPublicId,
                $command->expectedRowVersion,
                $this->audit->actorUserId($actor),
            );

            $this->audit->record(
                actor: $actor,
                productPublicId: null,
                entityTypeCode: 'price-list',
                entityId: null,
                actionCode: 'restored',
                newValues: ['publicId' => $restored->publicId],
            );

            return $restored;
        });
    }

    private function setStatus(ActorContext $actor, PriceListStatusCommand $command, bool $active): PriceListRecord
    {
        return $this->transaction->run(function () use ($actor, $command, $active): PriceListRecord {
            $this->require($command->priceListPublicId, true);
            $updated = $this->priceLists->setActive(
                $command->priceListPublicId,
                $active,
                $command->expectedRowVersion,
                $this->audit->actorUserId($actor),
            );

            $this->audit->record(
                actor: $actor,
                productPublicId: null,
                entityTypeCode: 'price-list',
                entityId: null,
                actionCode: $active ? 'activated' : 'deactivated',
                newValues: ['publicId' => $updated->publicId],
            );

            return $updated;
        });
    }

    private function require(string $publicId, bool $includeDeleted): PriceListRecord
    {
        $record = $this->priceLists->getByPublicId($publicId, $includeDeleted);
        if ($record === null) {
            throw PriceListNotFound::forPublicId($publicId);
        }

        return $record;
    }

    private function normalizeCode(string $code): string
    {
        $normalized = strtoupper(trim($code));
        if ($normalized === '' || strlen($normalized) > 50) {
            throw new \InvalidArgumentException('Price list code is required and must be at most 50 characters.');
        }

        return $normalized;
    }

    private function requireName(string $name): string
    {
        $normalized = trim($name);
        if ($normalized === '' || strlen($normalized) > 200) {
            throw new \InvalidArgumentException('Price list name is required and must be at most 200 characters.');
        }

        return $normalized;
    }

    /**
     * @return array{sourceCode: string, contextType: string, contextCode: string}|null
     */
    private function normalizeLegacyMapping(UpsertPriceListCommand $command): ?array
    {
        $source = $command->legacySourceCode !== null ? trim($command->legacySourceCode) : '';
        $type = $command->legacyContextType !== null ? trim($command->legacyContextType) : '';
        $code = $command->legacyContextCode !== null ? trim($command->legacyContextCode) : '';

        if ($source === '' && $type === '' && $code === '') {
            return null;
        }

        if ($source === '' || $type === '' || $code === '') {
            throw new \InvalidArgumentException('Legacy source, context type, and context code must all be provided together.');
        }

        return [
            'sourceCode' => $this->normalizeLegacySource($source),
            'contextType' => $this->normalizeLegacyContextType($type),
            'contextCode' => $this->normalizeLegacyContextCode($code),
        ];
    }

    private function normalizeLegacySource(string $source): string
    {
        return strtolower(trim($source));
    }

    private function normalizeLegacyContextType(string $type): string
    {
        return strtolower(trim($type));
    }

    private function normalizeLegacyContextCode(string $code): string
    {
        $normalized = trim($code);
        if ($normalized === '' || strlen($normalized) > 200) {
            throw new \InvalidArgumentException('Legacy context code is required.');
        }

        return $normalized;
    }

    /**
     * @param array{sourceCode: string, contextType: string, contextCode: string}|null $legacy
     * @return array<string, mixed>
     */
    private function payloadFromCommand(
        UpsertPriceListCommand $command,
        string $code,
        string $name,
        ?array $legacy,
    ): array {
        return [
            'code' => $code,
            'name' => $name,
            'currencyPublicId' => $command->currencyPublicId,
            'regionPublicId' => $command->regionPublicId,
            'marketPublicId' => $command->marketPublicId,
            'legacySourceCode' => $legacy['sourceCode'] ?? null,
            'legacyContextType' => $legacy['contextType'] ?? null,
            'legacyContextCode' => $legacy['contextCode'] ?? null,
            'effectiveFromUtc' => $command->effectiveFromUtc,
            'effectiveToUtc' => $command->effectiveToUtc,
            'isActive' => $command->isActive,
            'expectedRowVersion' => $command->expectedRowVersion,
        ];
    }
}
