<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Admin;

use TriCab\Core\Customer\Application\DTO\ContactListResult;
use TriCab\Core\Customer\Application\DTO\CustomerContactDetails;
use TriCab\Core\Customer\Application\DTO\CustomerContactSummary;
use TriCab\Core\Customer\Application\DTO\CustomerContactUserLinkDetails;
use TriCab\Core\Customer\Application\DTO\CustomerDetails;
use TriCab\Core\Customer\Application\DTO\CustomerListResult;
use TriCab\Core\Customer\Application\DTO\CustomerPortalUserProvisioningResult;
use TriCab\Core\Customer\Application\DTO\CustomerSummary;
use TriCab\Core\Customer\Application\DTO\CustomerSummaryCounts;
use TriCab\Core\Customer\Application\DTO\Pagination;

/**
 * Serializes Core Customer DTOs to admin API response arrays.
 * Never exposes numeric CustomerId/CustomerContactId/UserId — publicIds only.
 */
final class CustomerManagementDtoSerializer
{
    /**
     * @return array<string, mixed>
     */
    public static function customerSummary(CustomerSummary $summary): array
    {
        return [
            'publicId' => $summary->publicId,
            'customerCode' => $summary->customerCode,
            'customerName' => $summary->customerName,
            'status' => $summary->status,
            'isActive' => $summary->isActive,
            'suburb' => $summary->suburb,
            'postcode' => $summary->postcode,
            'countryCode' => $summary->countryCode,
            'marketCode' => $summary->marketCode,
            'territoryCode' => $summary->territoryCode,
            'phone' => $summary->phone,
            'contactCount' => $summary->contactCount,
            'portalUserCount' => $summary->portalUserCount,
            'createdAtUtc' => $summary->createdAtUtc,
            'updatedAtUtc' => $summary->updatedAtUtc,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function customerDetails(CustomerDetails $details): array
    {
        return [
            'publicId' => $details->publicId,
            'customerCode' => $details->customerCode,
            'customerName' => $details->customerName,
            'status' => $details->status,
            'isActive' => $details->isActive,
            'addressLine1' => $details->addressLine1,
            'addressLine2' => $details->addressLine2,
            'suburb' => $details->suburb,
            'postcode' => $details->postcode,
            'countryCode' => $details->countryCode,
            'marketCode' => $details->marketCode,
            'salesRep' => $details->salesRep,
            'territory' => $details->territory,
            'phone' => $details->phone,
            'legacyCustomerId' => $details->legacyCustomerId,
            'createdAtUtc' => $details->createdAtUtc,
            'updatedAtUtc' => $details->updatedAtUtc,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function customerListResult(CustomerListResult $result): array
    {
        return [
            'items' => array_map(self::customerSummary(...), $result->items),
            'pagination' => self::pagination($result->pagination),
            'counts' => self::counts($result->counts),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function customerContactSummary(CustomerContactSummary $summary): array
    {
        return [
            'publicId' => $summary->publicId,
            'customerPublicId' => $summary->customerPublicId,
            'displayName' => $summary->displayName,
            'email' => $summary->email,
            'position' => $summary->position,
            'isPrimary' => $summary->isPrimary,
            'isActive' => $summary->isActive,
            'portalAccessStatus' => $summary->portalAccessStatus,
            'createdAtUtc' => $summary->createdAtUtc,
            'updatedAtUtc' => $summary->updatedAtUtc,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function customerContactDetails(CustomerContactDetails $details): array
    {
        return [
            'publicId' => $details->publicId,
            'customerPublicId' => $details->customerPublicId,
            'displayName' => $details->displayName,
            'email' => $details->email,
            'normalizedEmail' => $details->normalizedEmail,
            'position' => $details->position,
            'phone' => $details->phone,
            'mobile' => $details->mobile,
            'isPrimary' => $details->isPrimary,
            'isActive' => $details->isActive,
            'portalAccessStatus' => $details->portalAccessStatus,
            'legacyContactId' => $details->legacyContactId,
            'createdAtUtc' => $details->createdAtUtc,
            'updatedAtUtc' => $details->updatedAtUtc,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function contactListResult(ContactListResult $result): array
    {
        return [
            'items' => array_map(self::customerContactSummary(...), $result->items),
            'pagination' => self::pagination($result->pagination),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function customerContactUserLinkDetails(CustomerContactUserLinkDetails $link): array
    {
        return [
            'contactPublicId' => $link->contactPublicId,
            'customerPublicId' => $link->customerPublicId,
            'userPublicId' => $link->userPublicId,
            'isPrimary' => $link->isPrimary,
            'isActive' => $link->isActive,
            'linkedAtUtc' => $link->linkedAtUtc,
            'unlinkedAtUtc' => $link->unlinkedAtUtc,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function customerPortalUserProvisioningResult(CustomerPortalUserProvisioningResult $result): array
    {
        return [
            'contact' => self::customerContactDetails($result->contact),
            'link' => self::customerContactUserLinkDetails($result->link),
            'userPublicId' => $result->userPublicId,
            'emailAddress' => $result->emailAddress,
            'displayName' => $result->displayName,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function pagination(Pagination $pagination): array
    {
        return [
            'page' => $pagination->page,
            'pageSize' => $pagination->pageSize,
            'totalItems' => $pagination->totalItems,
            'totalPages' => $pagination->totalPages,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function counts(CustomerSummaryCounts $counts): array
    {
        return [
            'totalCustomers' => $counts->totalCustomers,
            'activeCustomers' => $counts->activeCustomers,
            'inactiveCustomers' => $counts->inactiveCustomers,
            'portalEnabledCustomers' => $counts->portalEnabledCustomers,
        ];
    }
}
