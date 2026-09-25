<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Onboarding;

use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use TriCab\Api\Onboarding\Document\TemplatePdf;
use TriCab\Core\Onboarding\Domain\ValueObject\SignatureImage;
use TriCab\Core\Tests\Onboarding\Domain\SignatureFieldTest;

/** Phase 5.4 — a drawn signature lands on a paper template as an image. */
#[RequiresPhpExtension('gd')]
final class TemplatePdfSignatureTest extends TestCase
{
    public function test_a_signature_is_embedded_as_an_image_and_leaves_no_temporary_file(): void
    {
        $before = glob(sys_get_temp_dir() . '/sig*') ?: [];
        $png = SignatureImage::fromDataUrl('Signature', SignatureFieldTest::png(400, 120))->png;

        $pdf = new TemplatePdf(dirname(__DIR__, 2) . '/resources/onboarding/form-templates/employee-details-v007.pdf');
        $pdf->page(1);
        $pdf->signature($png, 60, 740, 245, 30);
        $bytes = $pdf->bytes();

        self::assertStringContainsString('/Subtype /Image', $bytes);
        self::assertSame($before, glob(sys_get_temp_dir() . '/sig*') ?: []);
    }
}
