<?php

declare(strict_types=1);

namespace TriCab\Api\Onboarding\Document;

use TriCab\Core\Onboarding\Domain\Exception\InvalidOnboardingValue;
use TriCab\Core\Onboarding\Domain\ValueObject\SignatureImage;

/**
 * The medical questionnaire and the deed onto their paper forms (5.4), ported
 * coordinate for coordinate from the source's `legal-forms-pdf.ts`. The source
 * drew at a fixed size and truncated, and so do these.
 */
final class LegalFormPdfMappers
{
    /** @return list<FormPdfMapper> */
    public static function all(): array
    {
        return [new MedicalQuestionnairePdfMapper(), new ConfidentialityDeedPdfMapper()];
    }

    /** A stored signature's PNG bytes, or null when there is none to draw. */
    public static function signature(FormAnswers $a, string $path): ?string
    {
        $value = $a->text($path);
        if ($value === '') {
            return null;
        }
        try {
            return SignatureImage::fromDataUrl($path, $value)->png;
        } catch (InvalidOnboardingValue) {
            return null;
        }
    }

    /** The source's `drawTop`: one fixed size, truncated to fit. */
    public static function fixed(TemplatePdf $pdf, string $value, float $x, float $top, float $width, float $size): void
    {
        $pdf->draw($value, $x, $top, $width, $size, false, $size);
    }
}
