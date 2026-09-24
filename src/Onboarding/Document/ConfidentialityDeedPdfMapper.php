<?php

declare(strict_types=1);

namespace TriCab\Api\Onboarding\Document;

use TriCab\Api\Onboarding\FormImport\LegalFormDefinitions;
use TriCab\Core\Onboarding\Application\DTO\Document\FormSubmissionForDocument;

final class ConfidentialityDeedPdfMapper implements FormPdfMapper
{
    public function formSlug(): string
    {
        return LegalFormDefinitions::DEED_SLUG;
    }

    public function documentKind(): string
    {
        return 'legal_forms';
    }

    public function render(FormAnswers $a, FormSubmissionForDocument $submission, string $templateDirectory): string
    {
        $pdf = new TemplatePdf(rtrim($templateDirectory, '/') . '/deed-of-confidentiality-and-restraint-of-trade.pdf');
        if ($pdf->pageCount() !== 5) {
            throw new \RuntimeException('The Confidentiality Deed template must contain five pages.');
        }

        $deedDate = $a->text('deedDate');
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $deedDate);

        for ($page = 1; $page <= 5; ++$page) {
            $pdf->page($page);
            if ($page === 1) {
                if ($date !== false) {
                    LegalFormPdfMappers::fixed($pdf, $date->format('j'), 389, 126, 26, 10);
                    LegalFormPdfMappers::fixed($pdf, $date->format('F'), 64, 146, 88, 10);
                    LegalFormPdfMappers::fixed($pdf, $date->format('y'), 207, 146, 25, 10);
                }
                LegalFormPdfMappers::fixed($pdf, $a->text('fullName'), 64, 243, 333, 10);
                LegalFormPdfMappers::fixed($pdf, $a->text('address'), 64, 263, 333, 10);
            }
            if ($page === 5) {
                $employee = LegalFormPdfMappers::signature($a, 'employeeSignature');
                if ($employee !== null) {
                    $pdf->signature($employee, 270, 648, 138, 24, 0);
                }
                LegalFormPdfMappers::fixed($pdf, TemplatePdf::formatDate($a->text('employeeSignedDate')), 427, 657, 100, 8);
                LegalFormPdfMappers::fixed($pdf, $a->text('fullName'), 145, 681, 120, 7);
                LegalFormPdfMappers::fixed($pdf, $a->text('witnessName'), 179, 707, 128, 7);
                $witness = LegalFormPdfMappers::signature($a, 'witnessSignature');
                if ($witness !== null) {
                    $pdf->signature($witness, 185, 735, 125, 24, 0);
                }
            }
        }

        $pdf->metadata('Deed of Confidentiality and Restraint of Trade - completed', sprintf('TRI-DOC-HR-010-V003 completed deed, revision %d', $submission->revisionNumber));

        return $pdf->bytes();
    }
}
