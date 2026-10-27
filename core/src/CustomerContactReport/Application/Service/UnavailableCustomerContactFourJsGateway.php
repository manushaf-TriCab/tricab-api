<?php

declare(strict_types=1);

namespace TriCab\Core\CustomerContactReport\Application\Service;

use TriCab\Core\CustomerContactReport\Contract\CustomerContactFourJsGateway;
use TriCab\Core\CustomerContactReport\Domain\FourJs\FourJsUnavailable;

/** Bound when no 4JS URL is configured. Every lookup fails closed. */
final class UnavailableCustomerContactFourJsGateway implements CustomerContactFourJsGateway
{
    public function fetch(string $type, string $ref): string
    {
        throw new FourJsUnavailable('4JS is not configured.');
    }
}
