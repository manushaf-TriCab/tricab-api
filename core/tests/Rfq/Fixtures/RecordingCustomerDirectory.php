<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\Rfq\Fixtures;

use TriCab\Core\Rfq\Application\DTO\RfqContactOption;
use TriCab\Core\Rfq\Application\DTO\RfqCustomerOption;
use TriCab\Core\Rfq\Contract\RfqCustomerDirectory;

/** Records what it was asked, and answers one customer. */
final class RecordingCustomerDirectory implements RfqCustomerDirectory
{
    /** @var list<array{string, int}> */
    public array $asked = [];

    public function search(string $query, int $limit): array
    {
        $this->asked[] = [$query, $limit];

        return [new RfqCustomerOption(37193, 'A&HCON', 'A & H Controls Pte Ltd', 'ASIA', 'MEL')];
    }

    public function contacts(int $customerId, int $limit): array
    {
        $this->asked[] = [(string) $customerId, $limit];

        return [];
    }

    public function find(int $customerId): ?RfqCustomerOption
    {
        return $customerId === 37193 ? new RfqCustomerOption(37193, 'A&HCON', 'A & H Controls Pte Ltd', 'ASIA', 'MEL') : null;
    }
}
