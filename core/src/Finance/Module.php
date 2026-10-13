<?php

declare(strict_types=1);

namespace TriCab\Core\Finance;

/**
 * Marker for the Finance bounded context: the five finance apps (Tri-Task,
 * Tri-Credit Notes, Tri-Collection, Tri-AR, Tri-AP) as modules of the platform.
 *
 * Finance owns no part of identity. Records link to a person by
 * `identity.Users.UserId`, never by email address or by the finance portal's
 * user id (plan decision D7). The portal's own user, role and grant tables are
 * retired, not ported.
 *
 * ## Switches
 *
 * `FINANCE_MODULE_ENABLED` turns the module on; each app then has its own
 * switch (`Settings::financeAppEnabled()`). An app switch does nothing while the
 * module switch is off, and every switch defaults to off, so work can merge
 * ahead of the screens that use it.
 *
 * ## Permissions
 *
 * Phase 1 defines the shared permissions and one access permission per app, and
 * nothing app-specific: each app's own permissions and roles arrive in its own
 * phase (decision D4). The map from the finance portal's vocabulary to the
 * platform's, for all five apps, is `docs/finance/permissions.md`.
 *
 * **`FINANCE.ACCESS` grants no record.** It reaches the launcher and the
 * caller's own access summary. Neither does an app's `ACCESS` permission: it
 * reaches the app, and every record read needs the app's own permission.
 *
 * The platform `ADMINISTRATOR` role holds `FINANCE.ADMIN` and nothing else in
 * finance, as RFQ and Onboarding do (decision D3): an administrator can grant
 * finance access without being inside the records. `EMPLOYEE` and `GUEST` hold
 * nothing. Guest sessions never get a finance permission.
 */
final class Module
{
    public const NAME = 'Finance';

    /** Seed `ModuleCode` for every permission this module defines. */
    public const MODULE_CODE = 'finance';

    /** Reach the launcher and read one's own finance access. No record. */
    public const PERMISSION_ACCESS = 'FINANCE.ACCESS';

    /** Reach Tri-Task. No record. */
    public const PERMISSION_TASK_ACCESS = 'FINANCE.TASK.ACCESS';

    /** Reach Tri-Credit Notes. No record. */
    public const PERMISSION_CREDIT_ACCESS = 'FINANCE.CREDIT.ACCESS';

    /** Reach Tri-Collection. No record. */
    public const PERMISSION_COLLECT_ACCESS = 'FINANCE.COLLECT.ACCESS';

    /** Reach Tri-AR. No record. */
    public const PERMISSION_AR_ACCESS = 'FINANCE.AR.ACCESS';

    /** Reach Tri-AP. No record. */
    public const PERMISSION_AP_ACCESS = 'FINANCE.AP.ACCESS';

    /**
     * Finance administration: the mailbox registry, and later the grants and the
     * reference lists. Not a superset of anything, and reads no record.
     */
    public const PERMISSION_ADMIN = 'FINANCE.ADMIN';

    public const APP_TASK = 'TASK';
    public const APP_CREDIT = 'CREDIT';
    public const APP_COLLECT = 'COLLECT';
    public const APP_AR = 'AR';
    public const APP_AP = 'AP';

    /**
     * Each app's key, its business name, its environment switch and its address
     * under `/finance`. The key is also the segment in the permission code
     * (`FINANCE.<KEY>.ACCESS`).
     *
     * @var array<string, array{name: string, switch: string, path: string}>
     */
    public const APPS = [
        self::APP_TASK => ['name' => 'Tri-Task', 'switch' => 'FINANCE_TASK_ENABLED', 'path' => 'task'],
        self::APP_CREDIT => ['name' => 'Tri-Credit Notes', 'switch' => 'FINANCE_CREDIT_ENABLED', 'path' => 'credit'],
        self::APP_COLLECT => ['name' => 'Tri-Collection', 'switch' => 'FINANCE_COLLECT_ENABLED', 'path' => 'collect'],
        self::APP_AR => ['name' => 'Tri-AR', 'switch' => 'FINANCE_AR_ENABLED', 'path' => 'ar'],
        self::APP_AP => ['name' => 'Tri-AP', 'switch' => 'FINANCE_AP_ENABLED', 'path' => 'ap'],
    ];

