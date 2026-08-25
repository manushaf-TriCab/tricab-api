<?php

declare(strict_types=1);

namespace TriCab\Api\Identity\Passkey;

use Webauthn\Counter\CounterChecker;
use Webauthn\CredentialRecord;
use Webauthn\Exception\CounterException;

/**
 * Cloned-authenticator detection for hardware authenticators.
 *
 * Synced platform passkeys commonly report signCount=0. That is not treated as
 * a clone. A strictly decreasing positive counter is rejected.
 */
final class SyncedPasskeyCounterChecker implements CounterChecker
{
    public function check(CredentialRecord $credentialRecord, int $currentCounter): void
    {
        $stored = $credentialRecord->counter;
        if ($stored > 0 && $currentCounter > 0 && $currentCounter < $stored) {
            throw CounterException::create($currentCounter, $stored, 'Authenticator counter decreased.');
        }
    }
}
