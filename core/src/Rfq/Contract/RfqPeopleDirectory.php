<?php

declare(strict_types=1);

namespace TriCab\Core\Rfq\Contract;

use TriCab\Core\Rfq\Application\DTO\RfqPerson;

/**
 * Active platform users, for assigning an RFQ (parity plan E6).
 *
 * The store names assignees by email; the browser names them by identity
 * PublicId. This is the only place the two meet, on the server.
 */
interface RfqPeopleDirectory
{
    /** The active user with this identity PublicId (a GUID), or null if there is none. */
    public function find(string $publicId): ?RfqPerson;

    /**
     * Active users whose display name contains `$query` or whose email starts
     * with it, by name. An empty query lists from the top.
     *
     * @return list<RfqPerson>
     */
    public function search(string $query, int $limit): array;
}
