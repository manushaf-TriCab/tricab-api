<?php

declare(strict_types=1);

namespace TriCab\Core\Rfq;

/**
 * Marker for the RFQ bounded context — the platform home of the RFQ parser.
 *
 * RFQ owns intake, extraction results, editable review, persisted drafts,
 * queues, quote pricing snapshots, exports and the controlled 4js
 * handoff. It owns **no part of identity**: records link to a person by
 * `identity.Users.UserId`, never by email address. The source application's
 * `rfq_roles`, `rfq_user_roles` and `rfq_identities` are retired, not ported.
 *
 * ## Permissions
 *
 * One permission per route, as everywhere on the platform. The mapping from
 * the source's 21 permission keys is in `platform/rfq-phase0/0.7-traceability.md`
 * §2.3 and `docs/rfq-architecture.md` §4.
 *
 * **Reading is not scoped.** Anyone holding `RFQ.DRAFT.READ` sees every RFQ,
 * as everybody sees every quote in 4js, and every employee holds it
 * ({@see EMPLOYEE_PERMISSIONS}; owner decision, 28 Sep 2026). Branch is a
 * queue filter, not a limit. The source's `draft_scope` (own / branch / all)
 * is not carried. *Working* an RFQ is still narrower: the assignee, or a
 * manager (`RfqAccessPolicy::allowedActions()`).
 *
 * **`RFQ.ACCESS` grants no record.** It reaches the module entry and the
 * caller's own access summary. Every record read needs `RFQ.DRAFT.READ`.
 *
 * ## What is not decided yet (owner questions, Phase 0)
 *
 *  - Who works RFQs. {@see ROLE_PERMISSIONS} describes four roles; nobody is
 *    assigned to any of them (Q8). `EMPLOYEE` reads and does nothing else:
 *    the source made every signed-in staff member a salesperson, and that
 *    wider grant is not carried.
 *  - What `RFQ.PRICING.VIEW` withholds. In the source it hides prices in the
 *    browser but still sends them (Q9). The permission exists so the answer is
 *    a behaviour change, not a new code.
 */
final class Module
{
    public const NAME = 'RFQ';

    /** Seed `ModuleCode` for every permission below. */
    public const MODULE_CODE = 'rfq';

    /** Reach the module and read one's own effective RFQ access. No record. */
    public const PERMISSION_ACCESS = 'RFQ.ACCESS';

    /** List and open every RFQ. Every employee holds it. Source `drafts.view`. */
    public const PERMISSION_DRAFT_READ = 'RFQ.DRAFT.READ';

    /**
     * Create an RFQ from pasted text, files or images. The creator owns it.
     * New: the source lets `drafts.view` create, and splitting create from read
     * lets a read-only reviewer exist.
     */
    public const PERMISSION_DRAFT_CREATE = 'RFQ.DRAFT.CREATE';

    /** Save a revision; resolve reader proposals. Assignee or manager only. */
    public const PERMISSION_DRAFT_EDIT = 'RFQ.DRAFT.EDIT';

    /** Take an unassigned RFQ. */
    public const PERMISSION_DRAFT_CLAIM = 'RFQ.DRAFT.CLAIM';

    /**
     * Assign and reassign, bulk actions, priority and due date. Also the
     * *manager* predicate: a holder may edit, sync and archive work that is not
     * theirs. Source `drafts.assign`.
     */
    public const PERMISSION_DRAFT_ASSIGN = 'RFQ.DRAFT.ASSIGN';

    /** Change workflow status. What each actor may set is in `WorkflowTransitionPolicy`. */
    public const PERMISSION_DRAFT_WORKFLOW = 'RFQ.DRAFT.WORKFLOW';

    public const PERMISSION_DRAFT_COMMENT = 'RFQ.DRAFT.COMMENT';

    /** Archive with a reason. Assignee or manager only. */
    public const PERMISSION_DRAFT_ARCHIVE = 'RFQ.DRAFT.ARCHIVE';

    public const PERMISSION_DRAFT_RESTORE = 'RFQ.DRAFT.RESTORE';

    /**
     * Permanent deletion. Needs a retention policy that does not exist yet
     * (plan 1.3), so no route uses it in Phase 1.
     */
    public const PERMISSION_DRAFT_PURGE = 'RFQ.DRAFT.PURGE';

    /** Hand a saved revision to 4js through a durable attempt. Source `drafts.push`. */
    public const PERMISSION_QUOTE_SYNC = 'RFQ.QUOTE.SYNC';

    /**
     * Resolve an uncertain attempt: adopt the quote 4js turned out to hold, or
     * confirm it holds none. A live-ERP judgement, so it is its own code even
     * though the source folds it into `drafts.push`.
     */
    public const PERMISSION_QUOTE_RECONCILE = 'RFQ.QUOTE.RECONCILE';

    /** See cost, margin and sell. Hide versus withhold is owner question Q9. */
    public const PERMISSION_PRICING_VIEW = 'RFQ.PRICING.VIEW';

    /** Queue statistics. Source `analytics.team` / `analytics.all`, which nothing checked. */
    public const PERMISSION_ANALYTICS_READ = 'RFQ.ANALYTICS.READ';

    /** Review metrics and the activity summary, across every branch. Source `admin.dashboard`. */
    public const PERMISSION_REPORTS_READ = 'RFQ.REPORTS.READ';

    /** The activity log. Source `audit.view`. */
    public const PERMISSION_AUDIT_READ = 'RFQ.AUDIT.READ';

    /** Raw review sessions and row edit logs; customer text included. Source `review.view`. */
    public const PERMISSION_REVIEWLOG_READ = 'RFQ.REVIEWLOG.READ';