    /** Every permission a person's role may carry. Seeded by `seed-finance-permissions.sql`. */
    public const PERSON_PERMISSIONS = [
        self::PERMISSION_ACCESS,
        self::PERMISSION_TASK_ACCESS,
        self::PERMISSION_CREDIT_ACCESS,
        self::PERMISSION_COLLECT_ACCESS,
        self::PERMISSION_AR_ACCESS,
        self::PERMISSION_AP_ACCESS,
        self::PERMISSION_ADMIN,
    ];

    public const ROLE_ADMIN = 'FINANCE_ADMIN';

    /**
     * Platform roles `seed-finance-roles.sql` creates, assigned to nobody. App
     * roles (`FINANCE_<APP>_<ROLE>`, decision D2) come with each app.
     *
     * @var array<string, list<string>>
     */
    public const ROLE_PERMISSIONS = [
        self::ROLE_ADMIN => self::PERSON_PERMISSIONS,
    ];

    /**
     * What the platform `ADMINISTRATOR` role holds: finance administration and
     * nothing that opens an app (decision D3).
     */
    public const ADMINISTRATOR_PERMISSIONS = [self::PERMISSION_ADMIN];

    /** What the platform `EMPLOYEE` and `GUEST` roles hold: nothing. */
    public const EMPLOYEE_PERMISSIONS = [];

    // ------------------------------------------------------------------
    // Tri-Task (Phase 2, decision D13). The portal's `task.*` strings, with
    // `FINANCE.` in front and upper-cased (decision D1). `task.users.manage` and
    // `task.roles.assign_admin` are not carried: roles belong to the platform's
    // identity (permissions.md section 3, Phase 2 question Q6).
    // ------------------------------------------------------------------

    public const PERMISSION_TASK_TASKS_READ = 'FINANCE.TASK.TASKS.READ';
    public const PERMISSION_TASK_TASKS_CREATE = 'FINANCE.TASK.TASKS.CREATE';
    public const PERMISSION_TASK_TASKS_UPDATE_OWN = 'FINANCE.TASK.TASKS.UPDATE_OWN';
    public const PERMISSION_TASK_TASKS_DELETE_OWN = 'FINANCE.TASK.TASKS.DELETE_OWN';
    public const PERMISSION_TASK_TASKS_READ_ALL = 'FINANCE.TASK.TASKS.READ_ALL';
    public const PERMISSION_TASK_TASKS_UPDATE = 'FINANCE.TASK.TASKS.UPDATE';
    public const PERMISSION_TASK_TASKS_DELETE = 'FINANCE.TASK.TASKS.DELETE';
    public const PERMISSION_TASK_SETUP_MANAGE = 'FINANCE.TASK.SETUP.MANAGE';
    public const PERMISSION_TASK_MAIL_SEND = 'FINANCE.TASK.MAIL.SEND';
    public const PERMISSION_TASK_ANALYTICS_READ = 'FINANCE.TASK.ANALYTICS.READ';
    public const PERMISSION_TASK_SETTINGS_MANAGE = 'FINANCE.TASK.SETTINGS.MANAGE';

    /**
     * The eleven Tri-Task permissions a role may carry (besides
     * `FINANCE.TASK.ACCESS`, which Phase 1 seeded). Seeded by
     * `seed-finance-task-permissions.sql`.
     */
    public const TASK_PERMISSIONS = [
        self::PERMISSION_TASK_TASKS_READ,
        self::PERMISSION_TASK_TASKS_CREATE,
        self::PERMISSION_TASK_TASKS_UPDATE_OWN,
        self::PERMISSION_TASK_TASKS_DELETE_OWN,
        self::PERMISSION_TASK_TASKS_READ_ALL,
        self::PERMISSION_TASK_TASKS_UPDATE,
        self::PERMISSION_TASK_TASKS_DELETE,
        self::PERMISSION_TASK_SETUP_MANAGE,
        self::PERMISSION_TASK_MAIL_SEND,
        self::PERMISSION_TASK_ANALYTICS_READ,
        self::PERMISSION_TASK_SETTINGS_MANAGE,
    ];

    public const ROLE_TASK_USER = 'FINANCE_TASK_USER';
    public const ROLE_TASK_MANAGER = 'FINANCE_TASK_MANAGER';
    public const ROLE_TASK_ADMIN = 'FINANCE_TASK_ADMIN';

