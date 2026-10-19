<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Npr\Support;

use TriCab\Core\Npr\Mail\NprMailFailed;
use TriCab\Core\Npr\Mail\NprMailMessage;
use TriCab\Core\Npr\Mail\NprMailTransport;
use TriCab\Core\Npr\Mail\NprMailWake;

/** A mock transport: keeps what would have been sent, never sends. */
final class RecordingNprMailTransport implements NprMailTransport, NprMailWake
{
    /** @var list<NprMailMessage> */
    public array $sent = [];

    public bool $failing = false;

    public int $wakes = 0;

    public function send(NprMailMessage $message): void
    {
        if ($this->failing) {
            throw new NprMailFailed('Microsoft Graph sendMail answered 503.');
        }
        $this->sent[] = $message;
    }

    public function signal(): void
    {
        $this->wakes++;
    }
}
