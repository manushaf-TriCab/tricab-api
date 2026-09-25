<?php

declare(strict_types=1);

namespace TriCab\Api\Onboarding\Document;

use TriCab\Core\Onboarding\Application\DTO\Document\PolicyDocumentContext;
use TriCab\Core\Onboarding\Application\DTO\Document\RenderedDocument;
use TriCab\Core\Onboarding\Contract\OnboardingPolicyDocumentRenderer;
use TriCab\Core\Onboarding\Domain\ValueObject\PolicyDeclaration;
use TriCab\Core\Onboarding\Domain\ValueObject\SignatureImage;

/**
 * A signed policy's own PDF, every page, with the declaration stamped onto its
 * sign-off block (5.4). Ported from the source's
 * `api/_lib/forms/policy-declaration-pdf.ts`.
 *
 * The boxes are the source's, as it wrote them: `[page (0-based), x1, y1, x2,
 * y2]` in points from the BOTTOM-left, pdf-lib's convention. {@see box()}
 * turns one into {@see TemplatePdf}'s top-down terms, so the numbers can be
 * checked against the source one for one.
 *
 * FPDI imports page content only, so the templates' interactive fields are
 * gone before anything is drawn. The source painted each box white first to
 * hide those fields; here there is nothing to hide, and painting would erase
 * the printed lines under the name and date. For the same reason the source's
 * redrawn Employee Declaration borders are not needed: its boxes are printed.
 */
/**
 * @phpstan-type Rect array{0: int, 1: float, 2: float, 3: float, 4: float}
 */
final class PolicyDeclarationPdfMapper implements OnboardingPolicyDocumentRenderer
{
    private const EMPLOYEE_DECLARATION = 'employee-declaration';

    /**
     * Template file, and the boxes of its name, signature and date.
     *
     * @var array<string, array{file: string, name: Rect, signature: Rect, date: Rect, licenceCopy?: Rect}>
     */
    private const SIGN_OFF = [
        self::EMPLOYEE_DECLARATION => [
            'file' => 'employee-declaration.pdf',
            'name' => [0, 161.3, 377.8, 442.1, 407.8],
            'signature' => [0, 163.7, 315.4, 440.3, 353.8],
            // The source date widget covers only half of its visibly printed box.
            'date' => [0, 164.9, 262.4, 440.3, 294.4],
        ],
        'HR-POL-001-V004' => [
            'file' => 'internet-usage-policy.pdf',
            'name' => [1, 306.02, 620.29, 514.36, 640.29],
            'signature' => [1, 90.7, 622.0, 281.5, 649.2],
            'date' => [1, 80.3, 572.8, 230.3, 596.0],
        ],
        'HR-POL-009-V004' => [
            'file' => 'corporate-credit-card-policy.pdf',
            'name' => [2, 82.33, 451.62, 224.53, 465.47],
            'signature' => [2, 227.6, 449.35, 367.7, 466.45],
            'date' => [2, 370.71, 451.62, 512.91, 465.47],
        ],
        'HR-POL-010-V003' => [
            'file' => 'company-vehicle-policy.pdf',
            'name' => [1, 153.09, 645.54, 441.85, 665.22],
            'signature' => [1, 124.1, 593.65, 224.9, 622.15],
            'date' => [1, 115.65, 625.63, 222.13, 645.42],
            'licenceCopy' => [1, 82.05, 665.8, 91.88, 675.64],
        ],
        'HR-POL-033-V008' => [
            'file' => 'workplace-behaviour-policy.pdf',
            'name' => [9, 82.33, 589.25, 224.51, 601.3],
            'signature' => [9, 227.6, 588.55, 367.7, 601.45],
            'date' => [9, 370.69, 589.25, 512.87, 601.3],
        ],
        'WHS-POL-001-V005' => [
            'file' => 'workplace-health-and-safety-policy.pdf',
            'name' => [1, 82.33, 589.25, 224.51, 601.3],
            'signature' => [1, 227.0, 589.75, 368.6, 600.85],
            'date' => [1, 370.69, 589.25, 512.87, 601.3],
        ],
    ];

    private const CODE_OF_CONDUCT_FILE = 'code-of-conduct.pdf';
    private const CODE_OF_CONDUCT_PAGE = 14;

    public function __construct(private readonly string $templateDirectory)
    {
    }

    public static function withTriCabTemplates(string $basePath): self
    {
        return new self($basePath . '/resources/onboarding/policy-templates');
    }

