<?php

declare(strict_types=1);

namespace TriCab\Core\Rfq\Application\DTO;

use TriCab\Core\Rfq\Domain\ValueObject\RfqActor;

/**
 * `GET /api/v1/rfqs/me` — contract `rfq.access.v1`. What the signed-in person
 * may do in the module, as the server resolves it on this request.
 *
 * Replaces the scaffold's `useRfqMe`, which invented a person ("David Smith"),
 * a branch (`VIC`) and a role (platform `ADMIN` became RFQ `developer`) in the
 * browser. Nothing here is cached by the server between requests, so a
 * revoked grant is gone on the next call (invariant 1, source quirk Q14).
 *
 * There is no scope in it: everyone who reads RFQs reads all of them (owner
 * decision, 28 Sep 2026). `branch` is the person's own branch, for a default
 * queue filter; `branch.source` says where it came from, `unavailable` until
 * platform identity carries branch membership (Q7).
 */
final class RfqAccessSummary
{
    public const BRANCH_SOURCE_UNAVAILABLE = 'unavailable';
    public const BRANCH_SOURCE_DIRECTORY = 'directory';

    public function __construct(
        private readonly RfqActor $actor,
        private readonly string $branchSource,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'permissions' => $this->actor->rfqPermissions(),
            'branch' => [
                'code' => $this->actor->branchCode,
                'source' => $this->branchSource,
            ],
        ];
    }
}
