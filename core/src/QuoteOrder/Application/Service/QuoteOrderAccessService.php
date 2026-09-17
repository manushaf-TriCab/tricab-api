<?php

declare(strict_types=1);

namespace TriCab\Core\QuoteOrder\Application\Service;

use TriCab\Core\QuoteOrder\Application\DTO\QuoteOrderActor;
use TriCab\Core\QuoteOrder\Application\DTO\QuoteOrderSearchCriteria;
use TriCab\Core\QuoteOrder\Application\DTO\QuoteOrderVisibilityScope;
use TriCab\Core\QuoteOrder\Application\Exception\QuoteOrderAccessDenied;
use TriCab\Core\QuoteOrder\Module;

/**
 * Server-side Quotes & Orders visibility.
 *
 * Rules:
 * - Requires QUOTE.READ (never trust client filters alone).
 * - Customer/portal: only mapped CustomerCode values (Azure Customers.CustomerCode
 *   = legacy tc.cust.code). Empty mapping → empty results / not found.
 * - ADMINISTRATOR + QUOTE.READ: staffSearchAll (management-equivalent).
 * - EMPLOYEE + QUOTE.READ: staffSearchAll until trusted staff region/territory
 *   assignment exists in the new identity model (documented PARTIAL parity).
 * - Client region/territory/representative filters never expand authorization.
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
        $isStaff = $actor->isStaff();
        $isCustomerPortal = $actor->isCustomerPortalScoped();

        $codes = [];
        foreach ($actor->allowedCustomerCodes as $code) {
            $trimmed = trim($code);
            if ($trimmed !== '') {
                $codes[strtoupper($trimmed)] = $trimmed;
            }
        }

        // No QUOTE.READ_ALL in catalog. Broader staff scope uses role + QUOTE.READ.
        // Territory/region staff assignment is not on identity.Users — cannot invent.
        $staffSearchAll = !$isCustomerPortal && ($isAdmin || $isStaff);

        return new QuoteOrderVisibilityScope(
            canSearch: true,
            isCustomerPortalScoped: $isCustomerPortal,
            isAdministrator: $isAdmin,
            staffSearchAll: $staffSearchAll,
            allowedCustomerCodes: array_values($codes),
            allowedCountryCodes: [],
            regionCode: null,
        );
    }

    /**
     * Intersect request filters with authorized scope so filters cannot expand access.
     */
    public function constrainCriteria(
        QuoteOrderSearchCriteria $criteria,
        QuoteOrderVisibilityScope $scope,
    ): QuoteOrderSearchCriteria {
        if (!$scope->isCustomerPortalScoped) {
            return $criteria;
        }

        $allowed = [];
        foreach ($scope->allowedCustomerCodes as $code) {
            $allowed[strtoupper(trim($code))] = $code;
        }

        if ($allowed === []) {
            return $criteria;
        }

        $requested = $criteria->customerCode;
        if ($requested === null || trim($requested) === '') {
            // Force exact IN-list via visibility SQL; no client customerCode needed.
            return $criteria;
        }

        $requestedUpper = strtoupper(trim($requested));
        $matched = [];
        foreach ($allowed as $upper => $original) {
            // Prefix match only within already-authorized codes (cannot expand).
            if (str_starts_with($upper, $requestedUpper) || str_starts_with($requestedUpper, $upper)) {
                if (str_starts_with($upper, $requestedUpper)) {
                    $matched[$upper] = $original;
                }
            }
        }

        if ($matched === []) {
            // Requested customerCode outside authorized set → empty via impossible code.
            return new QuoteOrderSearchCriteria(
                page: $criteria->page,
                pageSize: $criteria->pageSize,
                reference: $criteria->reference,
                customerCode: '__UNAUTHORIZED__',
                representative: $criteria->representative,
                from: $criteria->from,
                to: $criteria->to,
                type: $criteria->type,
                statusCode: $criteria->statusCode,
            );
        }

        // Keep the requested prefix only if it still sits inside authorized codes.
        return $criteria;
    }

    public function assertCanViewCustomerCode(QuoteOrderVisibilityScope $scope, ?string $customerCode): void
    {
        if ($scope->staffSearchAll && !$scope->isCustomerPortalScoped) {
            return;
        }

        if (!$scope->isCustomerPortalScoped) {
            return;
        }

        if ($scope->allowedCustomerCodes === []) {
            throw QuoteOrderAccessDenied::viewForbidden();
        }

        $normalized = strtoupper(trim((string) $customerCode));
        if ($normalized === '') {
            throw QuoteOrderAccessDenied::viewForbidden();
        }

        foreach ($scope->allowedCustomerCodes as $allowed) {
            if (strtoupper(trim($allowed)) === $normalized) {
                return;
            }
        }

        throw QuoteOrderAccessDenied::viewForbidden();
    }

    public function canViewCustomerCode(QuoteOrderVisibilityScope $scope, ?string $customerCode): bool
    {
        try {
            $this->assertCanViewCustomerCode($scope, $customerCode);

            return true;
        } catch (QuoteOrderAccessDenied) {
            return false;
        }
    }
}
