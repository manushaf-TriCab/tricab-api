<?php

declare(strict_types=1);

namespace TriCab\Core\Onboarding\Application\Service;

use Throwable;
use TriCab\Core\Identity\Application\DTO\UserManagement\ActorContext;
use TriCab\Core\Identity\Domain\ValueObject\EmailAddress;
use TriCab\Core\Onboarding\Application\DTO\Admin\OnboardingAdminEvent;
use TriCab\Core\Onboarding\Application\DTO\Admin\OnboardingPersonDetail;
use TriCab\Core\Onboarding\Application\DTO\Admin\OnboardingPersonRecord;
use TriCab\Core\Onboarding\Contract\OnboardingAccountDirectory;
use TriCab\Core\Onboarding\Contract\OnboardingAdminAuditLog;
use TriCab\Core\Onboarding\Contract\OnboardingInvitationEmailSender;
use TriCab\Core\Onboarding\Contract\OnboardingPeopleRepository;
use TriCab\Core\Onboarding\Domain\Exception\InvitationNotDelivered;
use TriCab\Core\Onboarding\Domain\Exception\InvitationNotPermitted;
use TriCab\Core\Onboarding\Domain\Exception\OnboardingPersonNotFound;
use TriCab\Core\Onboarding\Domain\Service\LeaverPolicy;
use TriCab\Core\Onboarding\Domain\ValueObject\AdminEventType;
use TriCab\Core\Shared\Time\Clock;

/**
 * HR invites a person to start their onboarding (4.1).
 *
 * ## HR never handles a code (invariant 6)
 *
 * The email links to the onboarding page and says how to sign in. The person
 * asks for their own code there; no code exists when this runs, and none is
 * ever shown to HR. That is also why this is not a "resend code" button: a
 * code sent on HR's click is a code HR could have asked the person to read out.
 *
 * ## Refused before anything is sent
 *
 *  - a leaver — the invitation would lead to an induction they can no longer
 *    complete;
 *  - an account that cannot sign in — pending, disabled, or without
 *    `account.login` — because the link would end on a refusal. The remedy is
 *    in User Management, not here;
 *  - a second invitation within {@see COOLDOWN_SECONDS}, which is what a double
 *    click is. The claim is one conditional `UPDATE`, so two clicks cannot both
 *    pass it.
 *
 * ## The record never claims a send that did not happen
 *
 * The invitation is stamped before it is sent, so a concurrent click sees it.
 * If the provider refuses the message the stamp is put back and the failure is
 * audited as refused — `InvitedAtUtc` always means "an email went".
 */
final readonly class InviteOnboardingPersonService
{
    public const PERMISSION = 'ONBOARDING.PEOPLE.MANAGE';
    public const COOLDOWN_SECONDS = 60;

    public function __construct(
        private OnboardingPeopleRepository $people,
        private OnboardingAccountDirectory $accounts,
        private OnboardingInvitationEmailSender $sender,
        private OnboardingAdminAuditLog $audit,
        private Clock $clock,
        private string $publicAppBaseUrl,
    ) {
    }

    public function invite(ActorContext $actor, string $personPublicId): OnboardingPersonDetail
    {
        $publicId = strtolower(trim($personPublicId));
        $person = $this->people->findPerson($publicId) ?? throw OnboardingPersonNotFound::forPublicId($personPublicId);

        try {
            if (LeaverPolicy::hasDeparted($person->departedAtUtc, $this->clock->now())) {
                throw InvitationNotPermitted::departed();
            }
            if (!$this->accounts->canSignIn($person->userId)) {
                throw InvitationNotPermitted::cannotSignIn();
            }
            $previous = $this->people->claimInvitation($person, self::COOLDOWN_SECONDS, $actor)
                ?? throw InvitationNotPermitted::tooSoon(self::COOLDOWN_SECONDS);
        } catch (InvitationNotPermitted $refusal) {
            $this->refuse($actor, $person, $refusal->reason, $refusal->getMessage());

            throw $refusal;
        }

        // Parsed before the send: an account with no usable address is a data
        // defect, and must not be reported as the email provider's failure.
        $to = EmailAddress::fromString($person->emailAddress);

        try {
            $this->sender->sendInvitation(
                $to,
                $person->displayName,
                rtrim($this->publicAppBaseUrl, '/') . '/onboarding',
            );
        } catch (Throwable $failure) {
            $this->people->releaseInvitation($person, $previous);
            $this->refuse($actor, $person, 'delivery_failed', 'The email provider did not accept the invitation.');

            throw InvitationNotDelivered::fromThrowable($failure);
        }

        $this->audit->append($actor, OnboardingAdminEvent::succeeded(
            AdminEventType::PERSON_INVITED,
            $person->userId,
            $person->publicId,
            'Sent an invitation to start onboarding. It carries no sign-in code.',
            [],
            self::PERMISSION,
        ));

        $detail = $this->people->personDetail($publicId) ?? throw OnboardingPersonNotFound::forPublicId($personPublicId);

        return $detail->withCanSignIn(true);
    }

    private function refuse(ActorContext $actor, OnboardingPersonRecord $person, string $reason, string $why): void
    {
        $this->audit->append($actor, OnboardingAdminEvent::refused(
            AdminEventType::PERSON_INVITATION_REFUSED,
            $person->userId,
            $person->publicId,
            'Invitation not sent: ' . $why,
            ['reason' => $reason],
            self::PERMISSION,
        ));
    }
}
