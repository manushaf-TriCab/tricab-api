# Finance permissions and roles: the map from the portal to the platform

Written 6 Oct 2026 (Phase 1, task F1.2). This is the reference each app's phase
builds its permissions and roles from. **Phase 1 seeds only the seven
permissions in section 2, and Phase 2 (7 Oct 2026, task T2.1) seeds Tri-Task's
eleven and its three roles (section 4), and Phase 5 (7 Oct 2026, task T5.1)
seeds Tri-AR's four and its three roles (section 7).** Sections 5, 6 and 8 are
the plan for the phase named there, not something that exists yet; Phases 3
and 4 (sections 5 and 6) were deferred on 7 Oct 2026.

Sources: the portal inventory §5 (`inventory/portal.md`, from `ROLE_SEED` and
`MAIL_PERMISSIONS` in `_work-portal-entra-20261005/server/app.py`) and the §5 of
each app inventory. Decisions D1 to D7 are in `FINANCE-PHASE-1.md`.

## 1. The rules of the map

| Rule | Decision | Example |
|---|---|---|
| A portal permission becomes a platform code by upper-casing it and putting `FINANCE.` in front. The portal's app prefix becomes the app key | D1 | `task.tasks.update_own` becomes `FINANCE.TASK.TASKS.UPDATE_OWN` |
| App keys are `TASK`, `CREDIT`, `COLLECT`, `AR`, `AP`. The portal's `invoice.*` becomes `AP`; its `collect.*` stays `COLLECT` | D1 | `invoice.invoices.read` becomes `FINANCE.AP.INVOICES.READ` |
| A portal role becomes a platform role named `FINANCE_<APP>_<ROLE>`, created by a seed and assigned to nobody | D2 | `manager` in Tri-Task becomes `FINANCE_TASK_MANAGER` |
| The platform `ADMINISTRATOR` role holds `FINANCE.ADMIN` and nothing else in finance. `EMPLOYEE` and `GUEST` hold nothing | D3 | The platform's administrator can grant finance access without reading finance records |
| Phase 1 defines the shared permissions and one access permission per app. Each app's own permissions and roles arrive in its own phase | D4 | Tri-Task's thirteen arrive in Phase 2 |
| Scoped grants (a branch for Credit Notes, a mailbox for Tri-AP) are kept in `finance.access_scopes`, keyed by the platform user, because `identity.UserRoles` has no scope column. No row for a person, app and kind means unrestricted, as in the portal; rows mean exactly those values. Read through `FinanceAccessScopeReader` (the table, `create-finance-access-schema.sql`, is written and not applied) | D6 | F1.10 |
| People are referred to by `identity.Users.UserId`. A map from portal user ids to platform users is the table `finance.portal_user_map`, filled by `bin/map-finance-portal-users.php` | D7 | F1.10 |

Codes are upper-case letters, digits and dots, with `_` allowed inside a segment
(`UPDATE_OWN`).

## 2. What Phase 1 seeds

Seven permissions, module code `finance`, and one role.

| Code | Opens |
|---|---|
| `FINANCE.ACCESS` | the launcher and the caller's own access summary; no record |
| `FINANCE.TASK.ACCESS` | reaching Tri-Task; no record |
| `FINANCE.CREDIT.ACCESS` | reaching Tri-Credit Notes; no record |
| `FINANCE.COLLECT.ACCESS` | reaching Argo Collect; no record |
| `FINANCE.AR.ACCESS` | reaching Tri-AR; no record |
| `FINANCE.AP.ACCESS` | reaching Tri-AP; no record |
| `FINANCE.ADMIN` | finance administration: the mailbox registry (F1.4), and later the grants and reference lists |

| Role | Holds | Assigned to |
|---|---|---|
| `FINANCE_ADMIN` | all seven | nobody |
| `ADMINISTRATOR` (platform) | `FINANCE.ADMIN` only | unchanged |
| `EMPLOYEE`, `GUEST` (platform) | nothing | unchanged |

Each app role below also holds `FINANCE.ACCESS` and its app's `ACCESS`
permission, so that a person given one app role can open the launcher and that
app. That addition is not in the portal; it is the cost of the launcher.

