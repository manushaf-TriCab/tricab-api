<?php

declare(strict_types=1);

namespace TriCab\Api\Expense\Persistence;

use Dompdf\Dompdf;
use Dompdf\Options;
use TriCab\Core\Expense\Contract\ExpensePdfRenderer;

final class ExpenseDompdfRenderer implements ExpensePdfRenderer
{
    public function renderHtml(string $html): string
    {
        $options = new Options();
        $options->set('defaultFont', 'Helvetica');
        $options->set('isRemoteEnabled', false);
        $options->set('isPhpEnabled', false);

        $dompdf = new Dompdf($options);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->render();

        return $dompdf->output();
    }
}
