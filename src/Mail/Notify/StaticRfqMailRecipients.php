<?php

declare(strict_types=1);

namespace TriCab\Api\Mail\Notify;

/** A fixed list: the in-memory default and tests. */
final class StaticRfqMailRecipients implements RfqMailRecipients
{
    /** @param list<int> $userIds */
    public function __construct(private readonly array $userIds = [])
    {
    }

    public function userIds(): array
    {
        return $this->userIds;
    }
}
