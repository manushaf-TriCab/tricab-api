<?php

declare(strict_types=1);

namespace TriCab\Core\Onboarding\Infrastructure;

use TriCab\Core\Identity\Application\DTO\UserManagement\ActorContext;
use TriCab\Core\Onboarding\Application\DTO\Admin\OnboardingAdminEvent;
use TriCab\Core\Onboarding\Contract\OnboardingAdminAuditLog;

final class InMemoryOnboardingAdminAuditLog implements OnboardingAdminAuditLog
{
    /** @var list<array{actorUserId: int, event: OnboardingAdminEvent}> */
    public array $entries = [];

    public function append(ActorContext $actor, OnboardingAdminEvent $event): void
    {
        $this->entries[] = ['actorUserId' => $actor->userId, 'event' => $event];
    }
}