    /**
     * Tri-Task's roles, each a superset of the one before, and each also holding
     * `FINANCE.ACCESS` and `FINANCE.TASK.ACCESS` so a person given one role can
     * open the launcher and the app (permissions.md section 2). Seeded by
     * `seed-finance-task-roles.sql`, assigned to nobody.
     *
     * A Team Member does not hold `SETUP.MANAGE` (Manusha, 12 Oct 2026): projects, templates, statement areas and
     * entities are shared by everyone's tasks, so only Managers and Administrators change them.
     *
     * @var array<string, list<string>>
     */
    public const TASK_ROLE_PERMISSIONS = [
        self::ROLE_TASK_USER => [
            self::PERMISSION_ACCESS,
            self::PERMISSION_TASK_ACCESS,
            self::PERMISSION_TASK_TASKS_READ,
            self::PERMISSION_TASK_TASKS_CREATE,
            self::PERMISSION_TASK_TASKS_UPDATE_OWN,
            self::PERMISSION_TASK_TASKS_DELETE_OWN,
            self::PERMISSION_TASK_MAIL_SEND,
        ],
        self::ROLE_TASK_MANAGER => [
            self::PERMISSION_ACCESS,
            self::PERMISSION_TASK_ACCESS,
            self::PERMISSION_TASK_TASKS_READ,
            self::PERMISSION_TASK_TASKS_CREATE,
            self::PERMISSION_TASK_TASKS_UPDATE_OWN,
            self::PERMISSION_TASK_TASKS_DELETE_OWN,
            self::PERMISSION_TASK_SETUP_MANAGE,
            self::PERMISSION_TASK_MAIL_SEND,
            self::PERMISSION_TASK_TASKS_READ_ALL,
            self::PERMISSION_TASK_TASKS_UPDATE,
            self::PERMISSION_TASK_TASKS_DELETE,
            // Managers see the team's progress too (item 10 of TRI-TASK-UX-PLAN.md, 13 Oct 2026).
            self::PERMISSION_TASK_ANALYTICS_READ,
        ],
        self::ROLE_TASK_ADMIN => [
            self::PERMISSION_ACCESS,
            self::PERMISSION_TASK_ACCESS,
            self::PERMISSION_TASK_TASKS_READ,
            self::PERMISSION_TASK_TASKS_CREATE,
            self::PERMISSION_TASK_TASKS_UPDATE_OWN,
            self::PERMISSION_TASK_TASKS_DELETE_OWN,
            self::PERMISSION_TASK_SETUP_MANAGE,
            self::PERMISSION_TASK_MAIL_SEND,
            self::PERMISSION_TASK_TASKS_READ_ALL,
            self::PERMISSION_TASK_TASKS_UPDATE,
            self::PERMISSION_TASK_TASKS_DELETE,
            self::PERMISSION_TASK_ANALYTICS_READ,
            self::PERMISSION_TASK_SETTINGS_MANAGE,
        ],
    ];

    /**
     * The portal's permission strings, which the ported pages still read
     * (decision D19): `GET /api/v1/finance/task/me` answers these, not the
     * platform codes. `FINANCE.TASK.ACCESS` has no portal string.
     *
     * @var array<string, string>
     */
    public const TASK_PORTAL_PERMISSIONS = [
        self::PERMISSION_TASK_TASKS_READ => 'task.tasks.read',
        self::PERMISSION_TASK_TASKS_CREATE => 'task.tasks.create',
        self::PERMISSION_TASK_TASKS_UPDATE_OWN => 'task.tasks.update_own',
        self::PERMISSION_TASK_TASKS_DELETE_OWN => 'task.tasks.delete_own',
        self::PERMISSION_TASK_TASKS_READ_ALL => 'task.tasks.read_all',
        self::PERMISSION_TASK_TASKS_UPDATE => 'task.tasks.update',
        self::PERMISSION_TASK_TASKS_DELETE => 'task.tasks.delete',
        self::PERMISSION_TASK_SETUP_MANAGE => 'task.setup.manage',
        self::PERMISSION_TASK_MAIL_SEND => 'task.mail.send',
        self::PERMISSION_TASK_ANALYTICS_READ => 'task.analytics.read',
        self::PERMISSION_TASK_SETTINGS_MANAGE => 'task.settings.manage',
    ];

    // ------------------------------------------------------------------
    // Tri-AR (Phase 5, decision D39). The portal's three `ar.*` strings, and
    // one that is new: reading a stored attachment, a customer's payment
    // document, is its own permission (Phase 5 question Q5) so it can be
    // withdrawn from a role without a release. `FINANCE.AR.ADMIN` is the app's
    // administrator, not `FINANCE.ADMIN`, which is module-wide.
    // ------------------------------------------------------------------