    /** Parser, reader, catalogue and job diagnostics. Source `technical.view` + `health.detail`. */
    public const PERMISSION_TECHNICAL_READ = 'RFQ.TECHNICAL.READ';

    /** Module administration. Not a superset of anything. */
    public const PERMISSION_ADMIN = 'RFQ.ADMIN';

    /**
     * Mailbox intake. For a service identity only — the replacement for the
     * source's `MAIL_WORKER_TOKEN` — and held by no person's role.
     */
    public const PERMISSION_MAIL_INGEST = 'RFQ.MAIL.INGEST';

    /** Every permission a person's role may carry. */
    public const PERSON_PERMISSIONS = [
        self::PERMISSION_ACCESS,
        self::PERMISSION_DRAFT_READ,
        self::PERMISSION_DRAFT_CREATE,
        self::PERMISSION_DRAFT_EDIT,
        self::PERMISSION_DRAFT_CLAIM,
        self::PERMISSION_DRAFT_ASSIGN,
        self::PERMISSION_DRAFT_WORKFLOW,
        self::PERMISSION_DRAFT_COMMENT,
        self::PERMISSION_DRAFT_ARCHIVE,
        self::PERMISSION_DRAFT_RESTORE,
        self::PERMISSION_DRAFT_PURGE,
        self::PERMISSION_QUOTE_SYNC,
        self::PERMISSION_QUOTE_RECONCILE,
        self::PERMISSION_PRICING_VIEW,
        self::PERMISSION_ANALYTICS_READ,
        self::PERMISSION_REPORTS_READ,
        self::PERMISSION_AUDIT_READ,
        self::PERMISSION_REVIEWLOG_READ,
        self::PERMISSION_TECHNICAL_READ,
        self::PERMISSION_ADMIN,
    ];

    /** Permissions for other systems. None is granted to a person's role. */
    public const INTEGRATION_PERMISSIONS = [
        self::PERMISSION_MAIL_INGEST,
    ];

    public const ROLE_SALES = 'RFQ_SALES';
    public const ROLE_SALES_MANAGER = 'RFQ_SALES_MANAGER';
    public const ROLE_ADMIN = 'RFQ_ADMIN';
    public const ROLE_DEVELOPER = 'RFQ_DEVELOPER';

    private const SALES = [
        self::PERMISSION_ACCESS,
        self::PERMISSION_DRAFT_READ,
        self::PERMISSION_DRAFT_CREATE,
        self::PERMISSION_DRAFT_EDIT,
        self::PERMISSION_DRAFT_CLAIM,
        self::PERMISSION_DRAFT_WORKFLOW,
        self::PERMISSION_DRAFT_COMMENT,
        self::PERMISSION_DRAFT_ARCHIVE,
        self::PERMISSION_QUOTE_SYNC,
        self::PERMISSION_QUOTE_RECONCILE,
    ];

    private const MANAGER_ADDS = [
        self::PERMISSION_DRAFT_ASSIGN,
        self::PERMISSION_DRAFT_RESTORE,
        self::PERMISSION_ANALYTICS_READ,
        self::PERMISSION_AUDIT_READ,
    ];

    private const ADMIN_ADDS = [
        self::PERMISSION_REPORTS_READ,
        self::PERMISSION_PRICING_VIEW,
        self::PERMISSION_ADMIN,
    ];

    private const DEVELOPER_ADDS = [
        self::PERMISSION_REVIEWLOG_READ,
        self::PERMISSION_TECHNICAL_READ,
        self::PERMISSION_DRAFT_PURGE,
    ];

    /**
     * The four source roles, re-expressed as platform roles. **Draft, for owner
     * review** — `seed-rfq-roles.sql` creates them with these permissions and
     * assigns them to nobody.
     *
     * Parity notes, so a reviewer can see what moved:
     *
     *  - `drafts.delete` (a compatibility key nothing checked) is not carried.
     *  - `RFQ.PRICING.VIEW` sits with admin and developer only, where the
     *    source puts `pricing.view`. Sales and managers price quotes today with
     *    the prices hidden from their view, not withheld from their browser.
     *  - `RFQ.DRAFT.CREATE` is new and goes wherever `drafts.view` was.
     *  - `RFQ.QUOTE.RECONCILE` goes wherever `drafts.push` was.
     *
     * @var array<string, list<string>>
     */
    public const ROLE_PERMISSIONS = [
        self::ROLE_SALES => self::SALES,
        self::ROLE_SALES_MANAGER => [...self::SALES, ...self::MANAGER_ADDS],
        self::ROLE_ADMIN => [...self::SALES, ...self::MANAGER_ADDS, ...self::ADMIN_ADDS],
        self::ROLE_DEVELOPER => [...self::SALES, ...self::MANAGER_ADDS, ...self::ADMIN_ADDS, ...self::DEVELOPER_ADDS],
    ];

    /**
     * What the platform `ADMINISTRATOR` role holds: module administration and
     * nothing that reads an RFQ. The TriExpense and Onboarding pattern — a
     * platform administrator can grant RFQ access without being inside every
     * customer's quote. The source's WordPress `manage_options` backstop, which
     * gave every WordPress admin every RFQ permission, is not carried.
     */
    public const ADMINISTRATOR_PERMISSIONS = [self::PERMISSION_ADMIN];

    /**
     * What the platform `EMPLOYEE` role holds: reach the module and read every
     * RFQ. Owner decision, 28 Sep 2026: all employees see all quotes, as in
     * 4js. Nothing that works an RFQ, and not cost and margin
     * (`RFQ.PRICING.VIEW`, Q9).
     */
    public const EMPLOYEE_PERMISSIONS = [self::PERMISSION_ACCESS, self::PERMISSION_DRAFT_READ];
}
