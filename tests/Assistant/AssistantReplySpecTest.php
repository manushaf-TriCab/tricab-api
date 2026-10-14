<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Assistant;

use PHPUnit\Framework\TestCase;
use TriCab\Api\Assistant\Application\AssistantReplySpec;
use TriCab\Core\ProductFinder\Application\DTO\ProductFinderQuery;

final class AssistantReplySpecTest extends TestCase
{
    public function test_a_full_query_is_normalised_with_labels(): void
    {
        $spec = AssistantReplySpec::fromFinderQuery(new ProductFinderQuery(
            text: 'ignored',
            style: 'XL',
            coreCount: 4,
            crossSectionMm2: 16.0,
            colour: 'orange',
            loadAmps: 63.0,
            lengthM: 120.0,
            voltage: 400,
            phase: 3,
            install: 5,
            ambient: 40,
        ));

        self::assertSame([
            'coreCount' => 4,
            'crossSectionMm2' => 16.0,
            'colour' => 'orange',
            'colourLabel' => 'Orange',
            'style' => 'XL',
            'voltage' => 400,
            'voltageLabel' => '400 / 415 V',
            'phase' => 3,
            'phaseLabel' => 'Three phase',
            'installMethod' => ['code' => 5, 'label' => 'Conduit in air'],
            'ambientC' => 40,
            'loadAmps' => 63.0,
            'lengthM' => 120.0,
        ], $spec);
    }

    public function test_a_partial_query_has_every_key_with_null_for_the_unknown(): void
    {
        $spec = AssistantReplySpec::fromFinderQuery(new ProductFinderQuery(style: 'KL', colour: 'OR'));

        self::assertSame('OR', $spec['colour']);
        self::assertSame('Orange', $spec['colourLabel']);
        self::assertSame('KL', $spec['style']);
        foreach (['coreCount', 'crossSectionMm2', 'voltage', 'voltageLabel', 'phase', 'phaseLabel', 'installMethod', 'ambientC', 'loadAmps', 'lengthM'] as $key) {
            self::assertArrayHasKey($key, $spec);
            self::assertNull($spec[$key], $key);
        }
        self::assertCount(13, $spec);
    }

    public function test_phase_codes_are_labelled_as_the_finder_uses_them(): void
    {
        self::assertSame('Single phase', AssistantReplySpec::fromFinderQuery(new ProductFinderQuery(phase: 4))['phaseLabel']);
        self::assertSame('DC', AssistantReplySpec::fromFinderQuery(new ProductFinderQuery(phase: 5))['phaseLabel']);
        self::assertSame('Phase 7', AssistantReplySpec::fromFinderQuery(new ProductFinderQuery(phase: 7))['phaseLabel']);
    }

    public function test_an_unknown_install_code_and_voltage_and_colour_do_not_break_labelling(): void
    {
        $spec = AssistantReplySpec::fromFinderQuery(new ProductFinderQuery(
            style: 'XL',
            colour: 'mauve',
            voltage: 3300,
            install: 42,
        ));

        self::assertSame(['code' => 42, 'label' => 'Method 42'], $spec['installMethod']);
        self::assertSame('mauve', $spec['colour']);
        self::assertNull($spec['colourLabel']);
        self::assertSame('3300 V', $spec['voltageLabel']);
    }

    public function test_a_blank_colour_is_null_and_a_grey_spelling_is_labelled(): void
    {
        self::assertNull(AssistantReplySpec::fromFinderQuery(new ProductFinderQuery(style: 'XL', colour: '  '))['colour']);
        self::assertSame('Grey', AssistantReplySpec::fromFinderQuery(new ProductFinderQuery(style: 'XL', colour: 'gray'))['colourLabel']);
    }

    public function test_a_code_search_with_no_shared_facets_gives_no_spec(): void
    {
        self::assertNull(AssistantReplySpec::fromCodeSearch(null, null, null, null, null));
        $spec = AssistantReplySpec::fromCodeSearch(3, 2.5, 2.5, 'BK', 'KL');
        self::assertIsArray($spec);
        self::assertSame(2.5, $spec['crossSectionMm2']);
        self::assertSame('Black', $spec['colourLabel']);
        $range = AssistantReplySpec::fromCodeSearch(3, 1.5, 4.0, null, null);
        self::assertIsArray($range);
        self::assertNull($range['crossSectionMm2']);
    }
}