## 3. What the portal does that has no platform equivalent

| Portal feature | What happens to it |
|---|---|
| Users, memberships and role grants in `platform_users`, `platform_user_applications`, `platform_user_roles` | Replaced by the platform's identity system. Users and roles are managed in the platform's own administration. The portal's user admin screen is not ported |
| The user flag "platform administrator" | `ADMINISTRATOR` holding `FINANCE.ADMIN` (D3) |
| Roles re-seeded from code at every start (an edited role reverts on restart) | Not carried. Seeds create a role if missing and withdraw only the permissions they own |
| `*.users.manage` (an app's own administrator changing members' roles) | Not carried as a permission: the platform administrator assigns roles. Tri-Task's own rules for who may grant which role (a manager cannot grant Administrator) are decided in Phase 2 |
| The service secret that bypasses permission checks in Tri-AR | Not carried. A service identity gets a named permission, as `RFQ.MAIL.INGEST` does |
| `shared/tricab_access_client.py` (`exchange`, `introspect`, `list_members_with_permission`, `list_users`, `update_user_access`) | Replaced by the platform's permission checks and a query for "people holding permission X" (needed by Tri-Task and Tri-AR assignee lists; Phases 2 and 5) |

## 4. Tri-Task (Phase 2)

Thirteen portal permissions, eleven carried. The three portal roles are each a
superset of the one before (`tri-task.md` §5). **Seeded by Phase 2, T2.1:**
`seed-finance-task-permissions.sql` (the eleven; `FINANCE.TASK.ACCESS` is
Phase 1's) and `seed-finance-task-roles.sql` (the three roles, assigned to
nobody), both in `Module::TASK_PERMISSIONS` and `Module::TASK_ROLE_PERMISSIONS`
and pinned to them by `FinanceTaskSeedScriptTest`. Each role also holds
`FINANCE.ACCESS` and `FINANCE.TASK.ACCESS` (section 2), so a user holds 8
permissions, a manager 11 and an administrator 13. `Module::TASK_PORTAL_PERMISSIONS`
is the table back to the portal's strings, which `GET /api/v1/finance/task/me`
answers (Phase 2, D19).

| Portal permission | Platform code | user | manager | admin |
|---|---|:-:|:-:|:-:|
| `task.tasks.read` | `FINANCE.TASK.TASKS.READ` | yes | yes | yes |
| `task.tasks.create` | `FINANCE.TASK.TASKS.CREATE` | yes | yes | yes |
| `task.tasks.update_own` | `FINANCE.TASK.TASKS.UPDATE_OWN` | yes | yes | yes |
| `task.tasks.delete_own` | `FINANCE.TASK.TASKS.DELETE_OWN` | yes | yes | yes |
| `task.setup.manage` | `FINANCE.TASK.SETUP.MANAGE` | yes | yes | yes |
| `task.mail.send` | `FINANCE.TASK.MAIL.SEND` | yes | yes | yes |
| `task.tasks.read_all` | `FINANCE.TASK.TASKS.READ_ALL` | | yes | yes |
| `task.tasks.update` | `FINANCE.TASK.TASKS.UPDATE` | | yes | yes |
| `task.tasks.delete` | `FINANCE.TASK.TASKS.DELETE` | | yes | yes |
| `task.users.manage` | not carried (section 3) | | | |
| `task.analytics.read` | `FINANCE.TASK.ANALYTICS.READ` | | | yes |
| `task.roles.assign_admin` | not carried (section 3) | | | |
| `task.settings.manage` | `FINANCE.TASK.SETTINGS.MANAGE` | | | yes |

Roles: `FINANCE_TASK_USER` (portal label Team Member), `FINANCE_TASK_MANAGER`,
`FINANCE_TASK_ADMIN`. Seeded but unused in the portal: `task.mail.read`, held by
no role and checked by nothing; not carried.

