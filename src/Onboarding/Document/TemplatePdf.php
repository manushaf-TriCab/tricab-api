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
     * below `$minSize` ({@see MIN_VALUE_SIZE} for forms; the assessment sheets'
     * small boxes use 4.6, as the source did), then truncates with an ellipsis.
     */
    public function draw(string $raw, float $x, float $top, float $width, float $preferredSize = 8.2, bool $bold = false, float $minSize = self::MIN_VALUE_SIZE): void
    {
        $value = trim((string) preg_replace('/\s+/u', ' ', $raw));
        if ($value === '') {
            return;
        }
        $encoded = self::encode($value);

        $size = $preferredSize;
        $this->pdf->SetFont('Helvetica', $bold ? 'B' : '', $size);
        while ($size > $minSize && $this->pdf->GetStringWidth($encoded) > $width) {
            $size -= 0.2;
            $this->pdf->SetFont('Helvetica', $bold ? 'B' : '', $size);
        }
        $size = max($minSize, $size);
        $this->pdf->SetFont('Helvetica', $bold ? 'B' : '', $size);

        $this->pdf->Text($x, $top + $size, $this->fit($encoded, $width));
    }

    /** The source's tick: a 7pt bold X. */
    public function mark(float $x, float $top): void
    {
        $this->pdf->SetFont('Helvetica', 'B', 7);
        $this->pdf->Text($x, $top + 7, 'X');
    }

    /**
     * A drawn signature, scaled to fit inside the box and centred in it, as the
     * source's `drawContainedImage` did. The PNG has already been checked
     * ({@see \TriCab\Core\Onboarding\Domain\ValueObject\SignatureImage}).
     */
    public function signature(string $png, float $x, float $top, float $width, float $height, float $padding = 2): void
    {
        $size = @getimagesizefromstring($png);
        if ($size === false || $size[0] < 1 || $size[1] < 1) {
            return;
        }
        $boxW = max(1, $width - 2 * $padding);
        $boxH = max(1, $height - 2 * $padding);
        $scale = min($boxW / $size[0], $boxH / $size[1]);
        $w = $size[0] * $scale;
        $h = $size[1] * $scale;

        // FPDF reads images from a path; the bytes live only as long as this call.
        $file = tempnam(sys_get_temp_dir(), 'sig');
        if ($file === false) {
            throw new \RuntimeException('No temporary file for a signature.');
        }
        try {
            file_put_contents($file, $png);
            $this->pdf->Image($file, $x + $padding + ($boxW - $w) / 2, $top + $padding + ($boxH - $h) / 2, $w, $h, 'PNG');
        } finally {
            @unlink($file);
        }
    }

    /** Paints a white box — the source's way of clearing a printed placeholder before writing over it. */
    public function erase(float $x, float $top, float $width, float $height): void
    {
        $this->pdf->SetFillColor(255, 255, 255);
        $this->pdf->Rect($x, $top, $width, $height, 'F');
    }

    /** Word-wrapped text in a box, as many lines as fit, the last one ellipsed if more remain. */
    public function drawWrapped(string $raw, float $x, float $top, float $width, float $height, float $size): void
    {
        $words = array_values(array_filter(explode(' ', trim((string) preg_replace('/\s+/u', ' ', $raw))), static fn (string $w): bool => $w !== ''));
        if ($words === []) {
            return;
        }
        $this->pdf->SetFont('Helvetica', '', $size);
        $lines = [];
        $current = '';
        foreach ($words as $word) {
            $candidate = $current === '' ? $word : $current . ' ' . $word;
            if ($this->pdf->GetStringWidth(self::encode($candidate)) <= $width) {
                $current = $candidate;
                continue;
            }
            if ($current !== '') {
                $lines[] = $current;
            }
            $current = $word;
        }
        $lines[] = $current;
        $max = max(1, (int) floor($height / ($size + 2)));
        foreach (array_slice($lines, 0, $max) as $i => $line) {
            $last = $i === $max - 1 && count($lines) > $max;
            $this->draw($last ? $line . '...' : $line, $x, $top + $i * ($size + 2), $width, $size, false, 4.6);
        }
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
