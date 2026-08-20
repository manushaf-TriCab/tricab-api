<?php

declare(strict_types=1);

namespace TriCab\Core\LegacyMigration\Support;

final class SourceCatalog
{
    public const WORDPRESS = 'wordpress';
    public const BUSINESS_DB = 'business-db';
    public const MOODLE = 'moodle';
    public const FUTURE_ERP = 'future-erp';
    public const FUTURE_CRM = 'future-crm';
    public const FIXTURE = 'fixture';

    /**
     * @return list<array{code: string, label: string, kind: string}>
     */
    public static function sources(): array
    {
        return [
            ['code' => self::WORDPRESS, 'label' => 'WordPress', 'kind' => 'legacy-cms'],
            ['code' => self::BUSINESS_DB, 'label' => 'Business Database', 'kind' => 'legacy-sql'],
            ['code' => self::MOODLE, 'label' => 'Moodle', 'kind' => 'legacy-lms'],
            ['code' => self::FUTURE_ERP, 'label' => 'Future ERP', 'kind' => 'future'],
            ['code' => self::FUTURE_CRM, 'label' => 'Future CRM', 'kind' => 'future'],
            ['code' => self::FIXTURE, 'label' => 'Framework fixtures (non-production)', 'kind' => 'harness'],
        ];
    }
}
