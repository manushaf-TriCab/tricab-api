<?php

declare(strict_types=1);

namespace TriCab\Core\TelDirectory\Application\Service;

/**
 * Legacy Tel Directory inclusion rules (WordPress template parity).
 */
final class TelDirectoryFilter
{
    /** @var list<string> */
    public const ALLOWED_EMAIL_DOMAINS = [
        '@tricab.com',
        '@sparkywarehouse.com.au',
        '@tricabtrading.com',
    ];

    /** @var list<string> Exact mail addresses hard-excluded by legacy UI. */
    public const HARD_EXCLUDED_EMAILS = [
        'jeffe@tricab.com',
        'shalini@tricab.com',
        'agfern@tricab.com',
        'ferndale@tricab.com',
        'it.support@sparkywarehouse.com.au',
        'stepfwdit@tricab.com',
        'diannen@tricab.com',
        'diedesk@tricab.com',
        'pfe@tricab.com',
    ];

    public const SPECIAL_DUAL_MOBILE_EMAIL = 'ag@tricab.com';

    /**
     * @param array<string, mixed> $graphUser
     */
    public function accepts(array $graphUser): bool
    {
        if (($graphUser['accountEnabled'] ?? false) !== true) {
            return false;
        }

        $mail = $this->normaliseEmail((string) ($graphUser['mail'] ?? ''));
        if ($mail === '') {
            return false;
        }

        if (!$this->hasAllowedDomain($mail)) {
            return false;
        }

        if (in_array($mail, self::HARD_EXCLUDED_EMAILS, true)) {
            return false;
        }

        $jobTitle = trim((string) ($graphUser['jobTitle'] ?? ''));
        if ($jobTitle === '') {
            return false;
        }

        return true;
    }

    public function isSpecialDualMobileEmail(string $email): bool
    {
        return $this->normaliseEmail($email) === self::SPECIAL_DUAL_MOBILE_EMAIL;
    }

    public function normaliseEmail(string $email): string
    {
        return strtolower(trim($email));
    }

    private function hasAllowedDomain(string $normalisedEmail): bool
    {
        foreach (self::ALLOWED_EMAIL_DOMAINS as $domain) {
            if (str_contains($normalisedEmail, $domain)) {
                return true;
            }
        }

        return false;
    }
}
