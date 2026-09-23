<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Onboarding;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Api\Identity\Authentication\AuthenticatedUserAttribute;
use TriCab\Core\Identity\Application\DTO\AuthenticatedUser;
use TriCab\Core\Onboarding\Domain\Exception\AllocationNotPermitted;
use TriCab\Core\Onboarding\Domain\Exception\AssessmentNotAvailable;
use TriCab\Core\Onboarding\Domain\Exception\AssignmentStatusIsFinal;
use TriCab\Core\Onboarding\Domain\Exception\ContentVersionSuperseded;
use TriCab\Core\Onboarding\Domain\Exception\FormVersionNotClassified;
use TriCab\Core\Onboarding\Domain\Exception\InvalidOnboardingValue;
use TriCab\Core\Onboarding\Domain\Exception\InvitationNotDelivered;
use TriCab\Core\Onboarding\Domain\Exception\InvitationNotPermitted;
use TriCab\Core\Onboarding\Domain\Exception\OnboardingAccessWindowClosed;
use TriCab\Core\Onboarding\Domain\Exception\OnboardingAssignmentNotFound;
use TriCab\Core\Onboarding\Domain\Exception\OnboardingDocumentNotFound;
use TriCab\Core\Onboarding\Domain\Exception\OnboardingItemTypeMismatch;
use TriCab\Core\Onboarding\Domain\Exception\OnboardingPersonAlreadyExists;
use TriCab\Core\Onboarding\Domain\Exception\OnboardingPersonNotFound;
use TriCab\Core\Onboarding\Domain\Exception\OnboardingSubmissionWindowClosed;
use TriCab\Core\Onboarding\Domain\Exception\OnboardingWriteConflict;
use TriCab\Core\Onboarding\Domain\Exception\ReviewDecisionNotPermitted;
use TriCab\Core\Onboarding\Domain\Exception\SelfReviewNotPermitted;
use TriCab\Core\Onboarding\Domain\Exception\StatusChangeNotPermitted;
use TriCab\Core\Onboarding\Domain\Exception\UnknownPresentationSlide;
use TriCab\Core\Product\Domain\Exception\InvalidProductValue;
use TriCab\Core\Product\Domain\ValueObject\RowVersionToken;

/**
 * The shared plumbing of the eleven self-service routes: who is asking, what
 * they sent, and what a domain refusal looks like on the wire.
 *
 * The HR routes under `/api/v1/admin/onboarding` use it too (Phase 4). Their
 * refusals are the same domain exceptions, and a second mapper would be a
 * second chance for the two surfaces to answer one refusal differently.
 *
 * ## One mapping, in one place
 *
 * Eleven actions catching their own exceptions is eleven chances for the twelfth
 * to answer 500 where the others answer 409, and for the SPA to have to handle
 * both. {@see run()} wraps the call, so a new exception type is added here once
 * and every route follows.
 *
 * The codes matter as much as the statuses. The SPA reloads on
 * `ONBOARDING_CONTENT_SUPERSEDED` and shows an error on everything else 409, so
 * the difference between those has to survive the trip.
 *
 * ## Deliberately not distinguished
 *
 * Not-found and not-yours are one code and one status (work order invariant 4),
 * and the message names nothing. A refusal that said which of the two it was
 * would turn an assignment id into an oracle.
 *
 * ## The idempotency key is a header
 *
 * `Idempotency-Key`, following the platform's Save Quote route and the wider
 * convention. Not a body field: it identifies the *request*, not the thing being
 * written, and a body field is one a retry can forget to resend while resending
 * everything else.
 */
