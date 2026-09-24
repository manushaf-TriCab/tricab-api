<?php

declare(strict_types=1);

namespace TriCab\Api\Onboarding\Document;

use TriCab\Core\Onboarding\Application\DTO\Document\AssessmentDocumentInput;
use TriCab\Core\Onboarding\Application\DTO\Document\RenderedDocument;
use TriCab\Core\Onboarding\Contract\OnboardingAssessmentDocumentRenderer;

/**
 * Marks an attempt onto its module's paper assessment sheet (5.4), ported
 * table for table from the source's `assessment-pdf.ts`.
 *
 * Keyed by the module's slug (the source keyed by document reference; the
 * import keeps both, see {@see \TriCab\Api\Onboarding\TrainingImport\SourceTrainingConverter::MODULES}).
 * A module's variants are chosen by question count, so an attempt sat against a
 * superseded sheet is marked on the sheet it was sat on.
 *
 * An answer is marked by its position among the question's options — the sheet
 * prints the wording, and the platform keeps the sheet's order.
 */
final class AssessmentPdfMapper implements OnboardingAssessmentDocumentRenderer
{
    /**
     * @var array<string, array{reference: string, variants: list<array{
     *     filename: string,
     *     optionX: int|float,
     *     detailsPage: int,
     *     detailsErase?: array{int|float, int|float, int|float, int|float},
     *     details: array{name: array{int|float, int|float, int|float}, manager: array{int|float, int|float, int|float}, date: array{int|float, int|float, int|float}, location?: array{int|float, int|float, int|float}, position?: array{int|float, int|float, int|float}, department?: array{int|float, int|float, int|float}},
     *     optionTops: list<list<int|float>>,
     *     questionPages: list<int>,
     *     result: array{page: int, score: array{int|float, int|float, int|float}, outcome: array{int|float, int|float, int|float}},
     *     signOff?: array{competentX: int|float, notCompetentX: int|float, top: int|float, feedback: array{int|float, int|float, int|float, int|float, int|float}, receivedBy: array{int|float, int|float, int|float}, dateErase: array{int|float, int|float, int|float, int|float}, date: array{int|float, int|float, int|float}}
     * }>}>
     */
    private const TEMPLATES = [
        'workplace-sexual-harassment' => ['reference' => 'HR-DOC-094-V000', 'variants' => [[
            'filename' => 'workplace-sexual-harassment-v000.pdf',
            'optionX' => 129,
            'detailsPage' => 1,
            'details' => ['name' => [128, 117, 150], 'location' => [420, 117, 120], 'manager' => [188, 149, 92], 'date' => [407, 149, 130]],
            'optionTops' => [
                [196, 211.6, 227.1], [273.6, 289.2, 304.6], [351.1, 366.8, 382.2, 397.6],
                [444.1, 459.8, 475.2, 490.9], [537.4, 568.2, 583.9], [645.8, 661.5, 676.9, 692.5],
                [133.1, 148.6, 164.2, 179.6], [226.1, 257.2, 272.6], [319.1, 350.2, 365.9],
                [427.8, 443.2, 458.9, 474.3], [536.5, 551.9], [614, 629.5], [691.6, 707],
                [148.6, 164.2], [226.1, 241.6, 257.2, 288.3], [334.8, 365.9, 381.3, 396.7],
                [443.2, 458.9, 474.3, 490], [567.3, 582.9, 598.4], [676, 691.6, 707, 722.5],
                [179.6, 195, 210.7], [272.6, 288.3, 303.7], [365.9, 381.3, 396.7, 412.4],
            ],
            'questionPages' => [1, 1, 1, 1, 1, 1, 2, 2, 2, 2, 2, 2, 2, 3, 3, 3, 3, 3, 3, 4, 4, 4],
            'result' => ['page' => 4, 'score' => [148, 475, 70], 'outcome' => [380, 475, 150]],
        ]]],
        'respect-at-work' => ['reference' => 'HR-DOC-098-V000', 'variants' => [[
            'filename' => 'respect-at-work-v000.pdf',
            'optionX' => 129,
            'detailsPage' => 1,
            'details' => ['name' => [128, 116, 150], 'location' => [420, 116, 120], 'manager' => [188, 145, 92], 'date' => [407, 145, 130]],
            'optionTops' => [
                [188.1, 202.2, 216.4, 230.7], [273.3, 301.9, 316.1, 330.2],
                [373, 387, 401.3, 415.6], [458.2, 472.5, 486.7, 500.8], [539.9, 553.9, 568.2, 582.5],
                [625.1, 639.4, 667.9, 681.9], [130.4, 144.4, 158.7, 173], [215.5, 229.8, 244.1, 258.1],
                [299.1, 313.2, 327.4, 341.7], [384.3, 398.6, 412.8, 427.1], [484, 498, 512.3, 526.5],
                [597.7, 612, 626, 640.3], [199.4, 213.7, 228], [384.3, 398.6, 412.8],
                [455.4, 484, 512.3, 526.5], [567.3, 581.6, 595.8, 610.1], [652.7, 667, 681, 709.6],
                [130.4, 158.7, 173, 187.2], [229.8, 244.1, 258.1, 272.4], [329.3, 343.5, 357.8, 386.1],
                [443, 457.2, 471.5, 485.8], [571, 585.3, 599.5, 613.8],
            ],
            'questionPages' => [1, 1, 1, 1, 1, 1, 2, 2, 2, 2, 2, 2, 3, 3, 3, 3, 3, 4, 4, 4, 4, 4],
            'result' => ['page' => 4, 'score' => [148, 671, 70], 'outcome' => [380, 671, 150]],
        ]]],
        'corporate-whse-induction' => ['reference' => 'HR-DOC-009-V010', 'variants' => [
            [
                // WHSE-DOC-003-V007 (issued August 2026): 17 questions over four pages.
                'filename' => 'whse-corporate-assessment-v007.pdf',
                'optionX' => 44,
                'detailsPage' => 0,
                'detailsErase' => [64, 169, 136, 22],
                'details' => ['name' => [96, 95, 200], 'position' => [88, 115, 200], 'department' => [107, 135, 200], 'manager' => [157, 155, 175], 'date' => [68, 175, 120]],
                'optionTops' => [
                    [293.1, 313.3, 333.4, 353.6], [422.7, 442.6, 462.8, 483],
                    [552.1, 572.2, 592.4, 612.6], [681.4, 701.6, 721.8, 741.9],
                    [133.5, 153, 172.4, 191.8], [290.2, 305.8], [383.6, 403],
                    [474.6, 490.2, 505.5, 520.9, 536.5], [607.5, 622.9, 638.2, 653.8],
                    [738.1, 753.7, 769], [153.7, 169, 184.4, 200], [271, 286.4, 301.8],
                    [372.8, 388.4, 403.8, 419.1], [490.2, 505.5, 521.1, 536.5],
                    [607.5, 622.9, 638.5, 653.8], [724.9, 740.2, 755.8, 771.2],
                    [140.2, 155.6, 171.2, 186.6],
                ],
                'questionPages' => [0, 0, 0, 0, 1, 1, 1, 1, 1, 1, 2, 2, 2, 2, 2, 2, 3],
                'result' => ['page' => 3, 'score' => [0, 0, 0], 'outcome' => [0, 0, 0]],
                'signOff' => [
                    'competentX' => 100, 'notCompetentX' => 181, 'top' => 244.5,
                    'feedback' => [36, 289, 500, 45, 7], 'receivedBy' => [142, 310, 160],
                    'dateErase' => [358, 297, 136, 22], 'date' => [362, 310, 124],
                ],
            ],
            [
                // WHSE-DOC-003-V006 (superseded May 2026 sheet): 14 questions, three pages.
                'filename' => 'whse-corporate-assessment-v006.pdf',
                'optionX' => 54,
                'detailsPage' => 0,
                'detailsErase' => [74, 179, 132, 22],
                'details' => ['name' => [125, 108, 200], 'manager' => [165, 166, 175], 'date' => [80, 185, 120], 'position' => [118, 127, 200], 'department' => [138, 147, 200]],
                'optionTops' => [
                    [297.5, 316.8, 336.1, 355.4], [421.6, 441, 460.3, 479.7],
                    [545.8, 565.2, 584.4, 603.8], [669.9, 689.3, 708.6, 728],
                    [145, 163.7, 182.3, 200.8], [295.5, 310.2], [384.8, 403.5],
                    [472.3, 487, 501.9, 516.7, 531.4], [599.6, 614.5, 629.3, 644],
                    [712.2, 727, 741.8, 756.6], [151.5, 166.2, 181, 195.9],
                    [263.9, 278.8, 293.6, 308.5], [376.5, 391.4, 406.2, 421],
                    [489.1, 503.9, 518.7, 533.5],
                ],
                'questionPages' => [0, 0, 0, 0, 1, 1, 1, 1, 1, 1, 2, 2, 2, 2],
                'result' => ['page' => 2, 'score' => [0, 0, 0], 'outcome' => [0, 0, 0]],
                'signOff' => [
                    'competentX' => 108, 'notCompetentX' => 185, 'top' => 603,
                    'feedback' => [47, 646, 500, 45, 7], 'receivedBy' => [148, 666, 160],
                    'dateErase' => [356, 653, 132, 22], 'date' => [360, 666, 124],
                ],
            ],
        ]],
    ];

