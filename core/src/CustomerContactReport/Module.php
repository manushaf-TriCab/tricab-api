<?php

declare(strict_types=1);

namespace TriCab\Core\CustomerContactReport;

/**
 * Customer Contact reports (the complaint register).
 *
 * This is not the customer-person feature. Person records stay on
 * CUSTOMER.CONTACT.* and [customer].[CustomerContacts].
 *
 * The platform EMPLOYEE, ADMINISTRATOR and GUEST roles receive none of these
 * permissions. Ordinary work sits on CUSTOMER_CONTACT_USER. Register work
 * sits on CUSTOMER_CONTACT_COORDINATOR. Decisions sit on
 * CUSTOMER_CONTACT_APPROVER. Nobody is assigned to those roles by the seed.
 *
 * READ sees reports the caller created, is assigned to, or was asked to decide.
 * READ_ALL sees the register and the office fields. COMPLETE and ICAR_SIGN
 * still require the caller to be the current assignee. APPROVE still requires
 * the caller to be the recorded approver, and that person cannot be the one
 * who requested that same remedy. Holding coordinator and approver together
 * is allowed.
 */
final class Module
{
    public const NAME = 'Complaint Register';

    public const MODULE_CODE = 'customer_contact';

    public const PERMISSION_ACCESS = 'CUSTOMER_CONTACT.ACCESS';

    public const PERMISSION_READ = 'CUSTOMER_CONTACT.READ';

    public const PERMISSION_READ_ALL = 'CUSTOMER_CONTACT.READ_ALL';

    public const PERMISSION_CREATE = 'CUSTOMER_CONTACT.CREATE';

    public const PERMISSION_ASSIGN = 'CUSTOMER_CONTACT.ASSIGN';

    public const PERMISSION_COMPLETE = 'CUSTOMER_CONTACT.COMPLETE';

    public const PERMISSION_CLOSE = 'CUSTOMER_CONTACT.CLOSE';

    public const PERMISSION_REOPEN = 'CUSTOMER_CONTACT.REOPEN';

    public const PERMISSION_REMEDY = 'CUSTOMER_CONTACT.REMEDY';

    public const PERMISSION_APPROVE = 'CUSTOMER_CONTACT.APPROVE';

    public const PERMISSION_ICAR_SIGN = 'CUSTOMER_CONTACT.ICAR_SIGN';

    /** @var list<string> */
    public const PERMISSIONS = [
        self::PERMISSION_ACCESS,
        self::PERMISSION_READ,
        self::PERMISSION_READ_ALL,
        self::PERMISSION_CREATE,
        self::PERMISSION_ASSIGN,
        self::PERMISSION_COMPLETE,
        self::PERMISSION_CLOSE,
        self::PERMISSION_REOPEN,
        self::PERMISSION_REMEDY,
        self::PERMISSION_APPROVE,
        self::PERMISSION_ICAR_SIGN,
    ];

    /**
     * Ordinary Customer Contact user. This is not a grant on the platform
     * EMPLOYEE role. COMPLETE and ICAR_SIGN still require the current assignee.
     *
     * @var list<string>
     */
    public const EMPLOYEE_PERMISSIONS = [
        self::PERMISSION_ACCESS,
        self::PERMISSION_READ,
        self::PERMISSION_CREATE,
        self::PERMISSION_COMPLETE,
        self::PERMISSION_ICAR_SIGN,
    ];

    public const ROLE_USER = 'CUSTOMER_CONTACT_USER';

    public const ROLE_COORDINATOR = 'CUSTOMER_CONTACT_COORDINATOR';

    public const ROLE_APPROVER = 'CUSTOMER_CONTACT_APPROVER';

    /** @var list<string> */
    public const COORDINATOR_PERMISSIONS = [
        self::PERMISSION_ACCESS,
        self::PERMISSION_READ,
        self::PERMISSION_READ_ALL,
        self::PERMISSION_ASSIGN,
        self::PERMISSION_CLOSE,
        self::PERMISSION_REOPEN,
        self::PERMISSION_REMEDY,
    ];

    /** @var list<string> */
    public const APPROVER_PERMISSIONS = [
        self::PERMISSION_ACCESS,
        self::PERMISSION_READ,
        self::PERMISSION_APPROVE,
    ];

    /**
     * Dedicated roles. The seed assigns them to nobody.
     *
     * @var array<string, list<string>>
     */
    public const ROLE_PERMISSIONS = [
        self::ROLE_USER => self::EMPLOYEE_PERMISSIONS,
        self::ROLE_COORDINATOR => self::COORDINATOR_PERMISSIONS,
        self::ROLE_APPROVER => self::APPROVER_PERMISSIONS,
    ];

    /** @var array<string, string> stored status => dashboard label */
    public const STATUS_LABELS = [
        'submitted' => 'Awaiting Review',
        'assigned' => 'In Progress',
        'completed' => 'Completed',
        'closed' => 'Closed',
    ];
}
