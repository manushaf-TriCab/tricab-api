<?php

declare(strict_types=1);

namespace TriCab\Core\Npr\Application\Service;

use TriCab\Core\Identity\Application\DTO\AuthenticatedUser;
use TriCab\Core\Npr\Contract\NprRequestRepository;
use TriCab\Core\Npr\NprRequestReader;
use TriCab\Core\Npr\NprRequestSnapshot;

/**
 * {@see NprRequestReader} over the NPR repository: the only way CableFit reads NPR data.
 *
 * A snapshot is returned only to an NPR.TSD.READ holder, and only for a request
 * with TSD or later; anything else is null, with no way to tell missing from refused.
 */
final class TsdNprRequestReader implements NprRequestReader
{
    private const SECTION_PRODUCT_REQUIREMENTS = 5;

    public function __construct(
        private readonly NprRequestRepository $requests,
        private readonly NprAccessPolicy $policy,
        private readonly NprValidationService $dictionary,
    ) {
    }

    public function findForTsd(AuthenticatedUser $user, string $requestPublicId): ?NprRequestSnapshot
    {
        $request = $this->requests->find($requestPublicId);
        if ($request === null || !$this->policy->canReadAsTsd($user, $request)) {
            return null;
        }

        // Section 5 fields are variant-scoped, so their answers live in each variant;
        // any Section 5 id answered at request level is surfaced too.
        $section5 = array_intersect_key(
            $request->answers,
            array_flip($this->dictionary->fieldIdsInSection(self::SECTION_PRODUCT_REQUIREMENTS)),
        );

        return new NprRequestSnapshot(
            $request->publicId,
            $request->requestNumber,
            $request->revision,
            $request->title,
            $request->customerName,
            $section5,
            $request->variants,
        );
    }
}
