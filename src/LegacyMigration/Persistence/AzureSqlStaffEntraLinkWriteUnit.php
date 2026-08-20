<?php

declare(strict_types=1);

namespace TriCab\Api\LegacyMigration\Persistence;

use TriCab\Api\Identity\Persistence\AzureSqlTransaction;
use TriCab\Api\Identity\Persistence\AzureSqlValueMapper;
use TriCab\Api\Infrastructure\Database\SqlConnection;
use TriCab\Core\Identity\Domain\ValueObject\ExternalProvider;
use TriCab\Core\Identity\Domain\ValueObject\UserStatus;
use TriCab\Core\LegacyMigration\Contract\StaffEntraLinkWriteUnit;

final class AzureSqlStaffEntraLinkWriteUnit implements StaffEntraLinkWriteUnit
{
    use AzureSqlTransaction;

    public function __construct(private readonly SqlConnection $connection)
    {
    }

    public function linkEntraIdentity(array $link): array
    {
        return $this->runInTransaction(
            $this->connection,
            function () use ($link): array {
                $userId = (int) ($link['userId'] ?? 0);
                $subject = trim((string) ($link['subjectIdentifier'] ?? ''));
                if ($userId <= 0 || $subject === '') {
                    throw new \InvalidArgumentException('Invalid Entra link payload.');
                }

                $existing = $this->connection->fetchAll(
                    <<<'SQL'
SELECT TOP 1 e.[UserId]
FROM [identity].[ExternalIdentities] e
WHERE e.[IsActive] = 1
  AND (e.[ProviderCode] = :providerA OR e.[ProviderCode] = :providerB)
  AND e.[SubjectIdentifier] = :subject
SQL,
                    [
                        ':providerA' => ExternalProvider::MICROSOFT_ENTRA,
                        ':providerB' => str_replace('_', '-', ExternalProvider::MICROSOFT_ENTRA),
                        ':subject' => $subject,
                    ],
                );
                if ($existing !== []) {
                    $linkedUserId = AzureSqlValueMapper::intValue($existing[0]['UserId'] ?? 0);
                    if ($linkedUserId === $userId) {
                        $providerNormalized = $this->normalizeExistingLink($userId, $subject);
                        $userActivated = $this->activatePendingUserIfRequested(
                            $userId,
                            (bool) ($link['activateIfPending'] ?? false),
                        );

                        return [
                            'externalIdentityCreated' => false,
                            'providerNormalized' => $providerNormalized,
                            'userActivated' => $userActivated,
                        ];
                    }
                    throw new \RuntimeException('ENTRA_OID_CONFLICT: subject already linked to UserId ' . $linkedUserId);
                }

                $userRows = $this->connection->fetchAll(
                    'SELECT TOP 1 [UserId] FROM [identity].[Users] WHERE [UserId] = :userId',
                    [':userId' => $userId],
                );
                if ($userRows === []) {
                    throw new \RuntimeException('TriCab user not found: ' . $userId);
                }

                $this->connection->execute(
                    <<<'SQL'
INSERT INTO [identity].[ExternalIdentities] (
    [UserId],
    [ProviderCode],
    [TenantIdentifier],
    [SubjectIdentifier],
    [EmailClaim],
    [NormalizedEmailClaim],
    [IsActive]
)
VALUES (
    :userId,
    :provider,
    :tenant,
    :subject,
    :emailClaim,
    :normalizedEmailClaim,
    1
)
SQL,
                    [
                        ':userId' => $userId,
                        ':provider' => ExternalProvider::MICROSOFT_ENTRA,
                        ':tenant' => (string) ($link['tenantIdentifier'] ?? ''),
                        ':subject' => $subject,
                        ':emailClaim' => (string) ($link['emailClaim'] ?? ''),
                        ':normalizedEmailClaim' => strtolower((string) ($link['normalizedEmailClaim'] ?? '')),
                    ],
                );

                return [
                    'externalIdentityCreated' => true,
                    'providerNormalized' => false,
                    'userActivated' => $this->activatePendingUserIfRequested($userId, (bool) ($link['activateIfPending'] ?? false)),
                ];
            },
        );
    }

    private function normalizeExistingLink(int $userId, string $subject): bool
    {
        $rows = $this->connection->fetchAll(
            <<<'SQL'
SELECT TOP 1 e.[ExternalIdentityId], e.[ProviderCode]
FROM [identity].[ExternalIdentities] e
WHERE e.[IsActive] = 1
  AND e.[UserId] = :userId
  AND e.[SubjectIdentifier] = :subject
  AND (e.[ProviderCode] = :providerA OR e.[ProviderCode] = :providerB)
SQL,
            [
                ':userId' => $userId,
                ':subject' => $subject,
                ':providerA' => ExternalProvider::MICROSOFT_ENTRA,
                ':providerB' => str_replace('_', '-', ExternalProvider::MICROSOFT_ENTRA),
            ],
        );
        if ($rows === []) {
            return false;
        }

        $providerCode = strtolower(str_replace('-', '_', trim((string) ($rows[0]['ProviderCode'] ?? ''))));
        if ($providerCode === ExternalProvider::MICROSOFT_ENTRA) {
            return false;
        }

        $this->connection->execute(
            <<<'SQL'
UPDATE [identity].[ExternalIdentities]
SET [ProviderCode] = :provider
WHERE [ExternalIdentityId] = :externalIdentityId
  AND [ProviderCode] <> :provider
SQL,
            [
                ':provider' => ExternalProvider::MICROSOFT_ENTRA,
                ':externalIdentityId' => AzureSqlValueMapper::intValue($rows[0]['ExternalIdentityId'] ?? 0),
            ],
        );

        return true;
    }

    private function activatePendingUserIfRequested(int $userId, bool $activateIfPending): bool
    {
        if (!$activateIfPending) {
            return false;
        }

        $rows = $this->connection->fetchAll(
            <<<'SQL'
UPDATE u
SET
    u.[UserStatusId] = activeStatus.[UserStatusId],
    u.[UpdatedAt] = SYSDATETIMEOFFSET()
OUTPUT INSERTED.[UserId]
FROM [identity].[Users] u
INNER JOIN [identity].[UserStatuses] pendingStatus ON pendingStatus.[UserStatusId] = u.[UserStatusId]
    AND pendingStatus.[StatusCode] = :pendingStatus
CROSS JOIN [identity].[UserStatuses] activeStatus
    WHERE activeStatus.[StatusCode] = :activeStatus
  AND u.[UserId] = :userId
SQL,
            [
                ':userId' => $userId,
                ':pendingStatus' => UserStatus::PENDING,
                ':activeStatus' => UserStatus::ACTIVE,
            ],
        );

        return $rows !== [];
    }
}
