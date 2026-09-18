<?php

declare(strict_types=1);

namespace TriCab\Api\QuoteOrder\Legacy;

use TriCab\Api\Infrastructure\Database\SqlConnection;
use TriCab\Api\QuoteOrder\Support\LegacyRowValue;
use TriCab\Core\Identity\Application\DTO\AuthenticatedUser;
use TriCab\Core\Product\Migration\Live\LegacyReadOnlyConnection;
use TriCab\Core\QuoteOrder\Contract\QuoteLegacyOperatorIdResolver;
use TriCab\Core\QuoteOrder\Infrastructure\DescriptStaffQuoteLegacyOperatorIdResolver;

/**
 * Resolves sa_op using Azure LegacyStaffUserMappings.LegacyUserLogin when present,
 * then falls back to email-derived candidates against MySQL descript (desc_type=36).
 */
final class AzureLinkedDescriptQuoteLegacyOperatorIdResolver implements QuoteLegacyOperatorIdResolver
{
    private readonly DescriptStaffQuoteLegacyOperatorIdResolver $descript;

    public function __construct(
        private readonly LegacyReadOnlyConnection $legacy,
        private readonly ?SqlConnection $azure = null,
    ) {
        $this->descript = new DescriptStaffQuoteLegacyOperatorIdResolver($legacy);
    }

    public function resolveRaisedByOperatorId(AuthenticatedUser $actor, bool $customerGenerated): ?int
    {
        if ($customerGenerated) {
            return null;
        }

        if ($this->azure !== null) {
            try {
                $rows = $this->azure->fetchAll(
                    'SELECT TOP (3) [LegacyUserLogin]
                     FROM [migration].[LegacyStaffUserMappings]
                     WHERE [NormalizedEmailAddress] = :email
                     ORDER BY [LegacyStaffUserMappingId] DESC',
                    [':email' => strtolower(trim($actor->emailAddress))],
                );
                foreach ($rows as $row) {
                    $login = LegacyRowValue::stringOrNull($row['LegacyUserLogin'] ?? null);
                    if ($login === null) {
                        continue;
                    }
                    $id = $this->lookupDescriptId($login);
                    if ($id !== null) {
                        return $id;
                    }
                }
            } catch (\Throwable) {
                // Fall through to email-derived descript lookup.
            }
        }

        return $this->descript->resolveRaisedByOperatorId($actor, false);
    }

    private function lookupDescriptId(string $login): ?int
    {
        $rows = $this->legacy->query(
            'SELECT desc_id FROM tc.descript
             WHERE desc_type = 36 AND UPPER(TRIM(desc_code)) = UPPER(TRIM(:login))
             LIMIT 2',
            ['login' => $login],
            self::class,
            'lookupDescriptId',
        );
        if (count($rows) !== 1) {
            return null;
        }
        $id = LegacyRowValue::int($rows[0]['desc_id'] ?? null);

        return $id > 0 ? $id : null;
    }
}
