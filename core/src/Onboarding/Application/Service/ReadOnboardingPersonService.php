<?php

declare(strict_types=1);

namespace TriCab\Core\Onboarding\Application\Service;

use TriCab\Core\Onboarding\Application\DTO\Admin\OnboardingPersonDetail;
use TriCab\Core\Onboarding\Contract\OnboardingAccountDirectory;
use TriCab\Core\Onboarding\Contract\OnboardingPeopleRepository;
use TriCab\Core\Onboarding\Domain\Exception\OnboardingPersonNotFound;

final readonly class ReadOnboardingPersonService
{
    public function __construct(
        private OnboardingPeopleRepository $people,
        private OnboardingAccountDirectory $accounts,
    ) {
    }

    public function read(string $personPublicId): OnboardingPersonDetail
    {
        $publicId = strtolower(trim($personPublicId));
        $detail = $this->people->personDetail($publicId) ?? throw OnboardingPersonNotFound::forPublicId($personPublicId);
        $record = $this->people->findPerson($publicId) ?? throw OnboardingPersonNotFound::forPublicId($personPublicId);

        // Whether an invitation would work — the console only offers one when it would.
        return $detail->withCanSignIn($this->accounts->canSignIn($record->userId));
    }
}
