<?php

declare(strict_types=1);

namespace TriCab\Core\Npr\Application\Service;

/**
 * Who may be nominated to sign a screening decision. TSD has not named the
 * signatories (npr-portal `operational-config.json`: `screeningSignatoryStaffIds`
 * is empty and `signing_policy` is pending), so there is no default: until
 * this list is configured, requesting screening and deciding are blocked.
 */
final class NprSigningPolicy
{
    /** @var list<string> */
    private readonly array $emails;

    /** @param list<string> $nominatedEmails */
    public function __construct(array $nominatedEmails = [])
    {
        $this->emails = array_values(array_unique(array_filter(array_map(
            static fn (string $email): string => strtolower(trim($email)),
            $nominatedEmails,
        ), static fn (string $email): bool => $email !== '')));
    }

    public function isConfigured(): bool
    {
        return $this->emails !== [];
    }

    public function nominates(string $email): bool
    {
        return in_array(strtolower(trim($email)), $this->emails, true);
    }

    /** @return list<string> the nominated addresses, lowercased, in configured order */
    public function emails(): array
    {
        return $this->emails;
    }
}
