<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Technical;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use TriCab\Api\Technical\TechnicalContentRepository;

/**
 * Phase 3 product-coding packs + blocked document catalog inventory.
 */
final class TechnicalProductCodingAndDocumentsTest extends TestCase
{
    private TechnicalContentRepository $repo;

    protected function setUp(): void
    {
        $this->repo = TechnicalContentRepository::fromProjectRoot(dirname(__DIR__, 2));
    }

    public function test_industrial_product_coding_has_nine_segments_and_defaults(): void
    {
        $pack = $this->repo->productCoding('industrial');

        self::assertSame('industrial', $pack['mode']);
        self::assertCount(9, $pack['segments']);
        self::assertSame(
            [
                'styleCode' => 'KL',
                'jacketType' => 'T',
                'coreColour' => 'PA',
                'braidArmourScreen' => 'X',
                'conductor' => 'N',
                'conductorCount' => '1',
                'coreType' => 'C',
                'crossSection' => '240',
                'jacketColour' => 'OR',
            ],
            $pack['example']['defaults'],
        );
    }

    public function test_sbo_product_coding_defaults(): void
    {
        $pack = $this->repo->productCoding('sbo');

        self::assertSame('sbo', $pack['mode']);
        self::assertSame(
            [
                'styleCode' => 'DV',
                'jacketType' => 'A',
                'coreColour' => 'PF',
                'braidArmourScreen' => 'T',
                'conductor' => 'D',
                'conductorCount' => '4',
                'coreType' => 'A',
                'crossSection' => '14',
                'jacketColour' => 'BK',
            ],
            $pack['example']['defaults'],
        );
    }

    public function test_accessories_product_coding_defaults_and_pack_quantity_labels(): void
    {
        $pack = $this->repo->productCoding('accessories');

        self::assertSame('accessories', $pack['mode']);
        self::assertSame('A01', $pack['example']['defaults']['styleCode']);

        $packQuantity = null;
        foreach ($pack['segments'] as $segment) {
            if (($segment['key'] ?? '') === 'coreColour') {
                $packQuantity = $segment;
                break;
            }
        }
        self::assertNotNull($packQuantity);
        self::assertSame('Pack Quantity', $packQuantity['label']);
        self::assertSame('packQuantity', $packQuantity['optionGroup']);
    }

    public function test_documents_bep_pvc_disposal_returns_one_blocked_item(): void
    {
        $docs = $this->repo->documents('bep-pvc-disposal');

        self::assertSame('bep-pvc-disposal', $docs['category']);
        self::assertCount(1, $docs['items']);
        self::assertSame('BLOCKED', $docs['items'][0]['migrationStatus']);
    }

    public function test_documents_installation_guides_returns_eighteen_items(): void
    {
        $docs = $this->repo->documents('installation-guides');

        self::assertSame('installation-guides', $docs['category']);
        self::assertCount(18, $docs['items']);
    }

    public function test_invalid_product_coding_mode_throws(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid Technical content key');
        $this->repo->productCoding('not-a-mode');
    }
}
