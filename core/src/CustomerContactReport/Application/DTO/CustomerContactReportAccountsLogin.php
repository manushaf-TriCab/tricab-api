<?php

declare(strict_types=1);

namespace TriCab\Core\CustomerContactReport\Application\DTO;

final readonly class CustomerContactReportAccountsLogin
{
    public function __construct(
        public string $login,
    ) {
    }
}