A route the portal opened to "update or update own" (or "delete or delete own")
is gated on the weaker permission (Phase 2, D14); every role holding the
stronger one also holds the weaker, so nobody loses access, and the use case
decides own versus any. `FINANCE.TASK.*` is in neither the `ADMINISTRATOR` nor
the `EMPLOYEE` list (D3): a platform administrator alone is not a Tri-Task
member and is not in the assignee list.

## 5. Tri-Credit Notes (Phase 3)

| Portal permission | Platform code | Enforced today |
|---|---|---|
| `credit.notes.read` | `FINANCE.CREDIT.NOTES.READ` | no |
| `credit.notes.create` | `FINANCE.CREDIT.NOTES.CREATE` | no |
| `credit.notes.update_own` | `FINANCE.CREDIT.NOTES.UPDATE_OWN` | no |
| `credit.notes.update` | `FINANCE.CREDIT.NOTES.UPDATE` | no |
| `credit.notes.approve` | `FINANCE.CREDIT.NOTES.APPROVE` | no |
| `credit.notes.process` | `FINANCE.CREDIT.NOTES.PROCESS` | no |
| `credit.notes.delete` | `FINANCE.CREDIT.NOTES.DELETE` | no |
| `credit.reports.read` | `FINANCE.CREDIT.REPORTS.READ` | no |
| `credit.mail.send` | `FINANCE.CREDIT.MAIL.SEND` | no |
| `credit.settings.manage` | `FINANCE.CREDIT.SETTINGS.MANAGE` | no |
| `credit.users.manage` | not carried (section 3) | yes, on the user routes |
| `credit.mail.read` | not carried: in no role, checked by nothing | no |

| Portal role | Platform role | Permissions |
|---|---|---|
| `sales` | `FINANCE_CREDIT_SALES` | `NOTES.READ`, `NOTES.CREATE`, `NOTES.UPDATE_OWN` |
| `branch_manager` | `FINANCE_CREDIT_BRANCH_MANAGER` | `NOTES.READ`, `NOTES.APPROVE` |
| `finance` | `FINANCE_CREDIT_FINANCE` | `NOTES.READ`, `NOTES.PROCESS`, `NOTES.DELETE` |
| `executive_viewer` | `FINANCE_CREDIT_EXECUTIVE_VIEWER` | `NOTES.READ`, `REPORTS.READ` |
| `admin` | `FINANCE_CREDIT_ADMIN` | `NOTES.READ`, `.CREATE`, `.UPDATE`, `.DELETE`, `.APPROVE`, `.PROCESS`, `REPORTS.READ`, `MAIL.SEND`, `SETTINGS.MANAGE` |

Not enforced today means the server checks none of these: Tri-Credit Notes
checks only a sign-in, plus `credit.users.manage` and its own `admin` role on three
configuration tables. **Phase 3 must decide** whether to enforce them (a behaviour
change: a person who can open any note today would be refused) or to carry the
current behaviour and record that. The workflow rules (who approves, who processes)
live in the browser (`inventory/tri-credit-notes.md` §5) and are also a Phase 3
decision.

Scope: a **branch** per person (`finance.access_scopes`, F1.10). The portal
computes `allowed_branches` and the app sends it to the browser but filters
nothing by it, so enforcement is again Phase 3's decision. The browser's display
roles General Manager, CEO and CFO are not portal roles and are not carried as
platform roles; they are labels in the app's Administration page.

## 6. Argo Collect (Phase 4)

| Portal permission | Platform code | Enforced today |
|---|---|---|
| `collect.records.read` | `FINANCE.COLLECT.RECORDS.READ` | yes, every route |
| `collect.records.write` | `FINANCE.COLLECT.RECORDS.WRITE` | yes |
| `collect.records.delete` | `FINANCE.COLLECT.RECORDS.DELETE` | yes |
| `collect.mail.send` | `FINANCE.COLLECT.MAIL.SEND` | yes |
| `collect.users.manage` | not carried (section 3). The app calls any holder `admin` | |
| `collect.mail.read` | not carried: in no role | |

| Portal role | Platform role | Permissions |
|---|---|---|
| `reviewer` | `FINANCE_COLLECT_REVIEWER` | `RECORDS.READ` |
| `admin` | `FINANCE_COLLECT_ADMIN` | `RECORDS.READ`, `.WRITE`, `.DELETE`, `MAIL.SEND` |

