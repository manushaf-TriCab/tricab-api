<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\ProductFinder;

use PHPUnit\Framework\TestCase;
use TriCab\Api\Http\Action\ProductFinder\ProductFinderRequestMapper;

final class ProductFinderRequestMapperTest extends TestCase
{
    public function test_blank_strings_are_not_stated_values(): void
    {
        // The web form sends "" for an untouched field. Coerced, that becomes
        // cable type 0 — "Switchboard" — a stated answer nobody gave. This is
        // the same class of bug Phase 2 found in the assistant interpreter,
        // where the raw search box was treated as an authoritative term.
        $query = ProductFinderRequestMapper::fromHttpBody([
            'text' => '  ',
            'cableType' => '',
            'style' => '',
            'coreCount' => '',
            'crossSection' => '',
        ]);

        self::assertNull($query->text);
        self::assertNull($query->cableType);
        self::assertNull($query->style);
        self::assertNull($query->coreCount);
        self::assertTrue($query->isEmpty());
    }

    public function test_numbers_arrive_as_numbers_whether_typed_or_stringified(): void
    {
        $query = ProductFinderRequestMapper::fromHttpBody([
            'text' => ' 4 core 16mm2 ',
            'cableType' => '4',
            'coreCount' => 4,
            'crossSection' => '16.5',
            'loadAmps' => 120,
            'lengthM' => '80',
        ]);

        self::assertSame('4 core 16mm2', $query->text);
        self::assertSame(4, $query->cableType);
        self::assertSame(4, $query->coreCount);
        self::assertSame(16.5, $query->crossSectionMm2);
        self::assertSame(120.0, $query->loadAmps);
        self::assertSame(80.0, $query->lengthM);
        self::assertFalse($query->isEmpty());
    }

    public function test_a_stated_zero_survives(): void
    {
        // Cable type 0 is Switchboard, a real choice. Only absent and blank are
        // "not stated".
        $query = ProductFinderRequestMapper::fromHttpBody(['cableType' => 0]);

        self::assertSame(0, $query->cableType);
        self::assertFalse($query->isEmpty());
        self::assertSame(0, $query->toPayload()['cableType']);
    }

    public function test_non_numeric_junk_is_dropped_rather_than_coerced(): void
    {
        $query = ProductFinderRequestMapper::fromHttpBody([
            'text' => 'x',
            'coreCount' => 'four',
            'loadAmps' => ['nested'],
        ]);

        self::assertNull($query->coreCount);
        self::assertNull($query->loadAmps);
    }

    public function test_ai_is_on_unless_it_is_switched_off(): void
    {
        self::assertTrue(ProductFinderRequestMapper::fromHttpBody(['text' => 'x'])->allowAi);
        self::assertFalse(ProductFinderRequestMapper::fromHttpBody(['text' => 'x', 'allowAi' => false])->allowAi);
        self::assertFalse(ProductFinderRequestMapper::fromHttpBody(['text' => 'x', 'allowAi' => 'no'])->allowAi);
        self::assertTrue(ProductFinderRequestMapper::fromHttpBody(['text' => 'x', 'allowAi' => 'true'])->allowAi);
    }
}
