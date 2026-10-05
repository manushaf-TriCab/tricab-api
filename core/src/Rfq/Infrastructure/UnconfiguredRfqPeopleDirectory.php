<?php

declare(strict_types=1);

namespace TriCab\Core\Rfq\Infrastructure;

use TriCab\Core\Rfq\Application\DTO\RfqPerson;
use TriCab\Core\Rfq\Contract\RfqPeopleDirectory;

/** No Azure SQL (`USER_REPOSITORY=memory`): nobody to pick, and no id resolves. */
final class UnconfiguredRfqPeopleDirectory implements RfqPeopleDirectory
{
    public function find(string $publicId): ?RfqPerson
    {
        return null;
    }

    public function search(string $query, int $limit): array
    {
        return [];
    }
}
