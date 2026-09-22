<?php

declare(strict_types=1);

namespace TriCab\Core\Onboarding\Application\Service;

use TriCab\Core\Onboarding\Application\DTO\Admin\OnboardingPersonDetail;
use TriCab\Core\Onboarding\Contract\OnboardingPeopleRepository;
use TriCab\Core\Onboarding\Domain\Exception\OnboardingPersonNotFound;

final readonly class ReadOnboardingPersonService
{
    public function __construct(
        private OnboardingPeopleRepository $people,
    ) {
    }

    public function read(string $personPublicId): OnboardingPersonDetail
    {
        return $this->people->personDetail(strtolower(trim($personPublicId)))
            ?? throw OnboardingPersonNotFound::forPublicId($personPublicId);
    }
}
