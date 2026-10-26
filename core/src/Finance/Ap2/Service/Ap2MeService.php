<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ap2\Service;

use TriCab\Core\Finance\Ap2\Contract\Ap2SettingsStore;
use TriCab\Core\Finance\Ap2\Domain\Ap2Actor;

/** `GET /me`: the person, the Tri-AP2 permission codes they hold (full codes), the settings and what is switched on (`ai` is true only when a real AI implementation is bound, not the Null ones). */
final class Ap2MeService
{
    public function __construct(
        private readonly Ap2SettingsStore $settings,
        private readonly bool $sendingEnabled,
        private readonly bool $odooPostingEnabled,
        private readonly string $mailbox,
        private readonly bool $aiConnected = false,
    ) {
    }

    /** @return array<string, mixed> */
    public function me(Ap2Actor $actor): array
    {
        $held = array_values(array_filter($actor->permissions, static fn (string $p): bool => str_starts_with($p, 'FINANCE.AP2.')));
        sort($held);
        $s = $this->settings->get();

        return [
            'user' => $actor->person()->toArray(),
            'permissions' => $held,
            'settings' => Ap2Presenter::settings($s, $this->odooPostingEnabled, $this->mailbox),
            'flags' => ['sending' => $this->sendingEnabled, 'odooPosting' => $this->odooPostingEnabled, 'ai' => $this->aiConnected],
        ];
    }
}
