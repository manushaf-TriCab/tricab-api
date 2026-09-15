<?php

declare(strict_types=1);

namespace TriCab\Core\TelDirectory\Application\Service;

use TriCab\Core\TelDirectory\Application\DTO\TelDirectoryEntry;

/**
 * Maps Microsoft Graph user rows → Tel Directory DTO (legacy display rules).
 */
final class TelDirectoryMapper
{
    public function __construct(
        private readonly TelDirectoryFilter $filter = new TelDirectoryFilter(),
    ) {
    }

    /**
     * @param array<string, mixed> $graphUser
     */
    public function map(array $graphUser): ?TelDirectoryEntry
    {
        if (!$this->filter->accepts($graphUser)) {
            return null;
        }

        $email = $this->filter->normaliseEmail((string) ($graphUser['mail'] ?? ''));
        $name = trim((string) ($graphUser['displayName'] ?? ''));
        $jobTitle = trim((string) ($graphUser['jobTitle'] ?? ''));
        $location = $this->nullableTrim((string) ($graphUser['officeLocation'] ?? ''));
        $mobile = $this->nullableTrim((string) ($graphUser['mobilePhone'] ?? ''));
        $businessPhone = $this->firstBusinessPhone($graphUser);
        $telephone = $this->stripExtension($businessPhone);

        if ($this->filter->isSpecialDualMobileEmail($email)) {
            // Legacy: hide Telephone column; show mobile + business phones as Mobile links.
            return new TelDirectoryEntry(
                name: $name !== '' ? $name : $email,
                jobTitle: $jobTitle,
                email: $email,
                mobile: $mobile,
                telephone: null,
                location: $location,
                mobileSecondary: $this->nullableTrim($businessPhone ?? ''),
            );
        }

        return new TelDirectoryEntry(
            name: $name !== '' ? $name : $email,
            jobTitle: $jobTitle,
            email: $email,
            mobile: $mobile,
            telephone: $this->nullableTrim($telephone ?? ''),
            location: $location,
            mobileSecondary: null,
        );
    }

    /**
     * Legacy: explode on lowercase "x" and keep the part before the extension.
     */
    public function stripExtension(?string $telephoneNumber): ?string
    {
        if ($telephoneNumber === null) {
            return null;
        }
        $trimmed = trim($telephoneNumber);
        if ($trimmed === '') {
            return null;
        }
        $parts = explode('x', $trimmed, 2);

        return trim($parts[0]);
    }

    /**
     * @param array<string, mixed> $graphUser
     */
    private function firstBusinessPhone(array $graphUser): ?string
    {
        $phones = $graphUser['businessPhones'] ?? null;
        if (!is_array($phones) || $phones === []) {
            return null;
        }
        $first = $phones[0] ?? null;
        if (!is_string($first)) {
            return null;
        }
        $trimmed = trim($first);

        return $trimmed !== '' ? $trimmed : null;
    }

    private function nullableTrim(string $value): ?string
    {
        $trimmed = trim($value);

        return $trimmed !== '' ? $trimmed : null;
    }
}
