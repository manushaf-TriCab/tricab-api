<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Onboarding;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TriCab\Api\Http\Auth\AdminActorFactory;
use TriCab\Core\Identity\Application\DTO\AuthenticatedUser;
use TriCab\Core\Onboarding\Application\Service\DecideReviewService;
use TriCab\Core\Onboarding\Domain\Exception\InvalidOnboardingValue;
use TriCab\Core\Onboarding\Domain\ValueObject\AssignmentStatus;
use TriCab\Core\Product\Domain\Exception\InvalidProductValue;
use TriCab\Core\Product\Domain\ValueObject\RowVersionToken;

/**
 * POST /api/v1/admin/onboarding/queue/{assignmentPublicId}/decision (4.3).
 *
 * Body: `{ "rowVersion": "<base64>", "outcome": "completed" | "fix_and_resubmit"
 * | "in_progress", "note"?: "…" }`.
 *
 * Optimistic concurrency rather than an idempotency key: a decision replayed
 * after it landed carries a token that no longer matches, and 409 with a
 * reload is the right answer — the item is decided, and the page should show
 * it rather than pretend to decide it again.
 */
final class PostOnboardingReviewDecisionAction
{
    public function __construct(
        private readonly DecideReviewService $decisions,
        private readonly OnboardingSelfServiceResponder $responder,
    ) {
    }

    /**
     * @param array<string, string> $arguments
     */
    public function __invoke(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $arguments = [],
    ): ResponseInterface {
        unset($response);

        return $this->responder->run($request, function (AuthenticatedUser $user) use ($request, $arguments): ResponseInterface {
            $body = OnboardingSelfServiceResponder::body($request);
            $note = $body['note'] ?? null;
            if ($note !== null && !is_string($note)) {
                throw InvalidOnboardingValue::for('note', '(not text)');
            }
            $outcome = $body['outcome'] ?? null;

            $detail = $this->decisions->decide(
                AdminActorFactory::fromRequest($request),
                $arguments['assignmentPublicId'] ?? '',
                self::rowVersion($body),
                AssignmentStatus::fromString(is_string($outcome) ? $outcome : ''),
                $note,
            );

            return $this->responder->success($detail->toArray($user->userId));
        });
    }

    /**
     * @param array<array-key, mixed> $body
     */
    private static function rowVersion(array $body): RowVersionToken
    {
        $value = $body['rowVersion'] ?? null;
        if (!is_string($value) || trim($value) === '') {
            throw InvalidOnboardingValue::for('rowVersion', '(missing)');
        }

        try {
            return RowVersionToken::fromBase64(trim($value));
        } catch (InvalidProductValue) {
            throw InvalidOnboardingValue::for('rowVersion', '(malformed)');
        }
    }
}
