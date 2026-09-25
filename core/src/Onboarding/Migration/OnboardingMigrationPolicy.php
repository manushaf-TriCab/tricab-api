<?php

declare(strict_types=1);

namespace TriCab\Core\Onboarding\Migration;

use TriCab\Core\Onboarding\Migration\Source\SourceSnapshot;

/**
 * What the migration may act on (Phase 7): work order §7 — "production
 * execution remains disabled and requires a separate work order/approval".
 *
 * Until {@see PRODUCTION_EXECUTION_ENABLED} is changed in code — by that
 * separate work order, reviewed like any other change — execute and rollback
 * accept only a snapshot that is marked synthetic **and** whose every address
 * is at a reserved test domain. Either alone is not enough: a flag can be set by
 * hand on a real extract, and real people do not have `@example.test`
 * addresses.
 *
 * Extraction and preview are read-only and are not gated: previewing the real
 * source is how its readiness would be judged.
 */
final class OnboardingMigrationPolicy
{
    public const PRODUCTION_EXECUTION_ENABLED = false;

    /** RFC 2606 / 6761 reserved names: nobody real has an address there. */
    public const SYNTHETIC_DOMAINS = ['example.test', 'example.invalid'];

    /**
     * @throws OnboardingMigrationRefused
     */
    public static function assertExecutable(SourceSnapshot $snapshot): void
    {
        if (self::PRODUCTION_EXECUTION_ENABLED) {
            return;
        }
        if (!$snapshot->isSynthetic()) {
            throw OnboardingMigrationRefused::because('PRODUCTION_DISABLED', 'this snapshot is not synthetic, and production execution is disabled until a separate work order enables it');
        }
        foreach ($snapshot->rows('trainees') as $trainee) {
            $email = strtolower(is_string($trainee['email']) ? $trainee['email'] : '');
            $domain = substr($email, (int) strrpos($email, '@') + 1);
            if (!in_array($domain, self::SYNTHETIC_DOMAINS, true)) {
                throw OnboardingMigrationRefused::because('PRODUCTION_DISABLED', sprintf('the snapshot is marked synthetic but trainees#%d has an address outside %s', is_int($trainee['id']) ? $trainee['id'] : 0, implode(' / ', self::SYNTHETIC_DOMAINS)));
            }
        }
    }
}
