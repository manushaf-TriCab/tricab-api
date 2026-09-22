<?php

declare(strict_types=1);

namespace TriCab\Core\Onboarding\Contract;

use TriCab\Core\Identity\Application\DTO\UserManagement\ActorContext;
use TriCab\Core\Onboarding\Application\DTO\Admin\OnboardingAdminEvent;

/**
 * Appends a single HR audit event on its own.
 *
 * For refusals. A successful change writes its events inside its own
 * transaction, through the repository that makes the change; a refused one has
 * no transaction to join, and still owes a row (invariant 13's "result").
 */
interface OnboardingAdminAuditLog
{
    public function append(ActorContext $actor, OnboardingAdminEvent $event): void;
}
