<?php

declare(strict_types=1);

namespace TriCab\Api\Rfq\Persistence;

use TriCab\Api\Identity\Persistence\AzureSqlValueMapper;
use TriCab\Api\Infrastructure\Database\SqlConnection;
use TriCab\Core\Identity\Domain\ValueObject\UserStatus;
use TriCab\Core\Rfq\Application\DTO\RfqPerson;
use TriCab\Core\Rfq\Contract\RfqPeopleDirectory;

/**
 * Active users from `[identity].[Users]`, for the workflow panel's owner
 * picker and for resolving an id to the address the store keys assignees by.
 *
 * Active means the user's status is `active`: a pending or disabled account
 * cannot be given work. The address returned is the user's primary,
 * normalised (lower-case) one, which is what the queue's `people` CTE matches.
 */
final class AzureSqlRfqPeopleDirectory implements RfqPeopleDirectory
{
    private const GUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

    private const SELECT = '
        SELECT %s u.[PublicId], u.[DisplayName], u.[NormalizedEmailAddress]
        FROM [identity].[Users] u
        INNER JOIN [identity].[UserStatuses] s ON s.[UserStatusId] = u.[UserStatusId] AND s.[StatusCode] = :active';

    public function __construct(private readonly SqlConnection $connection)
    {
    }

    public function find(string $publicId): ?RfqPerson
    {
        $publicId = strtolower(trim($publicId));
        if (preg_match(self::GUID, $publicId) !== 1) {
            return null;
        }
        $row = $this->connection->fetchAll(
            sprintf(self::SELECT, '') . ' WHERE u.[PublicId] = :id',
            ['active' => UserStatus::ACTIVE, 'id' => $publicId],
        )[0] ?? null;

        return $row === null ? null : self::person($row);
    }

    public function search(string $query, int $limit): array
    {
        $query = trim($query);
        $params = ['active' => UserStatus::ACTIVE];
        $where = '';
        if ($query !== '') {
            // `!` escapes, as in the queue's search: PDO misreads a backslash in a literal.
            $escaped = strtr($query, ['!' => '!!', '%' => '!%', '_' => '!_', '[' => '![']);
            $where = " WHERE u.[DisplayName] LIKE :contains ESCAPE '!' OR u.[NormalizedEmailAddress] LIKE :prefix ESCAPE '!'";
            $params['contains'] = '%' . $escaped . '%';
            $params['prefix'] = strtolower($escaped) . '%';
        }
        $rows = $this->connection->fetchAll(
            sprintf(self::SELECT, 'TOP (' . max(1, $limit) . ')') . $where . ' ORDER BY u.[DisplayName], u.[UserId]',
            $params,
        );

        return array_map(self::person(...), $rows);
    }

    /** @param array<string, mixed> $row */
    private static function person(array $row): RfqPerson
    {
        return new RfqPerson(
            strtolower(AzureSqlValueMapper::stringValue($row['PublicId'])),
            AzureSqlValueMapper::nullableString($row['DisplayName']) ?? 'Unnamed user',
            strtolower(AzureSqlValueMapper::stringValue($row['NormalizedEmailAddress'])),
        );
    }
}
