<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Service;

use TriCab\Core\Identity\Application\DTO\UserManagement\ActorContext;
use TriCab\Core\Product\Application\Command\RegionStatusCommand;
use TriCab\Core\Product\Application\Command\UpsertRegionCommand;
use TriCab\Core\Product\Application\DTO\RegionRecord;
use TriCab\Core\Product\Application\DTO\RegionWriteData;
use TriCab\Core\Product\Application\Exception\DuplicateRegionCode;
use TriCab\Core\Product\Application\Exception\InvalidRegion;
use TriCab\Core\Product\Application\Exception\RegionInUse;
use TriCab\Core\Product\Application\Exception\RegionNotFound;
use TriCab\Core\Product\Contract\RegionRepository;
use TriCab\Core\Shared\Persistence\ApplicationTransaction;

final class ManageRegionService
{
    public function __construct(
        private readonly RegionRepository $regions,
        private readonly ApplicationTransaction $transaction,
        private readonly ProductAuditRecorder $audit,
    ) {
    }

    public function create(ActorContext $actor, UpsertRegionCommand $command): RegionRecord
    {
        return $this->transaction->run(function () use ($actor, $command): RegionRecord {
            $data = $this->validate($command);
            $this->assertUnique($data, null);
            $created = $this->regions->create($data, $this->audit->actorUserId($actor));
            $this->record($actor, 'created', $created);

            return $created;
        });
    }

    public function update(ActorContext $actor, UpsertRegionCommand $command): RegionRecord
    {
        return $this->transaction->run(function () use ($actor, $command): RegionRecord {
            $publicId = trim((string) $command->publicId);
            $rowVersion = trim((string) $command->expectedRowVersion);
            if ($publicId === '' || $rowVersion === '') {
                throw InvalidRegion::field('concurrency', 'publicId and expectedRowVersion are required for update.');
            }
            $this->requireRegion($publicId);
            $data = $this->validate($command);
            $this->assertUnique($data, $publicId);
            $updated = $this->regions->update(
                $publicId,
                $data,
                $rowVersion,
                $this->audit->actorUserId($actor),
            );
            $this->record($actor, 'updated', $updated);

            return $updated;
        });
    }

    public function activate(ActorContext $actor, RegionStatusCommand $command): RegionRecord
    {
        return $this->setActive($actor, $command, true);
    }

    public function deactivate(ActorContext $actor, RegionStatusCommand $command): RegionRecord
    {
        $region = $this->requireRegion($command->publicId);
        if ($region->isDefault || $region->defaultWebsiteRegion) {
            throw InvalidRegion::field('isActive', 'a default region cannot be deactivated.');
        }

        return $this->setActive($actor, $command, false);
    }

    public function delete(ActorContext $actor, RegionStatusCommand $command): RegionRecord
    {
        return $this->transaction->run(function () use ($actor, $command): RegionRecord {
            $region = $this->requireRegion($command->publicId);
            if ($region->isDefault || $region->defaultWebsiteRegion) {
                throw InvalidRegion::field('isDefault', 'unset default status before deleting this region.');
            }
            $usage = $this->regions->usage($command->publicId);
            if ($usage->isUsed()) {
                throw new RegionInUse($usage);
            }
            $deleted = $this->regions->softDelete(
                $command->publicId,
                $this->requireRowVersion($command->expectedRowVersion),
                $this->audit->actorUserId($actor),
            );
            $this->record($actor, 'deleted', $deleted);

            return $deleted;
        });
    }

    private function setActive(ActorContext $actor, RegionStatusCommand $command, bool $active): RegionRecord
    {
        return $this->transaction->run(function () use ($actor, $command, $active): RegionRecord {
            $this->requireRegion($command->publicId);
            $updated = $this->regions->setActive(
                $command->publicId,
                $active,
                $this->requireRowVersion($command->expectedRowVersion),
                $this->audit->actorUserId($actor),
            );
            $this->record($actor, $active ? 'activated' : 'deactivated', $updated);

            return $updated;
        });
    }

    private function requireRegion(string $publicId): RegionRecord
    {
        $region = $this->regions->findByPublicId($publicId);
        if ($region === null || $region->isDeleted) {
            throw RegionNotFound::forPublicId($publicId);
        }

        return $region;
    }

    private function assertUnique(RegionWriteData $data, ?string $excludePublicId): void
    {
        if ($this->regions->codeExists($data->code, $excludePublicId)) {
            throw DuplicateRegionCode::forCode($data->code);
        }
        if ($data->isDefault && $this->regions->anotherDefaultExists($excludePublicId)) {
            throw InvalidRegion::field('isDefault', 'only one default region is allowed.');
        }
        if ($data->defaultWebsiteRegion && $this->regions->anotherDefaultWebsiteRegionExists($excludePublicId)) {
            throw InvalidRegion::field('defaultWebsiteRegion', 'only one default website region is allowed.');
        }
    }

