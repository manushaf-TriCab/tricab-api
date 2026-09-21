<?php

declare(strict_types=1);

namespace TriCab\Core\Identity\Domain\ValueObject;

/**
 * How strongly the current session proved who it is.
 *
 * The platform previously had no such notion: a six-digit code emailed to a
 * personal mailbox and a Microsoft sign-in with MFA and conditional access
 * carried identical authority, because authorization looked only at
 * permissions. That is tolerable while everyone signs in with Entra. It stops
 * being tolerable once employees who are never issued a TriCab mailbox — a
 * warehouse operator, say — hold real roles and authenticate by OTP, because
 * the weakest door then opens everything their role can reach.
 *
 * The fix is not to weaken those people's access. It is to make authorization
 * depend on how you proved it as well as what you may do.
 *
 * Ordering, weakest first:
 *
 *  LOW          email OTP, Google, Apple — possession of a mailbox or a
 *               consumer account. Phishable, and outside TriCab's control.
 *  SUBSTANTIAL  passkey — device-bound and phishing-resistant. The intended
 *               level for staff who will never hold an Entra account.
 *  HIGH         Microsoft Entra — TriCab-issued, under tenant policy, MFA and
 *               conditional access.
 */
final class AuthenticationAssurance
{
    public const LOW = 'low';
    public const SUBSTANTIAL = 'substantial';
    public const HIGH = 'high';

    /** @var array<string, int> */
    private const RANK = [
        self::LOW => 1,
        self::SUBSTANTIAL => 2,
        self::HIGH => 3,
    ];

    /** @var array<string, string> */
    private const BY_PROVIDER = [
        ExternalProvider::EMAIL_OTP => self::LOW,
        ExternalProvider::GOOGLE => self::LOW,
        ExternalProvider::APPLE => self::LOW,
        ExternalProvider::PASSKEY => self::SUBSTANTIAL,
        ExternalProvider::MICROSOFT_ENTRA => self::HIGH,
    ];

    private function __construct(private readonly string $value)
    {
    }

    public static function low(): self
    {
        return new self(self::LOW);
    }

    public static function substantial(): self
    {
        return new self(self::SUBSTANTIAL);
    }

    public static function high(): self
    {
        return new self(self::HIGH);
    }

    /**
     * An unrecognised provider is treated as LOW rather than rejected.
     * Failing closed on the level, not on the request, means a provider added
     * later cannot silently acquire administrative reach before anyone has
     * decided what it is worth.
     */
    public static function forProvider(ExternalProvider|string $provider): self
    {
        $code = $provider instanceof ExternalProvider ? $provider->toString() : strtolower(trim($provider));
        $code = str_replace('-', '_', $code);

        return new self(self::BY_PROVIDER[$code] ?? self::LOW);
    }

    public function toString(): string
    {
        return $this->value;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    /** True when this level is at least as strong as $required. */
    public function satisfies(self $required): bool
    {
        return self::RANK[$this->value] >= self::RANK[$required->value];
    }
}
