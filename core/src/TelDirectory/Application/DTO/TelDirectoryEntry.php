<?php

declare(strict_types=1);

namespace TriCab\Core\TelDirectory\Application\DTO;

/**
 * Mobile-facing Tel Directory contact. Never includes Graph secrets or CustomAttribute1.
 */
final class TelDirectoryEntry
{
    public function __construct(
        public readonly string $name,
        public readonly string $jobTitle,
        public readonly string $email,
        public readonly ?string $mobile,
        public readonly ?string $telephone,
        public readonly ?string $location,
        /**
         * Second dialable number for legacy ag@tricab.com dual-mobile behaviour.
         * Null for all other contacts.
         */
        public readonly ?string $mobileSecondary = null,
    ) {
    }

    /**
     * @return array{
     *   name: string,
     *   jobTitle: string,
     *   email: string,
     *   mobile: string|null,
     *   telephone: string|null,
     *   location: string|null,
     *   mobileSecondary?: string
     * }
     */
    public function toArray(): array
    {
        $payload = [
            'name' => $this->name,
            'jobTitle' => $this->jobTitle,
            'email' => $this->email,
            'mobile' => $this->mobile,
            'telephone' => $this->telephone,
            'location' => $this->location,
        ];
        if ($this->mobileSecondary !== null && $this->mobileSecondary !== '') {
            $payload['mobileSecondary'] = $this->mobileSecondary;
        }

        return $payload;
    }
}
