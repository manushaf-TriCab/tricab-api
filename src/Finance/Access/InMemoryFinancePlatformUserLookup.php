<?php

declare(strict_types=1);

namespace TriCab\Api\Finance\Access;

use TriCab\Core\Finance\Shared\Access\FinancePlatformUserLookup;

/** Platform users by email, held in memory: the tests'. */
final class InMemoryFinancePlatformUserLookup implements FinancePlatformUserLookup
{
    /**
     * @param array<string, list<int>> $byEmail lower-case email => the platform users that hold it
     */
    public function __construct(private readonly array $byEmail = [])
    {
    }

    public function userIdsByEmail(array $emails): array
    {
        $out = [];
        foreach ($emails as $email) {
            if (isset($this->byEmail[$email])) {
                $out[$email] = $this->byEmail[$email];
            }
        }

        return $out;
    }
}
