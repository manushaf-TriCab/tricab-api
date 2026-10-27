<?php

declare(strict_types=1);

namespace TriCab\Core\CustomerContactReport\Contract;

use TriCab\Core\CustomerContactReport\Domain\FourJs\FourJsUnavailable;

/**
 * Raw 4JS access. Server side only: the browser and the phone never talk to 4JS.
 */
interface CustomerContactFourJsGateway
{
    /**
     * @param 'ORDOCR'|'INVOCR' $type
     * @return string the raw OCR body, which may be an "error ..." line
     * @throws FourJsUnavailable on timeout, a non-200 answer, or no configured URL
     */
    public function fetch(string $type, string $ref): string;
}
