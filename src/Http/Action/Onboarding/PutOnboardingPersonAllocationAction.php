<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Onboarding;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TriCab\Api\Http\Auth\AdminActorFactory;
use TriCab\Core\Identity\Application\DTO\AuthenticatedUser;
use TriCab\Core\Onboarding\Application\DTO\Admin\AllocationSelection;
use TriCab\Core\Onboarding\Application\DTO\Admin\CurrentAllocation;
use TriCab\Core\Onboarding\Application\Service\SetPersonAllocationService;
use TriCab\Core\Onboarding\Domain\Exception\InvalidOnboardingValue;
use TriCab\Core\Product\Domain\Exception\InvalidProductValue;
use TriCab\Core\Product\Domain\ValueObject\RowVersionToken;

/**
 * PUT /api/v1/admin/onboarding/people/{personPublicId}/allocation (4.2).
 *
 * Body: `{ "rowVersion": "<base64>", "items": [{ "itemType", "itemPublicId",
 * "dueDate"? }, …] }` — the whole ticklist, every time.
 *
 * The response carries what the save *did*, and in particular `kept`: the
 * boxes unticked that stayed allocated because work had been recorded against
 * them. Without it an untick that did nothing is indistinguishable from a bug.
 */
final class PutOnboardingPersonAllocationAction
{
    public function __construct(
        private readonly SetPersonAllocationService $allocation,
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
            unset($user);
            $body = OnboardingSelfServiceResponder::body($request);

            $result = $this->allocation->set(
                AdminActorFactory::fromRequest($request),
                $arguments['personPublicId'] ?? '',
                self::rowVersion($body),
                self::selection($body),
            );

            $plan = $result['plan'];
            $summarise = static fn (CurrentAllocation $a): array => [
                'assignmentPublicId' => $a->assignmentPublicId,
                'itemType' => $a->itemType,
                'title' => $a->title,
                'status' => $a->status->toString(),
            ];

            return $this->responder->success([
                ...$result['detail']->toArray(),
                'result' => [
                    'allocatedCount' => count($plan->allocate),
                    'withdrawnCount' => count($plan->withdraw),
                    'dueDateChangedCount' => count($plan->dueDateChanges),
                    'kept' => array_map($summarise, $plan->kept),
                ],
            ]);
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

    /**
     * @param array<array-key, mixed> $body
     *
     * @return list<AllocationSelection>
     */
    private static function selection(array $body): array
    {
        $items = $body['items'] ?? null;
        if (!is_array($items)) {
            throw InvalidOnboardingValue::for('items', '(missing)');
        }
        if (count($items) > 500) {
            throw InvalidOnboardingValue::for('items', '(more than 500)');
        }

        $selection = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                throw InvalidOnboardingValue::for('items', '(not an object)');
            }
            $type = $item['itemType'] ?? null;
            $id = $item['itemPublicId'] ?? null;
            $due = $item['dueDate'] ?? null;
            $selection[] = AllocationSelection::of(
                is_string($type) ? $type : '',
                is_string($id) ? $id : '',
                is_string($due) ? $due : null,
            );
        }

        return $selection;
    }
}
