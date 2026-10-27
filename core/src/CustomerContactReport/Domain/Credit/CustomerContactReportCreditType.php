<?php

declare(strict_types=1);

namespace TriCab\Core\CustomerContactReport\Domain\Credit;

use TriCab\Core\CustomerContactReport\Domain\Exception\InvalidCustomerContactReportValue;

final class CustomerContactReportCreditType
{
    public const RETURNED_GOODS = 'Returned goods';

    public const OVERCHARGED = 'Overcharged';

    /** @var list<string> */
    public const ALL = [self::RETURNED_GOODS, self::OVERCHARGED];

    public static function fromString(string $raw): string
    {
        $value = trim($raw);
        foreach (self::ALL as $type) {
            if (strcasecmp($type, $value) === 0) {
                return $type;
            }
        }

        throw InvalidCustomerContactReportValue::for('creditType', 'must be Returned goods or Overcharged.');
    }
}
