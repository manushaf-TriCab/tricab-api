<?php

declare(strict_types=1);

namespace TriCab\Api\Product\Persistence\InMemory;

use TriCab\Core\Identity\Domain\ValueObject\UserId;
use TriCab\Core\Product\Application\DTO\PriceListLookupItem;
use TriCab\Core\Product\Application\DTO\PriceListRecord;
use TriCab\Core\Product\Application\DTO\PriceListUsage;
use TriCab\Core\Product\Application\Exception\PriceListConcurrencyConflict;
use TriCab\Core\Product\Application\Exception\PriceListNotFound;
use TriCab\Core\Product\Contract\PriceListRepository;
use TriCab\Core\Product\Domain\ValueObject\RowVersionToken;

final class InMemoryPriceListRepository implements PriceListRepository
{
    /** @var array<string, array<string, mixed>> */
    private array $records = [];

    public function listAdmin(array $filters = []): array
    {
        $items = [];
        foreach ($this->records as $record) {
            if (($filters['includeDeleted'] ?? false) !== true && ($record['isDeleted'] ?? false)) {
                continue;
            }
            if (array_key_exists('activeOnly', $filters) && $filters['activeOnly'] !== null) {
                if (($record['isActive'] ?? true) !== $filters['activeOnly']) {
                    continue;
                }
            }
            $items[] = $this->hydrate($record);
        }

        usort($items, static fn (PriceListRecord $a, PriceListRecord $b): int => strcmp($a->name, $b->name));

        return $items;
    }

    public function getByPublicId(string $publicId, bool $includeDeleted = false): ?PriceListRecord
    {
        $record = $this->records[$publicId] ?? null;
        if ($record === null) {
            return null;
        }
        if (!$includeDeleted && ($record['isDeleted'] ?? false)) {
            return null;
        }

        return $this->hydrate($record);
    }

    public function resolveByLegacyContext(string $sourceCode, string $contextType, string $contextCode): ?PriceListRecord
    {
        foreach ($this->records as $record) {
            if (($record['isDeleted'] ?? false) || !($record['isActive'] ?? true)) {
                continue;
            }
            if (
                ($record['legacySourceCode'] ?? null) === $sourceCode
                && ($record['legacyContextType'] ?? null) === $contextType
                && ($record['legacyContextCode'] ?? null) === $contextCode
            ) {
                return $this->hydrate($record);
            }
        }

        return null;
    }

    public function listActiveLookups(): array
    {
        return array_map(
            fn (PriceListRecord $record): PriceListLookupItem => new PriceListLookupItem(
                publicId: $record->publicId,
                code: $record->code,
                name: $record->name,
                currencyCode: $record->currencyCode,
                regionName: $record->regionName,
                legacySourceCode: $record->legacySourceCode,
                legacyContextCode: $record->legacyContextCode,
                isActive: $record->isActive,
            ),
            array_filter(
                $this->listAdmin(['activeOnly' => true]),
                static fn (PriceListRecord $record): bool => !$record->isDeleted && $record->isActive,
            ),
        );
    }

    public function getUsage(string $publicId): PriceListUsage
    {
        $this->requireRecord($publicId);

        return new PriceListUsage(0, 0, 0, 0, '0 product, 0 variant, 0 customer assignment(s)');
    }

    public function codeExists(string $code, ?string $excludePublicId = null): bool
    {
        foreach ($this->records as $publicId => $record) {
            if ($excludePublicId !== null && $publicId === $excludePublicId) {
                continue;
            }
            if (($record['isDeleted'] ?? false) === true) {
                continue;
            }
            if (($record['code'] ?? '') === $code) {
                return true;
            }
        }

        return false;
    }

    public function legacyContextExists(
        string $sourceCode,
        string $contextType,
        string $contextCode,
        ?string $excludePublicId = null,
    ): bool {
        foreach ($this->records as $publicId => $record) {
            if ($excludePublicId !== null && $publicId === $excludePublicId) {
                continue;
            }
            if (($record['isDeleted'] ?? false) === true) {
                continue;
            }
            if (
                ($record['legacySourceCode'] ?? null) === $sourceCode
                && ($record['legacyContextType'] ?? null) === $contextType
                && ($record['legacyContextCode'] ?? null) === $contextCode
            ) {
                return true;
            }
        }

        return false;
    }

    public function create(array $data, UserId $actorUserId): PriceListRecord
    {
        unset($actorUserId);
        $publicId = $this->uuid();
        $record = $this->baseRecord($publicId, $data);
        $this->records[$publicId] = $record;

        return $this->hydrate($record);
    }

    public function update(string $publicId, array $data, UserId $actorUserId): PriceListRecord
    {
        unset($actorUserId);
        $existing = $this->requireRecord($publicId);
        if (($data['expectedRowVersion'] ?? null) !== $existing['rowVersion']) {
            throw PriceListConcurrencyConflict::forPublicId($publicId);
        }
        $record = $this->baseRecord($publicId, $data);
        $record['rowVersion'] = RowVersionToken::fromBinary(random_bytes(8))->toBase64();
        $this->records[$publicId] = $record;

        return $this->hydrate($record);
    }