    private function validate(UpsertRegionCommand $command): RegionWriteData
    {
        $code = strtoupper(trim($command->code));
        if (preg_match('/^[A-Z0-9_-]{2,20}$/', $code) !== 1) {
            throw InvalidRegion::field('code', 'must be uppercase, 2-20 characters, using A-Z, 0-9, "_" or "-".');
        }
        $name = $this->required($command->name, 'name', 100);
        $displayName = $command->displayName === null
            ? $name
            : $this->required($command->displayName, 'displayName', 150);
        if ($command->displayOrder < 0) {
            throw InvalidRegion::field('displayOrder', 'must be zero or greater.');
        }
        if (($command->isDefault || $command->defaultWebsiteRegion)
            && (!$command->isActive || !$command->websiteEnabled || !$command->showInRegionPicker)
        ) {
            throw InvalidRegion::field('default', 'a default must be active, website enabled, and shown in the picker.');
        }
        $themeColour = $this->optional($command->themeColour, 'themeColour', 20);
        if ($themeColour !== null && preg_match('/^#[0-9A-Fa-f]{6}$/', $themeColour) !== 1) {
            throw InvalidRegion::field('themeColour', 'must be a six-digit hex colour.');
        }
        $email = $this->optional($command->email, 'email', 254);
        if ($email !== null && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw InvalidRegion::field('email', 'must be a valid email address.');
        }

        return new RegionWriteData(
            code: $code,
            name: $name,
            displayName: $displayName,
            flag: $this->optional($command->flag, 'flag', 20),
            themeColour: $themeColour,
            displayOrder: $command->displayOrder,
            isDefault: $command->isDefault,
            isActive: $command->isActive,
            websiteEnabled: $command->websiteEnabled,
            showInRegionPicker: $command->showInRegionPicker,
            defaultWebsiteRegion: $command->defaultWebsiteRegion,
            countryCodes: $this->countryCodes($command->countryCodes),
            currencyCode: $this->fixedUpper($command->currencyCode, 'currencyCode', 3),
            timezoneId: $this->optional($command->timezoneId, 'timezoneId', 100),
            languageCode: $this->optional($command->languageCode, 'languageCode', 10),
            office: $this->optional($command->office, 'office', 500),
            phone: $this->optional($command->phone, 'phone', 50),
            email: $email,
            salesContact: $this->optional($command->salesContact, 'salesContact', 200),
        );
    }

    /**
     * @param list<string> $values
     * @return list<string>
     */
    private function countryCodes(array $values): array
    {
        $result = [];
        foreach ($values as $value) {
            $code = strtoupper(trim($value));
            if (preg_match('/^[A-Z]{2}$/', $code) !== 1) {
                throw InvalidRegion::field('countryCodes', sprintf('"%s" is not an ISO alpha-2 code.', $value));
            }
            $result[$code] = $code;
        }

        return array_values($result);
    }

    private function fixedUpper(?string $value, string $field, int $length): ?string
    {
        $normalized = $this->optional($value, $field, $length);
        if ($normalized === null) {
            return null;
        }
        $normalized = strtoupper($normalized);
        if (strlen($normalized) !== $length || preg_match('/^[A-Z]+$/', $normalized) !== 1) {
            throw InvalidRegion::field($field, sprintf('must be %d letters.', $length));
        }

        return $normalized;
    }

    private function required(string $value, string $field, int $max): string
    {
        $normalized = trim($value);
        if ($normalized === '' || strlen($normalized) > $max) {
            throw InvalidRegion::field($field, sprintf('must be nonempty and at most %d characters.', $max));
        }

        return $normalized;
    }

    private function optional(?string $value, string $field, int $max): ?string
    {
        if ($value === null) {
            return null;
        }
        $normalized = trim($value);
        if ($normalized === '') {
            return null;
        }
        if (strlen($normalized) > $max) {
            throw InvalidRegion::field($field, sprintf('must be at most %d characters.', $max));
        }

        return $normalized;
    }

    private function requireRowVersion(string $rowVersion): string
    {
        if (trim($rowVersion) === '') {
            throw InvalidRegion::field('expectedRowVersion', 'is required.');
        }

        return $rowVersion;
    }

    private function record(ActorContext $actor, string $action, RegionRecord $region): void
    {
        $this->audit->record(
            actor: $actor,
            productPublicId: null,
            entityTypeCode: 'region',
            entityId: null,
            actionCode: $action,
            newValues: ['publicId' => $region->publicId, 'code' => $region->code],
        );
    }
}