    private const TEXT_SIZE = 6.4;
    private const TEXT_MIN = 4.6;

    public function __construct(private readonly string $templateDirectory)
    {
    }

    /**
     * @param array{int|float, int|float, int|float} $spec x, top, width
     * @return \Closure(TemplatePdf): void
     */
    private static function detailText(array $spec, string $value): \Closure
    {
        return static fn (TemplatePdf $p) => $p->draw($value, $spec[0], $spec[1], $spec[2], self::TEXT_SIZE, false, self::TEXT_MIN);
    }

    public static function hasSheet(string $moduleSlug): bool
    {
        return isset(self::TEMPLATES[$moduleSlug]);
    }

    public function render(AssessmentDocumentInput $input): ?RenderedDocument
    {
        $module = self::TEMPLATES[$input->moduleSlug] ?? null;
        if ($module === null) {
            return null;
        }
        $map = null;
        foreach ($module['variants'] as $variant) {
            if (count($variant['optionTops']) === count($input->questions)) {
                $map = $variant;
                break;
            }
        }
        if ($map === null) {
            throw new \RuntimeException(sprintf('%s has no sheet for %d questions.', $module['reference'], count($input->questions)));
        }

        $pdf = new TemplatePdf(rtrim($this->templateDirectory, '/') . '/' . $map['filename']);
        /** @var array<int, list<\Closure(TemplatePdf): void>> $ops page index => what to paint on it */
        $ops = [];
        $details = $map['details'];
        $detailsPage = $map['detailsPage'];

        if (isset($map['detailsErase'])) {
            $erase = $map['detailsErase'];
            $ops[$detailsPage][] = static fn (TemplatePdf $p) => $p->erase(...$erase);
        }
        $ops[$detailsPage][] = self::detailText($details['name'], $input->personName);
        if (isset($details['location'])) {
            $ops[$detailsPage][] = self::detailText($details['location'], $input->branch !== '' ? $input->branch : $input->department);
        }
        $ops[$detailsPage][] = self::detailText($details['manager'], $input->managerName);
        $ops[$detailsPage][] = self::detailText($details['date'], TemplatePdf::formatDate(substr($input->submittedAtUtc, 0, 10)));
        if (isset($details['position'])) {
            $ops[$detailsPage][] = self::detailText($details['position'], $input->position);
        }
        if (isset($details['department'])) {
            $ops[$detailsPage][] = self::detailText($details['department'], $input->department);
        }

        $optionX = $map['optionX'];
        foreach ($input->questions as $index => $question) {
            if ($question['chosenKey'] === null) {
                continue;
            }
            $option = array_search($question['chosenKey'], $question['optionKeys'], true);
            $optionTop = is_int($option) ? ($map['optionTops'][$index][$option] ?? null) : null;
            if (is_int($optionTop) || is_float($optionTop)) {
                $ops[$map['questionPages'][$index]][] = static fn (TemplatePdf $p) => $p->mark($optionX, $optionTop);
            }
        }

        $result = $map['result'];
        if (isset($map['signOff'])) {
            $s = $map['signOff'];
            $x = $input->passed ? $s['competentX'] : $s['notCompetentX'];
            $ops[$result['page']][] = static fn (TemplatePdf $p) => $p->mark($x, $s['top']);
            // Supervisor feedback, "received by HR" and the review date are
            // written at review; at submission they are left blank, as the source did.
            $ops[$result['page']][] = static fn (TemplatePdf $p) => $p->erase(...$s['dateErase']);
        } else {
            $score = $input->scorePercent . '%';
            $outcome = sprintf('%s (pass mark %d%%)', $input->passed ? 'PASS' : 'NOT YET COMPETENT', $input->passMarkPercent);
            $ops[$result['page']][] = static fn (TemplatePdf $p) => $p->draw($score, $result['score'][0], $result['score'][1], $result['score'][2], 8.0, true, self::TEXT_MIN);
            $ops[$result['page']][] = static fn (TemplatePdf $p) => $p->draw($outcome, $result['outcome'][0], $result['outcome'][1], $result['outcome'][2], 7.0, true, self::TEXT_MIN);
        }

        for ($index = 0; $index < $pdf->pageCount(); ++$index) {
            $pdf->page($index + 1);
            foreach ($ops[$index] ?? [] as $op) {
                $op($pdf);
            }
        }

        $pdf->metadata(
            sprintf('TriCab %s - completed assessment', $input->moduleTitle),
            sprintf('%s submitted by %s', $module['reference'], $input->personName),
        );

        return new RenderedDocument('assessment', 'application/pdf', $pdf->bytes());
    }
}