    public function render(PolicyDocumentContext $context): ?RenderedDocument
    {
        $declaration = $context->declaration;
        $reference = trim($context->documentReference);

        if ($declaration->kind === PolicyDeclaration::CODE_OF_CONDUCT && $reference === PolicyDeclaration::CODE_OF_CONDUCT_REFERENCE) {
            $pdf = $this->codeOfConduct($declaration);
        } elseif ($declaration->kind === PolicyDeclaration::SIGN_OFF) {
            $map = self::SIGN_OFF[$context->policySlug === self::EMPLOYEE_DECLARATION ? self::EMPLOYEE_DECLARATION : $reference] ?? null;
            if ($map === null) {
                return null;
            }
            $pdf = $this->signOff($map, $declaration);
        } else {
            return null;
        }

        $pdf->metadata(
            $context->policyTitle . ' - completed acknowledgement',
            sprintf('%s acknowledgement submitted %s', $reference !== '' ? $reference : 'Employee declaration', $context->acknowledgedAtUtc),
        );

        return new RenderedDocument('policy_declaration', 'application/pdf', $pdf->bytes());
    }

    /**
     * @param array{file: string, name: Rect, signature: Rect, date: Rect, licenceCopy?: Rect} $map
     */
    private function signOff(array $map, PolicyDeclaration $declaration): TemplatePdf
    {
        $pdf = new TemplatePdf($this->templateDirectory . '/' . $map['file']);

        for ($page = 1; $page <= $pdf->pageCount(); ++$page) {
            $pdf->page($page);
            $index = $page - 1;

            if ($map['name'][0] === $index) {
                $name = $this->box($pdf, $map['name']);
                $this->text($pdf, $declaration->text('fullName'), $name, $name[3] >= 18 ? 11 : 9, $name[3] >= 18 ? 9 : 7.8);
            }
            if ($map['date'][0] === $index) {
                $date = $this->box($pdf, $map['date']);
                $this->text($pdf, TemplatePdf::formatDate($declaration->text('date')), $date, $date[3] >= 18 ? 11 : 9);
            }
            if ($map['signature'][0] === $index) {
                $signature = $this->box($pdf, $map['signature']);
                $png = SignatureImage::fromDataUrl('signature', $declaration->text('signature'))->png;
                $pdf->signature($png, $signature[0], $signature[1], $signature[2], $signature[3], 2);
            }
            if (isset($map['licenceCopy']) && $map['licenceCopy'][0] === $index) {
                $this->checkbox($pdf, $this->box($pdf, $map['licenceCopy']), $declaration->flag('licenceCopyAttached'));
            }
        }

        return $pdf;
    }

    private function codeOfConduct(PolicyDeclaration $declaration): TemplatePdf
    {
        $pdf = new TemplatePdf($this->templateDirectory . '/' . self::CODE_OF_CONDUCT_FILE);
        if ($pdf->pageCount() <= self::CODE_OF_CONDUCT_PAGE) {
            throw new \RuntimeException('The Code of Conduct template has no sign-off page.');
        }
        $p = self::CODE_OF_CONDUCT_PAGE;

        for ($page = 1; $page <= $pdf->pageCount(); ++$page) {
            $pdf->page($page);
            if ($page - 1 !== $p) {
                continue;
            }

            $name = $this->box($pdf, [$p, 114.89, 331.13, 447.27, 348.25]);
            $division = $this->box($pdf, [$p, 160.52, 309.94, 446.58, 326.98]);
            $date = $this->box($pdf, [$p, 109.17, 291.46, 190.31, 307.5]);
            $this->text($pdf, $declaration->text('name'), $name, 10);
            $this->text($pdf, $declaration->text('divisionRegion'), $division, 10);
            $this->text($pdf, TemplatePdf::formatDate($declaration->text('date')), $date, 9.5);

            $accepted = $declaration->toArray()['accepted'] ?? null;
            $choices = [
                [[$p, 111.35, 675.45, 123.3, 687.85], $declaration->text('role') === 'executive'],
                [[$p, 183.73, 676.05, 195.82, 688.3], $declaration->text('role') === 'employee'],
                [[$p, 82.05, 486.37, 93.8, 498.12], $declaration->text('conflictStatus') === 'declared'],
                [[$p, 82.05, 452.3, 93.8, 464.08], $declaration->text('conflictStatus') === 'none'],
                [[$p, 82.05, 432.64, 93.8, 444.39], $declaration->flag('willAvoidUndeclaredConflict')],
                [[$p, 82.05, 398.82, 93.8, 410.57], $declaration->flag('understandsConsequences')],
                [[$p, 81.6, 251.6, 93.4, 264.2], $accepted === true],
                [[$p, 82.0, 231.8, 93.8, 244.2], $accepted === false],
            ];
            foreach ($choices as [$box, $checked]) {
                $this->checkbox($pdf, $this->box($pdf, $box), $checked);
            }
        }

        if ($declaration->text('conflictStatus') === 'declared' && $declaration->text('conflictDetails') !== '') {
            $this->conflictDetailsPage($pdf, $declaration);
        }

        return $pdf;
    }

