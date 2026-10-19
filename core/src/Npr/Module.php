<?php

declare(strict_types=1);

namespace TriCab\Core\Npr;

use TriCab\Core\Tsd\Module as TsdModule;

/**
 * Marker for the NPR (New Product Request) bounded context.
 *
 * Sales raise a request at /npr, branch managers endorse at /npr/team, and TSD
 * works it at /admin/tsd/npr. People are linked by `identity.Users.UserId`,
 * never by email address. Plan: NPR-TSD-MIGRATION-PLAN, N1.
 *
 * One permission per route. Nobody is assigned the roles yet (plan N5).
 */
final class Module
{
    public const NAME = 'NPR';

    /** Seed `ModuleCode` for every permission below. */
    public const MODULE_CODE = 'npr';

    public const PERMISSION_SELF_READ = 'NPR.SELF.READ';
    public const PERMISSION_SELF_CREATE = 'NPR.SELF.CREATE';
    public const PERMISSION_SELF_SUBMIT = 'NPR.SELF.SUBMIT';
    public const PERMISSION_TEAM_ENDORSE = 'NPR.TEAM.ENDORSE';
    public const PERMISSION_TSD_READ = 'NPR.TSD.READ';
    public const PERMISSION_TSD_WORK = 'NPR.TSD.WORK';
    public const PERMISSION_SCREENING_DECIDE = 'NPR.SCREENING.DECIDE';
    public const PERMISSION_ADMIN = 'NPR.ADMIN';

    /** Every permission the module defines. */
    public const PERMISSIONS = [
        self::PERMISSION_SELF_READ,
        self::PERMISSION_SELF_CREATE,
        self::PERMISSION_SELF_SUBMIT,
        self::PERMISSION_TEAM_ENDORSE,
        self::PERMISSION_TSD_READ,
        self::PERMISSION_TSD_WORK,
        self::PERMISSION_SCREENING_DECIDE,
        self::PERMISSION_ADMIN,
    ];

    public const ROLE_REQUESTER = 'NPR_REQUESTER';
    public const ROLE_MANAGER = 'NPR_MANAGER';
    public const ROLE_TSD_ENGINEER = 'TSD_ENGINEER';
    public const ROLE_TSD_SIGNATORY = 'TSD_SIGNATORY';
    public const ROLE_VIEWER = 'NPR_VIEWER';

    /** Role code => permissions it holds (NPR.* plus TSD.ACCESS). */
    public const ROLE_PERMISSIONS = [
        self::ROLE_REQUESTER => [
            self::PERMISSION_SELF_READ,
            self::PERMISSION_SELF_CREATE,
            self::PERMISSION_SELF_SUBMIT,
        ],
        self::ROLE_MANAGER => [
            self::PERMISSION_SELF_READ,
            self::PERMISSION_TEAM_ENDORSE,
        ],
        self::ROLE_TSD_ENGINEER => [
            self::PERMISSION_TSD_READ,
            self::PERMISSION_TSD_WORK,
            TsdModule::PERMISSION_ACCESS,
        ],
        self::ROLE_TSD_SIGNATORY => [
            self::PERMISSION_TSD_READ,
            self::PERMISSION_SCREENING_DECIDE,
            TsdModule::PERMISSION_ACCESS,
        ],
        self::ROLE_VIEWER => [
            self::PERMISSION_TSD_READ,
            TsdModule::PERMISSION_ACCESS,
        ],
    ];
}
