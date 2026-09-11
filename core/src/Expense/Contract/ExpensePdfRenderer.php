<?php

declare(strict_types=1);

namespace TriCab\Core\Expense\Contract;

interface ExpensePdfRenderer
{
    public function renderHtml(string $html): string;
}
