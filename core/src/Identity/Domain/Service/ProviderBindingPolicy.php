<?php

declare(strict_types=1);

namespace TriCab\Core\Identity\Domain\Service;

use TriCab\Core\Identity\Domain\ValueObject\EmailAddressPurpose;
use TriCab\Core\Identity\Domain\ValueObject\ExternalProvider;

/**
 * Which providers may resolve a user through an address of a given purpose.
 *
 * The policy lives in code rather than in a column on purpose. If the binding
 * were data, one bad row would widen who can link to an account; as code it is
 * reviewable, testable and identical in every environment. The column says what
 * an address is *for*; this says what that permits.
 *
 * Why each direction matters:
 *
 *  - A **corporate** address accepts Entra only. Nobody should be able to
 *    register a consumer Google or Apple account bearing someone's TriCab
 *    mailbox and resolve to their platform user.
 *  - A **personal** address never accepts Entra. An Entra identity is a mailbox
 *    TriCab issued; matching one to a self-asserted personal address would let
 *    a tenant guest invitation resolve to an existing employee.
 *
 * Google and Apple are permitted on personal addresses because both providers
 * are already live and resolve existing users by verified email — removing them
 * would lock people out today. That does leave "prove control of a personal
 * mailbox, inherit that user's roles" as a real path, which is why this policy
 * is only half the control. The other half is AuthenticationAssurance: those
 * providers are low assurance and cannot reach an administrative permission
 * however many roles the user holds.
 */
final class ProviderBindingPolicy
{
    /** @var array<string, list<string>> */
    private const ALLOWED_PROVIDERS_BY_PURPOSE = [
        EmailAddressPurpose::CORPORATE => [
            ExternalProvider::MICROSOFT_ENTRA,
        ],
        EmailAddressPurpose::PERSONAL => [
            ExternalProvider::EMAIL_OTP,
            ExternalProvider::PASSKEY,
            ExternalProvider::GOOGLE,
            ExternalProvider::APPLE,
        ],
    ];

    public static function allows(ExternalProvider $provider, EmailAddressPurpose $purpose): bool
    {
        $allowed = self::ALLOWED_PROVIDERS_BY_PURPOSE[$purpose->toString()] ?? [];

        return in_array($provider->toString(), $allowed, true);
    }
}
