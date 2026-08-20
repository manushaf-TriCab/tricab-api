<?php

declare(strict_types=1);

namespace TriCab\Core\LegacyMigration\Contract;

interface StaffEntraLinkWriteUnit
{
    /**
     * Creates ExternalIdentity and optionally activates a pending user in one transaction.
     *
     * @param array{
     *   userId: int,
     *   providerCode: string,
     *   tenantIdentifier: string,
     *   subjectIdentifier: string,
     *   emailClaim: string,
     *   normalizedEmailClaim: string,
     *   activateIfPending: bool
     * } $link
     * @return array{externalIdentityCreated: bool, userActivated: bool}
     */
    public function linkEntraIdentity(array $link): array;
}
