<?php

declare(strict_types=1);

namespace TriCab\Api\Hr\Employees\Directory;

use TriCab\Core\Identity\Application\DTO\UserManagement\ActorContext;

/**
 * Reads and edits the department map. Validation lives here; the store owns the
 * transaction and the audit row. Editing is gated on HR.EMPLOYEES.SYNC at the
 * route (see HrPermissions), reading on HR.EMPLOYEES.READ.
 */
final class DepartmentMapService
{
    public const MAX_RAW = 100;
    public const MAX_CLEAN_NAME = 100;
    public const MAX_COST_CENTRE = 40;

    public function __construct(private readonly DepartmentMapStore $store)
    {
    }

    /**
     * @return array{entries: list<array<string, mixed>>, summary: array{unmapped: int, total: int}}
     */
    public function list(): array
    {
        $entries = $this->store->entries();
        $unmapped = count(array_filter($entries, static fn (array $entry): bool => !$entry['mapped'] && $entry['employees'] > 0));

        return ['entries' => $entries, 'summary' => ['unmapped' => $unmapped, 'total' => count($entries)]];
    }

    /**
     * Both names blank removes the mapping.
     *
     * @return array{kind: string, raw: string, cleanName: ?string, costCentre: ?string, mapped: bool, changed: bool}
     *
     * @throws DepartmentMapRefused
     */
    public function save(ActorContext $actor, string $kind, string $raw, ?string $cleanName, ?string $costCentre): array
    {
        if (!in_array($kind, DepartmentMapStore::KINDS, true)) {
            throw DepartmentMapRefused::invalid('kind must be "department" or "factory".');
        }
        $raw = trim($raw);
        if ($raw === '' || mb_strlen($raw) > self::MAX_RAW) {
            throw DepartmentMapRefused::invalid('raw is required (up to ' . self::MAX_RAW . ' characters).');
        }
        $cleanName = self::blankToNull($cleanName);
        $costCentre = self::blankToNull($costCentre);
        if ($cleanName !== null && mb_strlen($cleanName) > self::MAX_CLEAN_NAME) {
            throw DepartmentMapRefused::invalid('cleanName is up to ' . self::MAX_CLEAN_NAME . ' characters.');
        }
        if ($costCentre !== null && mb_strlen($costCentre) > self::MAX_COST_CENTRE) {
            throw DepartmentMapRefused::invalid('costCentre is up to ' . self::MAX_COST_CENTRE . ' characters.');
        }
        if ($cleanName === null && $costCentre !== null) {
            throw DepartmentMapRefused::invalid('A cost centre needs a clean name; clear both to remove the mapping.');
        }

        return $this->store->save($actor, $kind, $raw, $cleanName, $costCentre);
    }

    private static function blankToNull(?string $value): ?string
    {
        $value = $value === null ? null : trim($value);

        return $value === null || $value === '' ? null : $value;
    }
}
