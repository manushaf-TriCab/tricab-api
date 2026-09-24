<?php

declare(strict_types=1);

namespace TriCab\Api\Onboarding\Document;

use setasign\Fpdi\Fpdi;

/**
 * One of TriCab's paper templates with answers painted onto it (5.4) — the PHP
 * counterpart of the source's pdf-lib helpers, so a mapper can be ported
 * coordinate for coordinate.
 *
 * Coordinates are the source's: points, x from the left, `top` from the top of
 * the page. pdf-lib drew at `y = height - top - size` from the bottom, which is
 * a baseline `top + size` below the top edge — exactly where FPDF's `Text()`
 * puts one.
 *
 * Helvetica, as the source used, which FPDF encodes as Windows-1252; anything
 * outside it is transliterated rather than dropped.
 */
final class TemplatePdf
{
    public const MIN_VALUE_SIZE = 7.2;
    private const INK = [10, 48, 92];

    private readonly Fpdi $pdf;
    private int $pageCount;

    public function __construct(string $templatePath)
    {
        if (!is_file($templatePath)) {
            throw new \RuntimeException('Template not found: ' . basename($templatePath));
        }
        $this->pdf = new Fpdi('P', 'pt');
        $this->pdf->SetAutoPageBreak(false);
        $this->pdf->SetMargins(0, 0, 0);
        $this->pageCount = $this->pdf->setSourceFile($templatePath);
    }

    public function pageCount(): int
    {
        return $this->pageCount;
    }

    /** Adds the template's page `$number` (1-based) and makes it the page drawn on. */
    public function page(int $number): void
    {
        $template = $this->pdf->importPage($number);
        $size = $this->pdf->getTemplateSize($template);
        if (!is_array($size)) {
            throw new \RuntimeException(sprintf('Template page %d could not be read.', $number));
        }
        $this->pdf->AddPage($size['orientation'], [$size['width'], $size['height']]);
        $this->pdf->useTemplate($template);
        $this->pdf->SetTextColor(...self::INK);
    }

    /**
     * One readable line: shrinks from `$preferredSize` to fit `$width`, never
     * below {@see MIN_VALUE_SIZE}, then truncates with an ellipsis.
     */
    public function draw(string $raw, float $x, float $top, float $width, float $preferredSize = 8.2, bool $bold = false): void
    {
        $value = trim((string) preg_replace('/\s+/u', ' ', $raw));
        if ($value === '') {
            return;
        }
        $encoded = self::encode($value);

        $size = $preferredSize;
        $this->pdf->SetFont('Helvetica', $bold ? 'B' : '', $size);
        while ($size > self::MIN_VALUE_SIZE && $this->pdf->GetStringWidth($encoded) > $width) {
            $size -= 0.2;
            $this->pdf->SetFont('Helvetica', $bold ? 'B' : '', $size);
        }
        $size = max(self::MIN_VALUE_SIZE, $size);
        $this->pdf->SetFont('Helvetica', $bold ? 'B' : '', $size);

        $this->pdf->Text($x, $top + $size, $this->fit($encoded, $width));
    }

    /** The source's tick: a 7pt bold X. */
    public function mark(float $x, float $top): void
    {
        $this->pdf->SetFont('Helvetica', 'B', 7);
        $this->pdf->Text($x, $top + 7, 'X');
    }

    public function metadata(string $title, string $subject): void
    {
        $this->pdf->SetTitle($title, true);
        $this->pdf->SetSubject($subject, true);
        $this->pdf->SetCreator('TriCab onboarding', true);
        $this->pdf->SetAuthor('TriCab', true);
    }

    public function bytes(): string
    {
        $bytes = $this->pdf->Output('S');
        if (!is_string($bytes) || $bytes === '') {
            throw new \RuntimeException('The PDF produced no bytes.');
        }

        return $bytes;
    }

    /** `YYYY-MM-DD` as the paper forms write it, `DD/MM/YYYY`; anything else unchanged. */
    public static function formatDate(string $value): string
    {
        return preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) === 1 ? "{$m[3]}/{$m[2]}/{$m[1]}" : $value;
    }

    private function fit(string $encoded, float $width): string
    {
        if ($this->pdf->GetStringWidth($encoded) <= $width) {
            return $encoded;
        }
        $shown = $encoded;
        while (strlen($shown) > 1 && $this->pdf->GetStringWidth($shown . '...') > $width) {
            $shown = substr($shown, 0, -1);
        }

        return rtrim($shown) . '...';
    }

    private static function encode(string $utf8): string
    {
        $converted = @iconv('UTF-8', 'windows-1252//TRANSLIT//IGNORE', $utf8);

        return $converted === false ? (string) preg_replace('/[^\x20-\x7E]/', '?', $utf8) : $converted;
    }
}
