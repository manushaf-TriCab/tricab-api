<?php

declare(strict_types=1);

namespace TriCab\Api\Onboarding\Document;

use TriCab\Api\Onboarding\FormImport\LegalFormDefinitions;
use TriCab\Core\Onboarding\Application\DTO\Document\FormSubmissionForDocument;

final class MedicalQuestionnairePdfMapper implements FormPdfMapper
{
    private const ANSWER_TOPS = [272, 347, 422, 508, 594];
    private const DETAIL_TOPS = [286, 361, 436, 522, 608];

    public function formSlug(): string
    {
        return LegalFormDefinitions::MEDICAL_SLUG;
    }

    public function documentKind(): string
    {
        return 'legal_forms';
    }

    public function render(FormAnswers $a, FormSubmissionForDocument $submission, string $templateDirectory): string
    {
        $pdf = new TemplatePdf(rtrim($templateDirectory, '/') . '/whs-medical-questionnaire.pdf');
        if ($pdf->pageCount() !== 1) {
            throw new \RuntimeException('The WHS Medical Questionnaire template must contain one page.');
        }
        $pdf->page(1);

        LegalFormPdfMappers::fixed($pdf, $a->text('fullName'), 136, 207, 394, 8);
        LegalFormPdfMappers::fixed($pdf, $a->text('address'), 136, 222, 394, 8);
        LegalFormPdfMappers::fixed($pdf, $a->text('contactNumber'), 136, 237, 394, 8);

        foreach (array_keys(LegalFormDefinitions::MEDICAL_QUESTIONS) as $index => $path) {
            $answer = $a->text("{$path}.answer");
            if ($answer === 'yes' || $answer === 'no') {
                $pdf->mark($answer === 'yes' ? 58 : 91, self::ANSWER_TOPS[$index], 8);
            }
            $pdf->drawWrapped($a->text("{$path}.details"), 52, self::DETAIL_TOPS[$index], 488, 26, 7.2, 1.2);
        }

        $signature = LegalFormPdfMappers::signature($a, 'signature');
        if ($signature !== null) {
            $pdf->signature($signature, 120, 726, 189, 17, 0);
        }
        LegalFormPdfMappers::fixed($pdf, TemplatePdf::formatDate($a->text('signedDate')), 337, 728, 156, 8);

        $pdf->metadata('WHS Medical Questionnaire - completed', sprintf('WHSE-FOR-007-V005 completed questionnaire, revision %d', $submission->revisionNumber));

        return $pdf->bytes();
    }
}