    public const PERMISSION_AR_RECORDS_READ = 'FINANCE.AR.RECORDS.READ';
    public const PERMISSION_AR_RECORDS_WRITE = 'FINANCE.AR.RECORDS.WRITE';
    public const PERMISSION_AR_ATTACHMENTS_READ = 'FINANCE.AR.ATTACHMENTS.READ';
    public const PERMISSION_AR_ADMIN = 'FINANCE.AR.ADMIN';

    /**
     * The four Tri-AR permissions a role may carry (besides `FINANCE.AR.ACCESS`,
     * which Phase 1 seeded). Seeded by `seed-finance-ar-permissions.sql`.
     */
    public const AR_PERMISSIONS = [
        self::PERMISSION_AR_RECORDS_READ,
        self::PERMISSION_AR_RECORDS_WRITE,
        self::PERMISSION_AR_ATTACHMENTS_READ,
        self::PERMISSION_AR_ADMIN,
    ];

    public const ROLE_AR_REVIEWER = 'FINANCE_AR_REVIEWER';
    public const ROLE_AR_OPERATOR = 'FINANCE_AR_OPERATOR';
    public const ROLE_AR_ADMIN = 'FINANCE_AR_ADMIN';

    /**
     * Tri-AR's roles, each a superset of the one before, and each also holding
     * `FINANCE.ACCESS` and `FINANCE.AR.ACCESS` (permissions.md section 2). The
     * portal let `ar.admin` stand in for any permission; here the administrator
     * simply holds them all (Phase 5 question Q4), so no rule in code is needed.
     * Seeded by `seed-finance-ar-roles.sql`, assigned to nobody.
     *
     * @var array<string, list<string>>
     */
    public const AR_ROLE_PERMISSIONS = [
        self::ROLE_AR_REVIEWER => [
            self::PERMISSION_ACCESS,
            self::PERMISSION_AR_ACCESS,
            self::PERMISSION_AR_RECORDS_READ,
            self::PERMISSION_AR_ATTACHMENTS_READ,
        ],
        self::ROLE_AR_OPERATOR => [
            self::PERMISSION_ACCESS,
            self::PERMISSION_AR_ACCESS,
            self::PERMISSION_AR_RECORDS_READ,
            self::PERMISSION_AR_ATTACHMENTS_READ,
            self::PERMISSION_AR_RECORDS_WRITE,
        ],
        self::ROLE_AR_ADMIN => [
            self::PERMISSION_ACCESS,
            self::PERMISSION_AR_ACCESS,
            self::PERMISSION_AR_RECORDS_READ,
            self::PERMISSION_AR_ATTACHMENTS_READ,
            self::PERMISSION_AR_RECORDS_WRITE,
            self::PERMISSION_AR_ADMIN,
        ],
    ];

    /**
     * The strings the ported pages read (decision D50): `GET
     * /api/v1/finance/ar/me` answers these, not the platform codes. Three are
     * the portal's; `ar.attachments.read` follows the same rule (D1) for the
     * new permission. `FINANCE.AR.ACCESS` has no portal string.
     *
     * @var array<string, string>
     */
    public const AR_PORTAL_PERMISSIONS = [
        self::PERMISSION_AR_RECORDS_READ => 'ar.records.read',
        self::PERMISSION_AR_RECORDS_WRITE => 'ar.records.write',
        self::PERMISSION_AR_ATTACHMENTS_READ => 'ar.attachments.read',
        self::PERMISSION_AR_ADMIN => 'ar.admin',
    ];

    // Tri-AP (Phase 6, decision D69). The portal's eight `invoice.*` strings a
    // role carried (`invoice.users.manage` is not carried: roles are the
    // platform's). The portal checked three of them; here every route checks
    // one (question Q1).
    // ------------------------------------------------------------------
    public const PERMISSION_AP_INVOICES_READ = 'FINANCE.AP.INVOICES.READ';
    public const PERMISSION_AP_INVOICES_WRITE = 'FINANCE.AP.INVOICES.WRITE';
    public const PERMISSION_AP_INVOICES_DELETE = 'FINANCE.AP.INVOICES.DELETE';
    public const PERMISSION_AP_MAIL_READ = 'FINANCE.AP.MAIL.READ';
    public const PERMISSION_AP_MAIL_SEND = 'FINANCE.AP.MAIL.SEND';
    public const PERMISSION_AP_APPROVALS_PREPARE = 'FINANCE.AP.APPROVALS.PREPARE';
    public const PERMISSION_AP_APPROVALS_DECIDE = 'FINANCE.AP.APPROVALS.DECIDE';
    public const PERMISSION_AP_SETTINGS_MANAGE = 'FINANCE.AP.SETTINGS.MANAGE';

