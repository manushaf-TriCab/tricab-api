<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Finance\Ap;

use TriCab\Core\Finance\Ap\Domain\ApVendor;
use TriCab\Core\Finance\Ap\Domain\ApVendorInvoice;
use TriCab\Core\Finance\Ap\Service\ApVendorMatcher;

/**
 * The vendors and entities of `test_vendor_match.py`, unchanged.
 */
final class ApVendorFixtures
{
    /** The source's `vendor()` helper: the ref defaults to V followed by the id to six digits. */
    public static function vendor(int $odooId, string $name, ?string $ref = null, string $email = '', string $vat = '', bool $active = true): ApVendor
    {
        return new ApVendor($odooId, $name, $ref ?? sprintf('V%06d', $odooId), $email, $vat, '', $active, false);
    }

    /**
     * @return list<ApVendor>
     */
    public static function vendors(): array
    {
        return [
            self::vendor(1, 'Jet Couriers (Tasmania) Pty Ltd'),
            self::vendor(2, 'Zend Limited', email: 'accounts@zend.example; ar@zend.example'),
            self::vendor(3, 'Go Logistics (NZ) Limited', vat: '123 456 789'),
            self::vendor(4, 'Go Logistics Australia Pty Ltd'),
            self::vendor(5, 'Lochert Bros t/as Sprint Freight & Logistics'),
            self::vendor(6, 'Tricab (New Zealand) Ltd', ref: 'IC0004'),
            self::vendor(7, 'Capital Transport 3TRICAB ADE'),
            self::vendor(8, 'Capital Transport 9TRIANGB BRI'),
            self::vendor(9, 'Civic Transport Services'),
            self::vendor(10, 'Old Supplies Pty Ltd', active: false),
            self::vendor(11, 'Bunnings Group Limited'),
        ];
    }

    /**
     * @return list<array{name: string}>
     */
    public static function companies(): array
    {
        return [['name' => 'TriCab (Australia) Pty Ltd'], ['name' => 'Tricab (New Zealand) Ltd']];
    }

    /**
     * @return list<string>
     */
    public static function buyers(): array
    {
        return ApVendorMatcher::buyerNames(self::companies());
    }

    public static function invoice(
        string $id = 'inv-1',
        ?string $vendor = null,
        ?string $company = null,
        ?string $taxId = null,
        ?string $vendorEmail = null,
        ?string $sender = null,
        ?string $senderName = null,
        ?string $vendorMatch = null,
    ): ApVendorInvoice {
        return new ApVendorInvoice($id, $vendor, $company, $taxId, $vendorEmail, $sender, $senderName, $vendorMatch);
    }
}
