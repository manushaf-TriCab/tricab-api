<?php

declare(strict_types=1);

namespace TriCab\Core\Identity\Domain\Service;

use TriCab\Core\Identity\Domain\ValueObject\EmailAddress;

/**
 * Masks email addresses for API-facing responses.
 */
final class EmailMasker
{
    public static function mask(EmailAddress $emailAddress): string
    {
        $value = $emailAddress->toString();
        $at = strpos($value, '@');
        if ($at === false) {
            return '***';
        }

        $local = substr($value, 0, $at);
        $domain = substr($value, $at + 1);

        if ($local === '') {
            return '***@' . $domain;
        }

        if (strlen($local) === 1) {
            return $local . '***@' . $domain;
        }

        return $local[0] . '***' . $local[strlen($local) - 1] . '@' . $domain;
    }
}