    /** The source's continuation page for conflict-of-interest details that do not fit the form. */
    private function conflictDetailsPage(TemplatePdf $pdf, PolicyDeclaration $declaration): void
    {
        $pdf->blankPage();
        $pdf->draw('TriCab Group', 50, 59, 400, 11, true);
        $pdf->draw('Code of Conduct', 50, 78, 495, 22, true);
        $pdf->draw('Conflict-of-Interest declaration details', 50, 113, 495, 15, true);

        $pdf->draw('Name', 50, 172, 210, 8, true);
        $pdf->draw($declaration->text('name'), 50, 186, 210, 11);
        $pdf->draw('Division/Region', 300, 172, 245, 8, true);
        $pdf->draw($declaration->text('divisionRegion'), 300, 186, 245, 11);
        $pdf->draw('Declaration date', 50, 224, 210, 8, true);
        $pdf->draw(TemplatePdf::formatDate($declaration->text('date')), 50, 238, 210, 11);
        $pdf->draw('Document reference', 300, 224, 245, 8, true);
        $pdf->draw(PolicyDeclaration::CODE_OF_CONDUCT_REFERENCE, 300, 238, 245, 11);

        $pdf->draw('Declared details', 50, 283, 495, 11, true);
        $pdf->drawWrapped($declaration->text('conflictDetails'), 50, 307, 495, 34 * 16, 11, 5);

        $pdf->draw('This continuation page forms part of the completed Code of Conduct declaration.', 50, 790, 495, 8);
    }

    /**
     * A source box as `[x, top, width, height]` on the page being drawn.
     *
     * @param Rect $rect `[page, x1, y1, x2, y2]`, bottom-up
     * @return array{0: float, 1: float, 2: float, 3: float}
     */
    private function box(TemplatePdf $pdf, array $rect): array
    {
        [, $x1, $y1, $x2, $y2] = $rect;

        return [$x1, $pdf->pageHeight() - $y2, $x2 - $x1, $y2 - $y1];
    }

    /**
     * The source's `drawReadableText`: padded, shrunk to fit, vertically centred.
     *
     * @param array{0: float, 1: float, 2: float, 3: float} $box
     */
    private function text(TemplatePdf $pdf, string $value, array $box, float $preferredSize, ?float $minimumSize = null): void
    {
        [$x, $top, $width, $height] = $box;
        $padding = $height >= 18 ? 5 : 2;
        $size = $preferredSize;
        // pdf-lib's baseline sat max(1, (height - size) / 2 + 1) above the box's bottom edge.
        $baselineFromTop = $top + $height - max(1, ($height - $size) / 2 + 1);
        $pdf->draw($value, $x + $padding, $baselineFromTop - $size, max(1, $width - 2 * $padding), $size, false, $minimumSize ?? $preferredSize);
    }

    /**
     * The source's `drawCheckbox`: the box redrawn white and outlined, then a bold X if ticked.
     *
     * @param array{0: float, 1: float, 2: float, 3: float} $box
     */
    private function checkbox(TemplatePdf $pdf, array $box, bool $checked): void
    {
        [$x, $top, $width, $height] = $box;
        $pdf->erase($x, $top, $width, $height);
        $pdf->border($x, $top, $width, $height);
        if (!$checked) {
            return;
        }
        $size = min(9, $height - 2);
        // Helvetica-Bold's X is 0.667 em wide.
        $markX = $x + ($width - 0.667 * $size) / 2;
        $baselineFromTop = $top + $height - (($height - $size) / 2 + 0.8);
        $pdf->mark($markX, $baselineFromTop - $size, $size);
    }
}
