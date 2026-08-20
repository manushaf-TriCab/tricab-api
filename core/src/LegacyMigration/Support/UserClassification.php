<?php

declare(strict_types=1);

namespace TriCab\Core\LegacyMigration\Support;

final class UserClassification
{
    public const STAFF_MICROSOFT = 'STAFF_MICROSOFT';
    public const STAFF_OTHER = 'STAFF_OTHER';
    public const CUSTOMER = 'CUSTOMER';
    public const CUSTOMER_CONTACT = 'CUSTOMER_CONTACT';
    public const ALI_FLEX_USER = 'ALI_FLEX_USER';
    public const SERVICE_PROVIDER = 'SERVICE_PROVIDER';
    public const INACTIVE = 'INACTIVE';
    public const DUPLICATE = 'DUPLICATE';
    public const AMBIGUOUS = 'AMBIGUOUS';
    public const LEGACY_ONLY = 'LEGACY_ONLY';
    public const REQUIRES_REVIEW = 'REQUIRES_REVIEW';

    /** @var list<string> */
    public const ALLOWED_META_KEYS = [
        'wp_capabilities',
        'wp_user_level',
        'first_name',
        'last_name',
        'nickname',
        'description',
        'company',
        'referrer',
        'location',
        'last_login',
        'phone',
        'mobile',
        'authLDAP',
    ];

    /** @var list<string> */
    public const KNOWN_ROLE_SLUGS = [
        'administrator',
        'staff',
        'sales',
        'customer',
        'electrician',
        'distributor',
        'subscriber',
        'tricab',
    ];
}