The app collapses the roles to `admin` (holds `collect.users.manage`) or `user`,
and the browser shows the sidebar by that (`inventory/argo-collect.md` §5). With
`users.manage` gone, Phase 4 must say which permission now makes someone an admin
in the sidebar; the nearest is `RECORDS.DELETE`, but that is Phase 4's decision.

## 7. Tri-AR (Phase 5)

**Seeded by Phase 5, T5.1 (7 Oct 2026):** `seed-finance-ar-permissions.sql` (four
permissions; `FINANCE.AR.ACCESS` is Phase 1's) and `seed-finance-ar-roles.sql`
(three roles, assigned to nobody), both in `Module::AR_PERMISSIONS` and
`Module::AR_ROLE_PERMISSIONS` and pinned to them by `FinanceArSeedScriptTest`.
Each role also holds `FINANCE.ACCESS` and `FINANCE.AR.ACCESS` (section 2), so a
reviewer holds 4 permissions, an operator 5 and an administrator 6.
`Module::AR_PORTAL_PERMISSIONS` is the table back to the strings the pages
read, which `GET /api/v1/finance/ar/me` will answer (Phase 5, D50).

| Portal permission | Platform code | Enforced today |
|---|---|---|
| `ar.records.read` | `FINANCE.AR.RECORDS.READ` | yes, route by route |
| `ar.records.write` | `FINANCE.AR.RECORDS.WRITE` | yes |
| `ar.admin` | `FINANCE.AR.ADMIN` | yes; satisfies any other permission in the app, and is the only one that edits Settings |
| none (new) | `FINANCE.AR.ATTACHMENTS.READ` | the portal opened attachments to `ar.records.read` |

| Portal role | Platform role | Permissions |
|---|---|---|
| `reviewer` | `FINANCE_AR_REVIEWER` | `RECORDS.READ`, `ATTACHMENTS.READ` |
| `operator` | `FINANCE_AR_OPERATOR` | `RECORDS.READ`, `ATTACHMENTS.READ`, `RECORDS.WRITE` |
| `admin` | `FINANCE_AR_ADMIN` | `RECORDS.READ`, `ATTACHMENTS.READ`, `RECORDS.WRITE`, `ADMIN` |

`FINANCE.AR.ADMIN` is the app's administrator; it is not `FINANCE.ADMIN`, which is
module-wide. **"Satisfies any permission" is carried by the seed, not by a rule in
code** (Phase 5, Q4, decided 7 Oct 2026): the administrator role holds every
Tri-AR permission, which is what the portal's role held, so each route checks
its one permission and nothing more. This replaces the earlier note here that a
policy class would do it.

**Attachments have their own permission** (Phase 5, Q5): the files kept from a
message are customers' payment documents. All three roles hold it, so nothing
changes on day one, and it can be withdrawn from a role later without a release.
The portal's service secret, accepted on every route, is not carried (Q3).
The portal's `MAIL_PERMISSIONS` has no Tri-AR entry; Tri-AR's mail rights come
from the mailbox grants (F1.4), not from permissions. The assignee list is "people holding
`FINANCE.AR.RECORDS.WRITE`".

The tokenised credit-note form for sales reps has no sign-in and so no
permission by design; its single-use and expiry rules are Phase 5's.

## 8. Tri-AP (Phase 6)

| Portal permission | Platform code | Enforced today |
|---|---|---|
| `invoice.invoices.read` | `FINANCE.AP.INVOICES.READ` | no |
| `invoice.invoices.write` | `FINANCE.AP.INVOICES.WRITE` | no |
| `invoice.invoices.delete` | `FINANCE.AP.INVOICES.DELETE` | no |
| `invoice.mail.read` | `FINANCE.AP.MAIL.READ` | no |
| `invoice.mail.send` | `FINANCE.AP.MAIL.SEND` | no |
| `invoice.approvals.prepare` | `FINANCE.AP.APPROVALS.PREPARE` | yes, route 60 |
| `invoice.approvals.decide` | `FINANCE.AP.APPROVALS.DECIDE` | yes, route 61 |
| `invoice.settings.manage` | `FINANCE.AP.SETTINGS.MANAGE` | no |
| `invoice.users.manage` | not carried (section 3) | yes, on the user routes |

| Portal role | Platform role | Permissions |
|---|---|---|
| `operator` | `FINANCE_AP_OPERATOR` | `INVOICES.READ`, `INVOICES.WRITE`, `MAIL.READ`, `MAIL.SEND` |
| `preparer` | `FINANCE_AP_PREPARER` | `INVOICES.READ`, `APPROVALS.PREPARE` |
| `approver` | `FINANCE_AP_APPROVER` | `INVOICES.READ`, `APPROVALS.DECIDE` |
| `admin` | `FINANCE_AP_ADMIN` | all of the above, plus `INVOICES.DELETE`, `SETTINGS.MANAGE` |

Scope: a **mailbox** per person (`finance.access_scopes`, F1.10). An admin or a
person with any unscoped role is unrestricted, as in the portal. Mailbox read and
send rights come from the mailbox grants, not from permissions.

Most of these are unenforced today. Phase 6 decides, as Phase 3 does. Approvals
look up the preparer and approver in a local `users` table that is empty under
the portal (`inventory/tri-ap.md` §10), which the platform's user ids replace.

## 9. User ids

Every finance app records people by the **portal's** user id (`platform_users.id`):
task assignees and preparers, credit-note creators and branch managers, collect and
AR actors, AP preparers and approvers, plus names and emails copied into rows.
The platform's key is `identity.Users.UserId` (D7).

The answer, for every app: **migrated rows are re-pointed through
`finance.portal_user_map`** (portal user id, the email the portal recorded, the
platform user, when mapped), filled by `bin/map-finance-portal-users.php` from an
export of the portal's `platform_users` (JSON or CSV with `id`, `email`,
`full_name`, `is_active`). It matches each portal user's email to the platform
user that holds that address, in `identity.Users` or `identity.UserEmailAddresses`,
and nothing else: no guess from a name, nobody created. It plans by default and
writes only with `--apply` (and, for a database that is not on this machine, only
with `--approved-by=NAME`); running it again changes nothing. It prints every
portal user with no platform user, and every conflict (an email two platform users
hold, a portal user already mapped to someone else, a platform user two portal
users would claim), and leaves those unmapped. The migration reads the map with
`FinancePortalUserMap::platformUserIdFor()`. A portal user with no platform user
cannot be mapped; their rows keep the recorded email and name as text and no
`UserId`, and the list is for Manusha to resolve before a data migration. New
rows use the platform id only. Nothing in finance stores an email as a key.

