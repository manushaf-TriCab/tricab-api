<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ar\Service;

use TriCab\Core\Finance\Ar\Contract\ArAssigneeDirectory;
use TriCab\Core\Finance\Ar\Domain\ArActor;
use TriCab\Core\Finance\Ar\Domain\ArAssignee;
use TriCab\Core\Finance\Module;

/**
 * `GET /me` and `GET /assignees` (source routes 5 and 11).
 *
 * `/me` carries what the source's `/api/config` gave the sidebar as well (decision D50, question
 * Q12): the mailbox and the Tri-Credit Notes link, from settings.
 */
final class ArMeService
{
    public function __construct(
        private readonly ArAssigneeDirectory $assignees,
        private readonly ArTime $time,
        private readonly string $mailbox,
        private readonly string $creditNotesUrl,
        private readonly bool $sendingEnabled = false,
    ) {
    }

    /**
     * The portal's own permission strings, derived from the platform codes by
     * `Module::AR_PORTAL_PERMISSIONS`, so `Settings.jsx` (`ar.admin`) and the pages' checks work unchanged.
     *
     * @return array<string, mixed>
     */
    public function me(ArActor $actor): array
    {
        $portal = [];
        foreach (Module::AR_PORTAL_PERMISSIONS as $code => $string) {
            if ($actor->can($code)) {
                $portal[] = $string;
            }
        }
        sort($portal);
        $name = $actor->name !== '' ? $actor->name : $actor->email;

        return [
            'id' => (string) $actor->userId,
            'name' => $name,
            'full_name' => $name,
            'email' => strtolower($actor->email),
            'permissions' => $portal,
            'mailbox' => $this->mailbox,
            'credit_notes_url' => $this->creditNotesUrl,
            'timezone' => $this->time->zoneName(),
            'sending_enabled' => $this->sendingEnabled,
        ];
    }

    /** @return list<array<string, string>> */
    public function assignees(): array
    {
        return array_map(static fn (ArAssignee $a): array => $a->toArray(), $this->assignees->assignees());
    }
}
