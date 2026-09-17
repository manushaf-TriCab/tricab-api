<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\Customer\Application\Service;

use PHPUnit\Framework\TestCase;
use TriCab\Core\Customer\Application\DTO\CustomerDetails;
use TriCab\Core\Customer\Application\Service\ResolveAuthenticatedCustomerCodesService;
use TriCab\Core\Customer\Contract\CustomerRepository;
use TriCab\Core\Customer\Domain\ValueObject\CustomerPublicId;

final class ResolveAuthenticatedCustomerCodesServiceTest extends TestCase
{
    public function test_resolves_distinct_sorted_customer_codes(): void
    {
        $aaaa = 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa';
        $bbbb = 'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb';
        $cccc = 'cccccccc-cccc-cccc-cccc-cccccccccccc';
        $dddd = 'dddddddd-dddd-dddd-dddd-dddddddddddd';

        $repo = $this->createMock(CustomerRepository::class);
        $repo->method('findDetailsByPublicId')->willReturnCallback(
            static function (CustomerPublicId $publicId) use ($aaaa, $bbbb, $cccc, $dddd): ?CustomerDetails {
                return match ($publicId->toString()) {
                    $aaaa => self::details($aaaa, 'ACME01'),
                    $bbbb => self::details($bbbb, 'acme01'),
                    $cccc => self::details($cccc, 'BETA01'),
                    $dddd => self::details($dddd, null),
                    default => null,
                };
            },
        );

        $service = new ResolveAuthenticatedCustomerCodesService($repo);
        $codes = $service->execute([$aaaa, $bbbb, $cccc, $dddd, 'not-a-uuid', '']);

        self::assertSame(['ACME01', 'BETA01'], $codes);
    }

    private static function details(string $publicId, ?string $code): CustomerDetails
    {
        return new CustomerDetails(
            publicId: $publicId,
            customerCode: $code,
            customerName: 'Name',
            status: 'ACTIVE',
            isActive: true,
            addressLine1: null,
            addressLine2: null,
            suburb: null,
            postcode: null,
            countryCode: 'AU',
            marketCode: 'AU',
            salesRep: null,
            territory: null,
            phone: null,
            legacyCustomerId: null,
            createdAtUtc: '2020-01-01T00:00:00Z',
            updatedAtUtc: '2020-01-01T00:00:00Z',
        );
    }
}