final readonly class OnboardingSelfServiceResponder
{
    public function __construct(
        private JsonResponder $responder,
    ) {
    }

    /**
     * Runs the work with an authenticated user, or answers for it.
     *
     * @param callable(AuthenticatedUser): ResponseInterface $work
     */
    public function run(ServerRequestInterface $request, callable $work): ResponseInterface
    {
        $user = $request->getAttribute(AuthenticatedUserAttribute::KEY);

        // The gate runs before the action and attaches the user, so reaching
        // here without one is a wiring fault rather than an anonymous request.
        // Answering 401 keeps that fault from reading as a 500.
        if (!$user instanceof AuthenticatedUser) {
            return $this->responder->error('UNAUTHENTICATED', 'Authentication is required.', 401);
        }

        try {
            return $work($user);
        } catch (Throwable $failure) {
            return $this->failure($failure);
        }
    }

    public function success(mixed $data, int $status = 200): ResponseInterface
    {
        /** @var array<string, mixed> $payload */
        $payload = is_array($data) ? $data : ['value' => $data];

        return $this->responder->success($payload, $status);
    }

    /**
     * @throws Throwable anything this does not recognise, so the platform's own
     *                   error handler logs it rather than this class hiding it
     */
    private function failure(Throwable $failure): ResponseInterface
    {
        return match (true) {
            // 404 — and the two are one answer on purpose.
            $failure instanceof OnboardingAssignmentNotFound,
            $failure instanceof OnboardingDocumentNotFound,
            $failure instanceof OnboardingPersonNotFound
                => $this->responder->error('ONBOARDING_NOT_FOUND', $failure->getMessage(), 404),

            // 403 — refused for a reason no credential changes.
            $failure instanceof OnboardingAccessWindowClosed
                => $this->responder->error('ONBOARDING_ACCESS_WINDOW_CLOSED', $failure->getMessage(), 403),
            $failure instanceof OnboardingSubmissionWindowClosed
                => $this->responder->error('ONBOARDING_SUBMISSION_WINDOW_CLOSED', $failure->getMessage(), 403),
            $failure instanceof StatusChangeNotPermitted
                => $this->responder->error('ONBOARDING_STATUS_CHANGE_NOT_PERMITTED', $failure->getMessage(), 403),
            // Said plainly rather than disguised as 404: the reviewer already
            // knows the record is theirs, and the remedy is another reviewer.
            $failure instanceof SelfReviewNotPermitted
                => $this->responder->error('ONBOARDING_SELF_REVIEW_NOT_PERMITTED', $failure->getMessage(), 403),

            // 409 — real, understood, and in conflict with the current state.
            $failure instanceof ContentVersionSuperseded
                => $this->responder->error('ONBOARDING_CONTENT_SUPERSEDED', $failure->getMessage(), 409),
            $failure instanceof OnboardingWriteConflict
                => $this->responder->error($failure->errorCode, $failure->getMessage(), 409),
            $failure instanceof AssessmentNotAvailable
                => $this->responder->error('ONBOARDING_ASSESSMENT_NOT_AVAILABLE', $failure->getMessage(), 409),
            $failure instanceof AssignmentStatusIsFinal
                => $this->responder->error('ONBOARDING_ITEM_IS_FINAL', $failure->getMessage(), 409),
            $failure instanceof OnboardingItemTypeMismatch
                => $this->responder->error('ONBOARDING_ITEM_TYPE_MISMATCH', $failure->getMessage(), 409),
            // HR's allocation refused for a reason the record explains —
            // a leaver, or an item the page offered that is no longer on offer.
            $failure instanceof AllocationNotPermitted
                => $this->responder->error(
                    'ONBOARDING_ALLOCATION_' . strtoupper($failure->reason),
                    $failure->getMessage(),
                    409,
                ),

            // A reviewer decision the item's state no longer admits — decided
            // by somebody else, or an outcome not offered from here.
            $failure instanceof ReviewDecisionNotPermitted
                => $this->responder->error(
                    'ONBOARDING_REVIEW_' . strtoupper($failure->reason),
                    $failure->getMessage(),
                    409,
                ),

            // 4.1 — adding and inviting people.
            $failure instanceof OnboardingPersonAlreadyExists
                => $this->responder->error('ONBOARDING_PERSON_EXISTS', $failure->getMessage(), 409),
            $failure instanceof InvitationNotPermitted
                => $this->responder->error(
                    'ONBOARDING_INVITATION_' . strtoupper($failure->reason),
                    $failure->getMessage(),
                    409,
                ),
            // 502 — the email provider refused. Nothing about the request is wrong.
            $failure instanceof InvitationNotDelivered
                => $this->responder->error('ONBOARDING_INVITATION_NOT_DELIVERED', $failure->getMessage(), 502),

            // 422 — the request is wrong in a way the client can fix.
            $failure instanceof UnknownPresentationSlide
                => $this->responder->error('ONBOARDING_UNKNOWN_SLIDE', $failure->getMessage(), 422),
            $failure instanceof FormVersionNotClassified
                => $this->responder->error('ONBOARDING_FORM_NOT_CLASSIFIED', $failure->getMessage(), 422),
            $failure instanceof InvalidOnboardingValue
                => $this->responder->error('VALIDATION_FAILED', $failure->getMessage(), 422),

            // Anything else is not this class's business. Swallowing it would
            // turn a bug into a 409 somebody spends a morning on.
            default => throw $failure,
        };
    }

    // ------------------------------------------------------------- the request

    /**
     * @return array<string, mixed>
     */
    public static function body(ServerRequestInterface $request): array
    {
        $body = $request->getParsedBody();

        /** @var array<string, mixed> */
        return is_array($body) ? $body : [];
    }

    public static function idempotencyKey(ServerRequestInterface $request): ?string
    {
        $header = trim($request->getHeaderLine('Idempotency-Key'));

        return $header === '' ? null : $header;
    }

    /**
     * The content version the client believes it is working against.
     *
     * Required, and refused when absent rather than defaulted to the current
     * one. Defaulting would make every write agree with whatever is live at the
     * moment it lands, which is exactly the check
     * {@see ContentVersionSuperseded} exists to perform.
     *
     * @param array<string, mixed> $body
     *
     * @throws InvalidOnboardingValue
     */
    public static function versionPublicId(array $body): string
    {
        $value = $body['versionPublicId'] ?? null;

        if (!is_string($value) || trim($value) === '') {
            throw InvalidOnboardingValue::for('versionPublicId', '(missing)');
        }

        return trim($value);
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return list<string>
     */
    public static function slideIds(array $body): array
    {
        $value = $body['slideIds'] ?? null;

        if (!is_array($value)) {
            throw InvalidOnboardingValue::for('slideIds', '(missing)');
        }

        $ids = [];
        foreach ($value as $slideId) {
            if (is_string($slideId) && trim($slideId) !== '') {
                $ids[] = trim($slideId);
            }
        }

        return $ids;
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<string, string>
     */
    public static function answers(array $body): array
    {
        $value = $body['answers'] ?? null;

        if (!is_array($value)) {
            throw InvalidOnboardingValue::for('answers', '(missing)');
        }

        $answers = [];
        foreach ($value as $questionPublicId => $optionKey) {
            if (is_string($optionKey) && $optionKey !== '') {
                $answers[(string) $questionPublicId] = $optionKey;
            }
        }

        return $answers;
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    public static function payload(array $body, string $field): array
    {
        $value = $body[$field] ?? null;

        if (!is_array($value)) {
            throw InvalidOnboardingValue::for($field, '(missing)');
        }

        /** @var array<string, mixed> */
        return $value;
    }

    /**
     * Null when the client is claiming there is no draft yet. The service
     * decides whether that claim is allowed; this only parses.
     *
     * @param array<string, mixed> $body
     */
    public static function rowVersion(array $body): ?RowVersionToken
    {
        $value = $body['expectedRowVersion'] ?? null;

        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return RowVersionToken::fromBase64(trim($value));
        } catch (InvalidProductValue) {
            // RowVersionToken belongs to Product and throws Product's own
            // exception, which this responder does not map — so an unparseable
            // token would answer 500 for what is plainly a bad request.
            // Translated here rather than widening the mapping to a type from
            // another module, which would make Onboarding's error contract
            // depend on Product's exception hierarchy.
            throw InvalidOnboardingValue::for('expectedRowVersion', 'not a row version token');
        }
    }
}
