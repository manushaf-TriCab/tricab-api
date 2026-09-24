<?php

declare(strict_types=1);

namespace TriCab\Api\Onboarding\Document;

use TriCab\Api\Onboarding\FormImport\EmployeeDetailsFormDefinition;
use TriCab\Core\Onboarding\Application\DTO\Document\FormSubmissionForDocument;

/**
 * Paints an Employee Details submission onto HR-FOR-004-V007 (5.4), ported
 * coordinate for coordinate from the source's `employee-details-pdf.ts`.
 *
 * The web form stays the record; this is the portable copy HR prints or files.
 * It carries bank details and the payslip PIN, so the document is C3 and every
 * read is streamed and audited.
 */
final class EmployeeDetailsPdfMapper implements FormPdfMapper
{
    public const TEMPLATE = 'employee-details-v007.pdf';

    private const IMMIGRATION_MARKS = [
        'australian_citizen' => [136, 288],
        'nz_citizen' => [225, 288],
        'permanent_resident' => [285, 288],
        'other' => [62, 301],
    ];

    public function formSlug(): string
    {
        return EmployeeDetailsFormDefinition::SLUG;
    }

    public function documentKind(): string
    {
        return 'employee_details';
    }

    public function render(FormAnswers $a, FormSubmissionForDocument $submission, string $templateDirectory): string
    {
        $pdf = new TemplatePdf(rtrim($templateDirectory, '/') . '/' . self::TEMPLATE);
        if ($pdf->pageCount() !== 1) {
            throw new \RuntimeException('The Employee Details template must contain exactly one page.');
        }
        $pdf->page(1);

        $pdf->draw($a->text('surname'), 89, 116, 210);
        $pdf->draw($a->text('givenName'), 382, 116, 157);
        $pdf->draw($a->text('preferredName'), 120, 130, 173);
        $pdf->draw(TemplatePdf::formatDate($a->text('dateOfBirth')), 390, 130, 142);
        $pdf->draw($a->text('address'), 86, 144, 350);
        $pdf->draw($a->text('postcode'), 480, 144, 60);
        $pdf->draw($a->text('homePhone'), 104, 158, 180);
        $pdf->draw($a->text('mobile'), 344, 158, 195);
        $pdf->draw(match ($a->text('aboriginalOrTorresStraitIslander')) {
            'yes' => 'Yes',
            'no' => 'No',
            default => '',
        }, 220, 178, 70);
        $pdf->draw($a->text('culturalIdentity'), 382, 172, 155);

        match ($a->text('genderIdentity')) {
            'male' => $pdf->mark(164, 198),
            'female' => $pdf->mark(198, 198),
            'nonbinary' => $pdf->mark(241, 198),
            default => null,
        };
        if ($a->ticked('meals.vegetarian')) {
            $pdf->mark(326, 212);
        }
        if ($a->ticked('meals.vegan')) {
            $pdf->mark(383, 212);
        }
        if ($a->ticked('meals.other')) {
            $pdf->mark(422, 212);
        }
        $pdf->draw($a->text('meals.otherDetails'), 458, 212, 80, 7.2);
        $pdf->draw($a->text('medicalConditions') !== '' ? $a->text('medicalConditions') : 'No', 205, 226, 330, 7.5);

        $status = self::IMMIGRATION_MARKS[$a->text('immigrationStatus')] ?? null;
        if ($status !== null) {
            $pdf->mark($status[0], $status[1]);
        }
        $pdf->draw($a->text('immigrationOtherDetails'), 82, 314, 455, 7.2);
        $pdf->draw($a->text('passportNumber'), 115, 331, 68, 7.5);
        $pdf->draw($a->text('visaNumberAndSubclass'), 305, 331, 110, 7.5);
        $pdf->draw(TemplatePdf::formatDate($a->text('visaExpiry')), 468, 331, 70, 7.5);

        self::account($pdf, $a, 'bank1', 420);
        self::account($pdf, $a, 'bank2', 481);

        $pdf->draw($a->text('payslipEmail'), 125, 573, 284, 7.8);
        $pdf->draw($a->text('payslipPin'), 444, 573, 85, 7.8);
        self::contact($pdf, $a, 'emergency1', 617);
        self::contact($pdf, $a, 'emergency2', 665);

        $legalName = trim($a->text('givenName') . ' ' . $a->text('surname'));
        $pdf->draw($legalName, 66, 707, 170, 8);
        $pdf->draw($a->text('address'), 255, 707, 280, 8);
        $pdf->draw('Electronically acknowledged by ' . $legalName, 60, 748, 245, 7.5, true);
        $pdf->draw(TemplatePdf::formatDate(substr($submission->submittedAtUtc, 0, 10)), 330, 748, 160, 8);

        $pdf->metadata(
            'TriCab Employee Details Form - completed',
            sprintf('%s submitted by %s, revision %d', EmployeeDetailsFormDefinition::DOCUMENT_REFERENCE, $submission->personDisplayName, $submission->revisionNumber),
        );

        return $pdf->bytes();
    }

    private static function account(TemplatePdf $pdf, FormAnswers $a, string $prefix, float $top): void
    {
        $pdf->draw($a->text("{$prefix}.bankName"), 105, $top, 173, 7.8);
        $pdf->draw($a->text("{$prefix}.branch"), 340, $top, 192, 7.8);
        $pdf->draw($a->text("{$prefix}.bsb"), 94, $top + 16, 163, 7.8);
        $pdf->draw($a->text("{$prefix}.accountNumber"), 350, $top + 16, 183, 7.8);
        $pdf->draw($a->text("{$prefix}.accountName"), 126, $top + 32, 223, 7.8);

        $value = $a->text("{$prefix}.allocationValue");
        $percentage = $a->text("{$prefix}.allocationType") === 'percentage';
        $pdf->draw($value === '' ? '' : ($percentage ? $value . '%' : '$' . $value), 472, $top + 32, 60, 7.8);
    }

    private static function contact(TemplatePdf $pdf, FormAnswers $a, string $prefix, float $top): void
    {
        $pdf->draw($a->text("{$prefix}.fullName"), 101, $top, 197, 7.8);
        $pdf->draw($a->text("{$prefix}.relationship"), 366, $top, 168, 7.8);
        $pdf->draw($a->text("{$prefix}.phone"), 102, $top + 16, 196, 7.8);
    }
}
