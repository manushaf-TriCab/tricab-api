<?php

declare(strict_types=1);

namespace TriCab\Api\DocumentGeneration\Rendering;

use Dompdf\Canvas;
use Dompdf\Dompdf;
use Dompdf\FontMetrics;

/**
 * Quote PDF page chrome drawn after Dompdf layout:
 * - "Page N of M" (replaces hardcoded HTML page label)
 * - "Continues to next page ->" on every non-final page only
 *
 * Coordinates assume A4 portrait with quote @page margins defined below.
 * Company footer is HTML (fixed); there is no canvas footer separator line.
 */
final class QuoteDocumentPagination
{
    /**
     * Bottom page margin reserved for:
     * continuation (non-final) + gap + company footer near page bottom (mm).
     */
    public const BOTTOM_MARGIN_MM = 20.0;

    /** Side page margin (mm) — matches document.css. */
    public const SIDE_MARGIN_MM = 8.0;

    /** Top page margin (mm) — matches document.css. */
    public const TOP_MARGIN_MM = 7.0;

    private const MM_TO_PT = 2.834645669;

    public static function quotePaginationCss(): string
    {
        $bottom = self::BOTTOM_MARGIN_MM;
        $top = self::TOP_MARGIN_MM;
        $side = self::SIDE_MARGIN_MM;

        return <<<CSS
/* Quote multi-page geometry — full @page reset so bottom margin is reserved. */
@page {
    size: A4 portrait;
    margin-top: {$top}mm;
    margin-right: {$side}mm;
    margin-bottom: {$bottom}mm;
    margin-left: {$side}mm;
}

.quote-page-footer {
    position: fixed;
    left: 0;
    right: 0;
    /*
     * Dompdf fixed `bottom` is relative to the content box (above @page
     * margin). BOTTOM_MARGIN is 20mm; use -(20 - 4.5) so the block's bottom
     * edge sits ~4.5mm above the physical page bottom.
     */
    bottom: -15.5mm;
    height: auto;
    box-sizing: border-box;
    padding: 0;
    border: 0 !important;
    border-top: 0 !important;
}

.quote-footer-chrome-spacer {
    display: none;
    height: 0;
    margin: 0;
    padding: 0;
}

.quote-company-footer {
    position: static !important;
    width: 100%;
    font-size: 8.5pt;
    line-height: 1.2;
    border: 0 !important;
}

.quote-company-footer td {
    width: 33.333%;
    vertical-align: bottom;
    border: 0 !important;
}

.quote-company-footer td:nth-child(2) {
    text-align: center;
}

.quote-company-footer td:nth-child(3) {
    text-align: right;
}

.quote-items-continued {
    page-break-before: always;
    margin-top: 0 !important;
}

.quote-final-block {
    page-break-inside: avoid;
    break-inside: avoid;
}

/*
 * Dompdf often drops collapsed table outer borders when segments paginate.
 * Use separate borders plus an explicit close row so every page segment closes.
 */
.items {
    border-collapse: separate !important;
    border-spacing: 0;
    border: 0.25mm solid #222;
    border-bottom: 0;
    margin-bottom: 2mm;
}

.quote-final-block .items {
    margin-bottom: 0;
}

.items thead th {
    border-top: 0;
    border-left: 0;
    border-right: 0.25mm solid #222;
    border-bottom: 0.25mm solid #222;
}

.items thead th:last-child {
    border-right: 0;
}

.items tbody td {
    border-top: 0;
    border-left: 0;
    border-right: 0;
    border-bottom: 0;
}

.items tbody tr.quote-item-table-close td {
    height: 0;
    padding: 0 !important;
    margin: 0;
    font-size: 0;
    line-height: 0;
    border-top: 0.25mm solid #222 !important;
    border-bottom: 0 !important;
}

.quote-item {
    page-break-inside: avoid;
    break-inside: avoid;
}

.quote-item td {
    padding-top: 1.6mm;
    padding-bottom: 1.2mm;
    vertical-align: top;
}

.quote-item .item-description {
    margin: 0 0 0.4mm;
}

.quote-item .item-comment {
    margin-top: 0.4mm;
    font-weight: normal;
}

.quote-closing {
    page-break-inside: avoid;
    break-inside: avoid;
}

.totals {
    page-break-inside: avoid;
    break-inside: avoid;
}

.conductor-comparison {
    page-break-inside: avoid;
    break-inside: avoid;
}
CSS;
    }

    public static function apply(Dompdf $dompdf): void
    {
        $canvas = $dompdf->getCanvas();
        $canvas->page_script(static function (
            int $pageNumber,
            int $pageCount,
            Canvas $canvas,
            FontMetrics $fontMetrics,
        ): void {
            $font = $fontMetrics->getFont('Helvetica') ?? 'Helvetica';
            $bold = $fontMetrics->getFont('Helvetica', 'bold') ?? $font;
            $width = (float) $canvas->get_width();
            $height = (float) $canvas->get_height();
            $right = $width - (self::SIDE_MARGIN_MM * self::MM_TO_PT);

            // Page 1: under QUOTATION title. Later pages: inside the top margin
            // (above the content box) so the label never overlaps the repeated thead.
            $pageLabel = sprintf('Page %d of %d', $pageNumber, $pageCount);
            $pageSize = 9.0;
            $pageWidth = $fontMetrics->getTextWidth($pageLabel, $font, $pageSize);
            $pageY = $pageNumber === 1
                ? (self::TOP_MARGIN_MM * self::MM_TO_PT) + 48.0
                : 8.0;
            $canvas->text($right - $pageWidth, $pageY, $pageLabel, $font, $pageSize, [0.05, 0.05, 0.05]);

            if ($pageNumber < $pageCount) {
                $continuation = 'Continues to next page ->';
                $contSize = 8.0;
                $contWidth = $fontMetrics->getTextWidth($continuation, $bold, $contSize);

                // Match HTML footer: bottom: 4.5mm, ~2 lines at 8.5pt (~7.8mm).
                $footerBottomClearancePt = 4.5 * self::MM_TO_PT;
                $footerBlockHeightPt = 7.8 * self::MM_TO_PT;
                $continuationGapPt = 10.0 * self::MM_TO_PT;
                $footerTopY = $height - $footerBottomClearancePt - $footerBlockHeightPt;
                $contY = $footerTopY - $continuationGapPt - $contSize;

                $canvas->text($right - $contWidth, $contY, $continuation, $bold, $contSize, [0.12, 0.12, 0.12]);
            }
        });
    }
}
