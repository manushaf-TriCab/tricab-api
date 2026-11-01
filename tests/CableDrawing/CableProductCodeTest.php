<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\CableDrawing;

use PHPUnit\Framework\TestCase;
use TriCab\Api\CableDrawing\CableProductCode;
use TriCab\Core\CableCalculator\Engine\CableProductCodeGenerator;

final class CableProductCodeTest extends TestCase
{
    public function testParsesTheGeneratorsLayout(): void
    {
        $code = CableProductCode::parse('ca-paxn/5c16bk');

        self::assertNotNull($code);
        self::assertSame('CA-PAXN/5C16BK', $code->code);
        self::assertSame('CA', $code->style);
        self::assertSame(5, $code->count);
        self::assertSame('C', $code->config);
        self::assertSame('16', $code->size);
        self::assertSame('BK', $code->colour);
        self::assertFalse($code->isAluminium());
    }

    public function testReadsSmallSizesAndAluminium(): void
    {
        $code = CableProductCode::parse('B1-PAXA/4C.5');

        self::assertNotNull($code);
        self::assertSame('.5', $code->size);
        self::assertFalse($code->isAwg());
        self::assertNull($code->colour);
        self::assertTrue($code->isAluminium());
    }

    public function testRejectsWhatIsNotACableCode(): void
    {
        foreach (['', 'CA', 'B06-ACXX/12H150', 'CA-PAXN/5X16BK'] as $text) {
            self::assertNull(CableProductCode::parse($text), $text);
        }
    }

    /** Whatever the generator writes for a spec row must match that row. */
    public function testMatchesTheRowsTheGeneratorWroteItFrom(): void
    {
        $rows = [
            ['cable_style' => 'CA', 'nc' => '4', 'cc' => 'c', 'xa' => '16', 'ne' => '1', 'ct' => 'p'],
            ['cable_style' => 'B1', 'nc' => '3', 'cc' => 'c', 'xa' => '120', 'ne' => '3', 'ct' => 'a'],
            ['cable_style' => 'C2', 'nc' => '12', 'cc' => 'c', 'xa' => '0.5', 'ne' => '0', 'ct' => 'p'],
            ['cable_style' => 'R5', 'nc' => '10', 'cc' => 'p', 'xa' => '0.75', 'ne' => '0', 'ct' => 'p'],
        ];
        foreach ($rows as $row) {
            $generated = CableProductCodeGenerator::generate(
                $row['cable_style'],
                $row['xa'],
                core: $row['nc'] . $row['cc'],
                earth: (int) $row['ne'],
                ctAlumin: $row['ct'] === 'a',
                colour: 'BK',
            );
            $code = CableProductCode::parse($generated);

            self::assertNotNull($code, $generated);
            self::assertTrue($code->matches($row), $generated);
        }
    }

    public function testRecognisesAwgGauges(): void
    {
        $code = CableProductCode::parse('BB-XXXD/1C1/0OR');

        self::assertNotNull($code);
        self::assertTrue($code->isAwg());
    }

    public function testDoesNotMatchAnAwgRowOfTheSameNumber(): void
    {
        $code = CableProductCode::parse('BB-XXXD/1C1BK');

        self::assertNotNull($code);
        self::assertFalse($code->matches(['cable_style' => 'BB', 'nc' => '1', 'cc' => 'c', 'xa' => '1/0', 'ne' => '0', 'ct' => 'p']));
    }
}
