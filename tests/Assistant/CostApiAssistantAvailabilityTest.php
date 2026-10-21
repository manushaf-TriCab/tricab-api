<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Assistant;

use PHPUnit\Framework\TestCase;
use TriCab\Api\Assistant\Infrastructure\CostApiAssistantAvailability;
use TriCab\Core\Product\Application\Pricing\CostResolution;
use TriCab\Core\Product\Application\Pricing\CustomerSellPriceStatus;
use TriCab\Core\Product\Application\Pricing\LegacyCostCalculationGateway;

final class CostApiAssistantAvailabilityTest extends TestCase
{
    public function test_stocked_and_make_come_through_and_anything_else_is_left_out(): void
    {
        $gateway = $this->gateway([
            'LS-XXXN/1C50EA' => CustomerSellPriceStatus::AVAILABILITY_STOCKED,
            'CA-PASN/5C120OR' => CustomerSellPriceStatus::AVAILABILITY_MAKE,
            'ZZ-NOPE/1C1OR' => CustomerSellPriceStatus::AVAILABILITY_UNKNOWN,
        ]);

        $availability = (new CostApiAssistantAvailability($gateway))
            ->forCodes(['LS-XXXN/1C50EA', 'CA-PASN/5C120OR', 'ZZ-NOPE/1C1OR'], 'AU');

        self::assertSame(['LS-XXXN/1C50EA' => 'STOCK', 'CA-PASN/5C120OR' => 'MAKE'], $availability);
        self::assertSame(['2507'], array_values(array_unique($gateway->dbcos)));
    }

    public function test_the_us_region_asks_tricab_usa(): void
    {
        $gateway = $this->gateway(['CA-PASN/5C120OR' => CustomerSellPriceStatus::AVAILABILITY_MAKE]);

        (new CostApiAssistantAvailability($gateway))->forCodes(['CA-PASN/5C120OR'], 'US');

        self::assertSame(['2517'], $gateway->dbcos);
    }

    /**
     * @param array<string, string> $statuses
     */
    private function gateway(array $statuses): RecordingCostGateway
    {
        return new RecordingCostGateway($statuses);
    }
}

/** Answers with a fixed status per code and records which company each call asked. */
final class RecordingCostGateway implements LegacyCostCalculationGateway
{
    /** @var list<string> */
    public array $dbcos = [];

    /** @param array<string, string> $statuses */
    public function __construct(private readonly array $statuses)
    {
    }

    public function resolveCost(string $productCode, string $dbco, string $requestedLength): CostResolution
    {
        $this->dbcos[] = $dbco;

        return CostResolution::failure($productCode, $dbco, 'TEST', $this->statuses[$productCode] ?? CustomerSellPriceStatus::AVAILABILITY_UNKNOWN, $requestedLength);
    }
}
