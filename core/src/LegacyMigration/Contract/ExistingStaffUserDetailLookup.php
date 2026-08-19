<?php

declare(strict_types=1);

namespace TriCab\Core\LegacyMigration\Contract;

/**
 * SELECT-only lookup of existing identity.Users details for staff migration review.
 */
interface ExistingStaffUserDetailLookup
{
    /**
     * @param list<string> $normalizedEmails
     * @return array<string, array{
     *   userId: int,
     *   publicId: string,
     *   emailAddress: string,
     *   normalizedEmailAddress: string,
     *   displayName: string|null,
     *   statusCode: string,
     *   roleCodes: list<string>,
     *   externalProviders: list<string>
     * }>
     */
    public function byNormalizedEmails(array $normalizedEmails): array;
}