    public function setActive(string $publicId, bool $isActive, string $expectedRowVersion, UserId $actorUserId): PriceListRecord
    {
        unset($actorUserId);
        $existing = $this->requireRecord($publicId);
        if ($expectedRowVersion !== $existing['rowVersion']) {
            throw PriceListConcurrencyConflict::forPublicId($publicId);
        }
        $existing['isActive'] = $isActive;
        $existing['rowVersion'] = RowVersionToken::fromBinary(random_bytes(8))->toBase64();
        $this->records[$publicId] = $existing;

        return $this->hydrate($existing);
    }

    public function archive(string $publicId, string $expectedRowVersion, UserId $actorUserId): PriceListRecord
    {
        unset($actorUserId);
        $existing = $this->requireRecord($publicId);
        if ($expectedRowVersion !== $existing['rowVersion']) {
            throw PriceListConcurrencyConflict::forPublicId($publicId);
        }
        $existing['isDeleted'] = true;
        $existing['isActive'] = false;
        $existing['rowVersion'] = RowVersionToken::fromBinary(random_bytes(8))->toBase64();
        $this->records[$publicId] = $existing;

        return $this->hydrate($existing);
    }

    public function restore(string $publicId, string $expectedRowVersion, UserId $actorUserId): PriceListRecord
    {
        unset($actorUserId);
        $existing = $this->records[$publicId] ?? null;
        if ($existing === null) {
            throw PriceListNotFound::forPublicId($publicId);
        }
        if ($expectedRowVersion !== $existing['rowVersion']) {
            throw PriceListConcurrencyConflict::forPublicId($publicId);
        }
        $existing['isDeleted'] = false;
        $existing['isActive'] = true;
        $existing['rowVersion'] = RowVersionToken::fromBinary(random_bytes(8))->toBase64();
        $this->records[$publicId] = $existing;

        return $this->hydrate($existing);
    }

    /**
     * @return array<string, mixed>
     */
    private function requireRecord(string $publicId): array
    {
        $record = $this->records[$publicId] ?? null;
        if ($record === null || ($record['isDeleted'] ?? false)) {
            throw PriceListNotFound::forPublicId($publicId);
        }

        return $record;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function baseRecord(string $publicId, array $data): array
    {
        return [
            'publicId' => $publicId,
            'code' => $data['code'],
            'name' => $data['name'],
            'currencyCode' => 'AUD',
            'currencyPublicId' => $data['currencyPublicId'],
            'regionPublicId' => $data['regionPublicId'] ?? null,
            'regionCode' => null,
            'regionName' => null,
            'marketPublicId' => $data['marketPublicId'] ?? null,
            'marketCode' => null,
            'marketName' => null,
            'legacySourceCode' => $data['legacySourceCode'] ?? null,
            'legacyContextType' => $data['legacyContextType'] ?? null,
            'legacyContextCode' => $data['legacyContextCode'] ?? null,
            'effectiveFromUtc' => $data['effectiveFromUtc'] ?? null,
            'effectiveToUtc' => $data['effectiveToUtc'] ?? null,
            'isActive' => $data['isActive'] ?? true,
            'isDeleted' => false,
            'rowVersion' => RowVersionToken::fromBinary(random_bytes(8))->toBase64(),
        ];
    }

    /**
     * @param array<string, mixed> $record
     */
    private function hydrate(array $record): PriceListRecord
    {
        return new PriceListRecord(
            publicId: (string) $record['publicId'],
            code: (string) $record['code'],
            name: (string) $record['name'],
            currencyCode: (string) ($record['currencyCode'] ?? 'AUD'),
            currencyPublicId: isset($record['currencyPublicId']) ? (string) $record['currencyPublicId'] : null,
            regionPublicId: isset($record['regionPublicId']) ? (string) $record['regionPublicId'] : null,
            regionCode: isset($record['regionCode']) ? (string) $record['regionCode'] : null,
            regionName: isset($record['regionName']) ? (string) $record['regionName'] : null,
            marketPublicId: isset($record['marketPublicId']) ? (string) $record['marketPublicId'] : null,
            marketCode: isset($record['marketCode']) ? (string) $record['marketCode'] : null,
            marketName: isset($record['marketName']) ? (string) $record['marketName'] : null,
            legacySourceCode: isset($record['legacySourceCode']) ? (string) $record['legacySourceCode'] : null,
            legacyContextType: isset($record['legacyContextType']) ? (string) $record['legacyContextType'] : null,
            legacyContextCode: isset($record['legacyContextCode']) ? (string) $record['legacyContextCode'] : null,
            effectiveFromUtc: isset($record['effectiveFromUtc']) ? (string) $record['effectiveFromUtc'] : null,
            effectiveToUtc: isset($record['effectiveToUtc']) ? (string) $record['effectiveToUtc'] : null,
            isActive: (bool) ($record['isActive'] ?? true),
            isDeleted: (bool) ($record['isDeleted'] ?? false),
            rowVersion: (string) $record['rowVersion'],
        );
    }

    private function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