    /**
     * The eight Tri-AP permissions a role may carry (besides `FINANCE.AP.ACCESS`,
     * which Phase 1 seeded). Seeded by `seed-finance-ap-permissions.sql`.
     */
    public const AP_PERMISSIONS = [
        self::PERMISSION_AP_INVOICES_READ,
        self::PERMISSION_AP_INVOICES_WRITE,
        self::PERMISSION_AP_INVOICES_DELETE,
        self::PERMISSION_AP_MAIL_READ,
        self::PERMISSION_AP_MAIL_SEND,
        self::PERMISSION_AP_APPROVALS_PREPARE,
        self::PERMISSION_AP_APPROVALS_DECIDE,
        self::PERMISSION_AP_SETTINGS_MANAGE,
    ];

    public const ROLE_AP_OPERATOR = 'FINANCE_AP_OPERATOR';
    public const ROLE_AP_PREPARER = 'FINANCE_AP_PREPARER';
    public const ROLE_AP_APPROVER = 'FINANCE_AP_APPROVER';
    public const ROLE_AP_ADMIN = 'FINANCE_AP_ADMIN';

    /**
     * Tri-AP's roles, as the portal had them, each also holding `FINANCE.ACCESS`
     * and `FINANCE.AP.ACCESS` (permissions.md section 2). They are not a ladder:
     * only the administrator holds everything, and a person who both works the
     * register and prepares approvals is given two roles. Seeded by
     * `seed-finance-ap-roles.sql`, assigned to nobody.
     *
     * @var array<string, list<string>>
     */
    public const AP_ROLE_PERMISSIONS = [
        self::ROLE_AP_OPERATOR => [
            self::PERMISSION_ACCESS,
            self::PERMISSION_AP_ACCESS,
            self::PERMISSION_AP_INVOICES_READ,
            self::PERMISSION_AP_INVOICES_WRITE,
            self::PERMISSION_AP_MAIL_READ,
            self::PERMISSION_AP_MAIL_SEND,
        ],
        self::ROLE_AP_PREPARER => [
            self::PERMISSION_ACCESS,
            self::PERMISSION_AP_ACCESS,
            self::PERMISSION_AP_INVOICES_READ,
            self::PERMISSION_AP_APPROVALS_PREPARE,
        ],
        self::ROLE_AP_APPROVER => [
            self::PERMISSION_ACCESS,
            self::PERMISSION_AP_ACCESS,
            self::PERMISSION_AP_INVOICES_READ,
            self::PERMISSION_AP_APPROVALS_DECIDE,
        ],
        self::ROLE_AP_ADMIN => [
            self::PERMISSION_ACCESS,
            self::PERMISSION_AP_ACCESS,
            self::PERMISSION_AP_INVOICES_READ,
            self::PERMISSION_AP_INVOICES_WRITE,
            self::PERMISSION_AP_INVOICES_DELETE,
            self::PERMISSION_AP_MAIL_READ,
            self::PERMISSION_AP_MAIL_SEND,
            self::PERMISSION_AP_APPROVALS_PREPARE,
            self::PERMISSION_AP_APPROVALS_DECIDE,
            self::PERMISSION_AP_SETTINGS_MANAGE,
        ],
    ];

    /**
     * The strings the ported pages read (decision D78): `GET
     * /api/v1/finance/ap/me` answers these, not the platform codes. The portal
     * calls the app `invoice`, so the prefix is not the app key.
     *
     * @var array<string, string>
     */
    public const AP_PORTAL_PERMISSIONS = [
        self::PERMISSION_AP_INVOICES_READ => 'invoice.invoices.read',
        self::PERMISSION_AP_INVOICES_WRITE => 'invoice.invoices.write',
        self::PERMISSION_AP_INVOICES_DELETE => 'invoice.invoices.delete',
        self::PERMISSION_AP_MAIL_READ => 'invoice.mail.read',
        self::PERMISSION_AP_MAIL_SEND => 'invoice.mail.send',
        self::PERMISSION_AP_APPROVALS_PREPARE => 'invoice.approvals.prepare',
        self::PERMISSION_AP_APPROVALS_DECIDE => 'invoice.approvals.decide',
        self::PERMISSION_AP_SETTINGS_MANAGE => 'invoice.settings.manage',
    ];

    /** The permission that reaches one app. No record. */
    public static function appAccessPermission(string $appKey): string
    {
        return 'FINANCE.' . $appKey . '.ACCESS';
    }
}