**Scopes.** `finance.access_scopes` holds one row per allowed value: `(user,
app, kind, value)`, with kind `branch` (Tri-Credit Notes) or `mailbox` (Tri-AP). A
mailbox is stored lower-case. For a person, an app and a kind, no row means
unrestricted and any rows mean exactly those values; the two kinds are
independent. `FinanceAccessScope` answers `allowsBranch()` and `allowsMailbox()`.
It reports rows only: whether an app's administrator is let past a scope is that
app's decision in its phase (the portal let an administrator or anyone with an
unscoped role through). A scope never grants entry to an app; the role and the
permission do. The rows themselves arrive with each app's data migration.

## 10. Counts, for the seed test

| | Portal | This map |
|---|---|---|
| Applications | 5 | 5 app keys |
| Roles | 3 Tri-Task, 5 Credit Notes, 2 Argo Collect, 3 Tri-AR, 4 Tri-AP = 17 | 17 app roles in Phases 2 to 6, plus `FINANCE_ADMIN` now |
| Permissions in roles | 13 Tri-Task (11 carried), 11 Credit Notes (10 carried), 5 Argo Collect (4 carried), 3 Tri-AR (3 carried, 1 added), 9 Tri-AP (8 carried) | 22 now (7 shared + Tri-Task's 11 + Tri-AR's 4); each other app's in its own phase |
