<?php

declare(strict_types=1);

namespace TriCab\Api\Onboarding\Document;

use TriCab\Api\Onboarding\FormImport\VehicleReportFormDefinition;
use TriCab\Core\Onboarding\Application\DTO\Document\FormSubmissionForDocument;

/**
 * The Vehicle Condition Report onto HR-FOR-024-V002 (5.4), ported from the
 * source's `generateVehiclePdf`: the inspection on page one with the first
 * photo, the driver's acknowledgement on page two, and a page per photo after.
 * Made when a reviewer accepts the form.
 */
final class VehicleReportPdfMapper implements FormPdfMapper
{
    private const CONDITION_X = ['new' => 91, 'as_new' => 158, 'used' => 259, 'poor' => 359];
    private const MIN = 4.4;

    public function formSlug(): string
    {
        return VehicleReportFormDefinition::SLUG;
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
        $pdf = new TemplatePdf(rtrim($templateDirectory, '/') . '/vehicle-condition-report-v002.pdf');
        if ($pdf->pageCount() !== 2) {
            throw new \RuntimeException('The Vehicle Condition Report template must contain two pages.');
        }
        $draw = static fn (string $value, float $x, float $top, float $width, float $size = 7, bool $bold = false) => $pdf->draw($value, $x, $top, $width, $size, $bold, self::MIN);
        $photos = $a->photos('photos');

        $pdf->page(1);
        $draw(TemplatePdf::formatDate($a->text('inspectionDate')), 120, 307, 420);
        $draw($a->text('inspectedBy'), 140, 322, 395);
        $draw($a->text('registration'), 125, 374, 175);
        $draw($a->text('year'), 330, 374, 200);
        $draw($a->text('make'), 125, 389, 175);
        $draw($a->text('model'), 330, 389, 200);
        $draw($a->text('bodyType'), 125, 404, 175);
        $draw($a->text('colour'), 330, 404, 200);
        $draw($a->text('transmission'), 125, 419, 175);
        $draw($a->text('odometer'), 365, 419, 165);
        $draw($a->text('nextServiceDue'), 150, 433, 140, 6.5);
        $firstAid = $a->text('firstAidKit');
        if ($firstAid === 'yes' || $firstAid === 'no') {
            $pdf->mark($firstAid === 'yes' ? 349 : 380, 434);
        }
        $logBook = $a->text('logBook');
        if ($logBook === 'yes' || $logBook === 'no') {
            $pdf->mark($logBook === 'yes' ? 472 : 503, 434);
        }
        $pdf->drawWrapped($a->text('comments'), 62, 674, 470, 26, 7);
        $condition = self::CONDITION_X[$a->text('overallCondition')] ?? null;
        if ($condition !== null) {
            $pdf->mark($condition, 722);
        }
        if (isset($photos[0])) {
            $pdf->photo($photos[0], 62, 476, 470, 165);
        }

        $pdf->page(2);
        $driver = $submission->personDisplayName;
        $draw($driver, 122, 307, 410);
        $draw($a->text('driverPosition'), 112, 329, 420);
        $draw($a->text('licenceNumber'), 160, 352, 165);
        $draw(TemplatePdf::formatDate($a->text('licenceExpiry')), 380, 352, 110);
        if ($a->ticked('licenceCopyAttached')) {
            $pdf->mark(494, 352);
        }
        if ($a->ticked('acknowledged')) {
            $draw('Authenticated by ' . $driver, 112, 374, 250, 6.3, true);
        }
        $draw(TemplatePdf::formatDate($a->text('handoverDate')), 455, 374, 75);

        foreach ($photos as $index => $photo) {
            $pdf->blankPage();
            $pdf->draw('TriCab Vehicle Condition Report — Photo Evidence', 48, 36, 500, 16, true, 16);
            $pdf->draw(sprintf('Vehicle: %s · Photo %d of %d', $a->text('registration'), $index + 1, count($photos)), 48, 64, 500, 10, false, 10);
            $pdf->photo($photo, 48, 110, 500, 610);
        }

        $pdf->metadata('TriCab Vehicle Condition Report - completed', sprintf('HR-FOR-024-V002, %s, revision %d', $a->text('registration'), $submission->revisionNumber));

        return $pdf->bytes();
    }
}
