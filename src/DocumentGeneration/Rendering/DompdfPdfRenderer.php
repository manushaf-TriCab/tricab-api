<?php

declare(strict_types=1);

namespace TriCab\Api\DocumentGeneration\Rendering;

use Dompdf\Dompdf;
use Dompdf\Options;
use RuntimeException;
use TriCab\Core\DocumentGeneration\Application\DTO\LegacyDocumentData;
use TriCab\Core\DocumentGeneration\Contract\PdfRenderer;
use TriCab\Core\DocumentGeneration\Legacy\LegacyStream;

final readonly class DompdfPdfRenderer implements PdfRenderer
{
    public function __construct(
        private string $templateDirectory,
        private string $assetDirectory,
    ) {
    }

    public function render(LegacyStream $stream): string
    {
        $template = match ($stream->templateName()) {
            'tcord.ocr' => 'order.php',
            'tcpick.ocr' => 'pick.php',
            'tcqu.ocr' => 'quote.php',
            default => throw new RuntimeException(sprintf(
                'Template %s is not allowlisted by the document renderer.',
                $stream->templateName(),
            )),
        };

        $html = $this->renderTemplate($template, new LegacyDocumentData($stream));
        $options = new Options();
        $options->set('defaultFont', 'Helvetica');
        $options->set('isRemoteEnabled', false);
        $options->set('isPhpEnabled', false);

        $dompdf = new Dompdf($options);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->render();

        if ($template === 'quote.php') {
            QuoteDocumentPagination::apply($dompdf);
        }

        return $dompdf->output();
    }

    private function renderTemplate(string $template, LegacyDocumentData $doc): string
    {
        $templatePath = $this->templateDirectory . DIRECTORY_SEPARATOR . $template;
        $cssPath = $this->templateDirectory . DIRECTORY_SEPARATOR . 'document.css';
        if (!is_file($templatePath) || !is_file($cssPath)) {
            throw new RuntimeException('The document template files are missing.');
        }

        $assets = [
            'logo' => $this->dataUri('tricab-logo.jpg'),
            'australianMade' => $this->dataUri('australian-made.jpg'),
            'stranding' => $this->dataUri('conductor-stranding.jpg'),
        ];
        $css = file_get_contents($cssPath);
        if ($css === false) {
            throw new RuntimeException('Unable to read document CSS.');
        }
        if ($template === 'quote.php') {
            // Replace the shared @page block so Dompdf cannot keep the shorter bottom margin.
            $css = (string) preg_replace(
                '/@page\s*\{[^}]*\}/s',
                '',
                $css,
                1,
            );
            $css .= "\n" . QuoteDocumentPagination::quotePaginationCss();
        }

        ob_start();
        require $templatePath;
        $body = ob_get_clean();
        if ($body === false) {
            throw new RuntimeException(sprintf('Unable to render template: %s', $template));
        }

        return sprintf(
            '<!doctype html><html><head><meta charset="UTF-8"><style>%s</style></head><body>%s</body></html>',
            $css,
            $body,
        );
    }

    private function dataUri(string $filename): string
    {
        $path = $this->assetDirectory . DIRECTORY_SEPARATOR . $filename;
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new RuntimeException(sprintf('Unable to read asset: %s', $path));
        }

        return 'data:image/jpeg;base64,' . base64_encode($contents);
    }
}
