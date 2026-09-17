<?php

declare(strict_types=1);

namespace TriCab\Core\QuoteOrder\Application\Service;

use TriCab\Core\QuoteOrder\Application\DTO\QuoteOrderActor;
use TriCab\Core\QuoteOrder\Application\DTO\QuoteOrderVisibilityScope;
use TriCab\Core\QuoteOrder\Application\Exception\QuoteOrderAccessDenied;
use TriCab\Core\QuoteOrder\Module;

/**
 * Server-side Quotes & Orders visibility.
 *
 * - Requires QUOTE.READ (never trust client filters alone).
 * - Customer-portal actors (linked customers, not staff/admin): restrict by
 *   allowedCustomerCodes. Empty list → empty results. Azure→legacy customer
 *   code resolution is Phase 1B unknown when codes are not supplied.
 * - Staff / ADMINISTRATOR with QUOTE.READ: may search; optional filters applied
 *   in the repository. Full legacy region/territory parity is PARTIAL in Phase 1.
 */
final class QuoteOrderAccessService
{
    public function requireRead(QuoteOrderActor $actor): void
    {
        if (!$actor->hasPermission(Module::PERMISSION_READ)) {
            throw QuoteOrderAccessDenied::permissionRequired();
        }
    }

    public function resolveVisibility(QuoteOrderActor $actor): QuoteOrderVisibilityScope
    {
        $this->requireRead($actor);

        $isAdmin = $actor->isPlatformAdministrator();
        $isCustomerPortal = $actor->isCustomerPortalScoped();

        $codes = [];
        foreach ($actor->allowedCustomerCodes as $code) {
            $trimmed = trim($code);
            if ($trimmed !== '') {
                $codes[strtoupper($trimmed)] = $trimmed;
            }
        }

        return new QuoteOrderVisibilityScope(
            canSearch: true,
            isCustomerPortalScoped: $isCustomerPortal,
            isAdministrator: $isAdmin,
            allowedCustomerCodes: array_values($codes),
            regionCode: null,
        );
    }

    public function assertCanViewCustomerCode(QuoteOrderVisibilityScope $scope, ?string $customerCode): void
    {
        if (!$scope->isCustomerPortalScoped) {
            return;
        }

        if ($scope->allowedCustomerCodes === []) {
            throw QuoteOrderAccessDenied::viewForbidden();
        }

        $normalized = strtoupper(trim((string) $customerCode));
        foreach ($scope->allowedCustomerCodes as $allowed) {
            if (strcasecmp($allowed, $normalized) === 0 || strtoupper($allowed) === $normalized) {
                return;
            }
        }

        throw QuoteOrderAccessDenied::viewForbidden();
    }
}
