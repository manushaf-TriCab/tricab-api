<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\Rfq\Domain;

use PHPUnit\Framework\TestCase;
use TriCab\Core\Rfq\Domain\Service\SalesOfficePolicy;

/** The source's `sales_office_for_territory`, case for case. */
final class SalesOfficePolicyTest extends TestCase
{
    public function test_each_named_territory_has_its_office(): void
    {
        $expected = [
            'WA' => 'PER', 'SA' => 'ADE', 'NZSTH' => 'CHR', 'TAS' => 'HOB', 'NZNTH' => 'AUC',
            'NZ' => 'AUC', 'VIC' => 'MEL', 'NSW' => 'SYD', 'QLD' => 'BRI',
        ];
        foreach ($expected as $territory => $office) {
            self::assertSame($office, SalesOfficePolicy::forTerritory($territory), $territory);
        }
    }

    public function test_the_territory_is_read_trimmed_and_in_any_case(): void
    {
        self::assertSame('PER', SalesOfficePolicy::forTerritory(' wa '));
    }

    public function test_an_unnamed_or_missing_territory_is_melbourne(): void
    {
        foreach (['ASIA', 'NONE', '', null] as $territory) {
            self::assertSame('MEL', SalesOfficePolicy::forTerritory($territory));
        }
    }
}
