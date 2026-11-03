<?php

declare(strict_types=1);

namespace TriCab\Api\Identity\Groups;

/** A user that Entra reports as a (transitive) member of a group. */
final readonly class EntraMember
{
    public function __construct(
        public string $objectId,
        public string $displayName,
        public ?string $mail,
        public ?string $userPrincipalName,
        public bool $accountEnabled = true,
    ) {
    }

    /** @return list<string> Addresses to match a platform user on, best first. */
    public function emails(): array
    {
        $found = [];
        foreach ([$this->mail, $this->userPrincipalName] as $candidate) {
            $email = strtolower(trim((string) $candidate));
            if ($email !== '' && !in_array($email, $found, true)) {
                $found[] = $email;
            }
        }

        return $found;
    }
}
