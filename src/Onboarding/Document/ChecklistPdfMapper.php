<?php

declare(strict_types=1);

namespace TriCab\Api\Onboarding\Document;

use TriCab\Api\Onboarding\FormImport\ChecklistFormDefinition;
use TriCab\Core\Onboarding\Application\DTO\Document\FormSubmissionForDocument;

/**
 * The New Starter Induction Checklist onto HR-FOR-003-V006 (5.4), ported from
 * the source's `generateChecklistPdf`. Made when a reviewer accepts the form,
 * because HR and the manager fill most of it.
 *
 * "Authenticated <date>" is what the source printed for an acknowledgement
 * given while signed in: the starter's is the submission date, the manager's
 * the date they recorded with their sign-off.
 */
final class ChecklistPdfMapper implements FormPdfMapper
{
    private const DAY_TOPS = [266, 280, 294, 309, 323, 337, 351, 365, 379, 393];
    private const WEEK_TOPS = [462, 476, 490, 504, 518, 532, 547, 561, 575, 589, 603, 617, 631, 645, 660, 674, 688, 702];
    private const SIZE = 6.4;
    private const MIN = 4.4;

    public function formSlug(): string
    {
        return ChecklistFormDefinition::SLUG;
    }

    public function renderedAt(): string
    {
        return 'completion';
    }

    public function documentKind(): string
    {
        return 'workflow';
    }

    public function render(FormAnswers $a, FormSubmissionForDocument $submission, string $templateDirectory): string
    {
        $pdf = new TemplatePdf(rtrim($templateDirectory, '/') . '/new-starter-checklist-v006.pdf');
        if ($pdf->pageCount() !== 1) {
            throw new \RuntimeException('The checklist template must contain one page.');
        }
        $pdf->page(1);
        $draw = static fn (string $value, float $x, float $top, float $width, float $size = self::SIZE, bool $bold = false) => $pdf->draw($value, $x, $top, $width, $size, $bold, self::MIN);

        $draw($a->text('employeeName'), 185, 95, 350, 7);
        $draw(TemplatePdf::formatDate($a->text('startDate')), 185, 115, 350, 7);
        $draw($a->text('position'), 185, 135, 350, 7);
        $draw($a->text('managerName'), 185, 155, 350, 7);
        $draw($a->text('department'), 185, 176, 350, 7);
        $draw($a->text('branch'), 185, 196, 350, 7);

        foreach ([['dayOne', ChecklistFormDefinition::DAY_ONE, self::DAY_TOPS], ['weekOne', ChecklistFormDefinition::WEEK_ONE, self::WEEK_TOPS]] as [$section, $items, $tops]) {
            foreach (array_keys($items) as $index => $item) {
                $state = $a->text("{$section}.{$item}.state");
                $top = $tops[$index] ?? null;
                if ($top === null || ($state !== 'completed' && $state !== 'not_applicable')) {
                    continue;
                }
                $draw(sprintf(
                    '%s · %s · %s',
                    $state === 'not_applicable' ? 'N/A' : 'Done',
                    $a->text("{$section}.{$item}.responsible"),
                    TemplatePdf::formatDate($a->text("{$section}.{$item}.completedOn")),
                ), 468, $top, 82, 4.8);
            }
        }
        $draw($a->text('dayOneNotes'), 150, 408, 380, 6.2);

        $starterName = $a->text('employeeName') !== '' ? $a->text('employeeName') : $submission->personDisplayName;
        // 722, not the source's 716: at 716 the line sat above its box on this
        // template, as the manager's row at 743 shows the right offset.
        $draw($starterName, 138, 722, 190);
        if ($a->ticked('employeeAcknowledged')) {
            $draw('Authenticated ' . TemplatePdf::formatDate(substr($submission->submittedAtUtc, 0, 10)), 405, 722, 135, 5.8, true);
        }
        $draw($a->text('managerName'), 182, 743, 145);
        if ($a->ticked('managerAcknowledged')) {
            $draw('Authenticated ' . TemplatePdf::formatDate($a->text('managerAcknowledgedOn')), 405, 743, 135, 5.8, true);
        }
        $draw($a->text('submittedToHrBy'), 130, 763, 200);
        $draw(TemplatePdf::formatDate($a->text('submittedToHrOn')), 390, 763, 145);

        $pdf->metadata('TriCab New Starter Induction Checklist - completed', sprintf('HR-FOR-003-V006, revision %d', $submission->revisionNumber));

        return $pdf->bytes();
    }
}
