# Onboarding — architecture, trust boundaries and data classification

Status: **Phase 1** — contracts, permissions and skeletons exist; the module stores no domain data yet.
Companion to `platform/ONBOARDING-MIGRATION-WORK-ORDER.md` (revision 2) and
`platform/ONBOARDING-IMPLEMENTATION-PLAN.md`. Work order §5, §6 and §7 Phase 1.5 ask for this document.

Paired branches: `tricab-api@feature/onboarding-module`, `tricab-web@feature/onboarding-module`.

This document is the reference for *where the boundaries are and who enforces them*. It is not a schema
proposal: the tables land in Phase 2 and the specifics are settled there against what is written here.

---

## 1. What the module owns, and what it must not

Onboarding is a bounded module in the same sense as Expense: `core/src/Onboarding/` for domain and
application code, `src/Onboarding/` in the API for persistence and HTTP adapters, one `Module` marker
class holding the permission constants.

It owns: onboarding profiles, invitations, explicit allocation, training modules and presentations,
immutable assessment attempts, policy versions and acknowledgements, form definitions and immutable
submissions, the reviewer queue and the six-state assignment machine, generated document metadata,
retention hooks and the completion projection for Skill Matrix.

It owns **none of identity**. There is no Onboarding user table, no Onboarding password, no Onboarding
OTP table, no copy of anybody's email address. Records link to a person by `identity.Users.UserId` and
nothing else — not email, not name, not provider subject (work order §6). A person's several verified
addresses are a platform concern delivered by Phase P (`identity.UserEmailAddresses`).

The source application does own all of that, which is the largest single structural difference between
source and target. `otp_challenges` is replaced by the platform's `identity.EmailOtpChallenges` and the
existing guest OTP flow; the identity half of `trainees` is replaced by `identity.Users`.

---

## 2. The four populations this module serves

The module exists because these four look different to HR and must look identical to the authorization
code. Access follows the permission and the assurance level, **never the role or the session type**
(work order §4.15).

| Population | Signs in with | Assurance | Reaches |
|---|---|---|---|
| Pre-start candidate | Email OTP on a personal address (guest session) | LOW | `/onboarding` self-service only |
| Employee with no TriCab mailbox (warehouse, yard) | Email OTP, then passkey once P.5 lands | LOW → SUBSTANTIAL | Self-service, plus whatever their role permits at their assurance |
| Employee with a TriCab mailbox | Microsoft Entra | HIGH | Self-service, plus their role |
| HR, reviewer, content author | Entra, or passkey | HIGH / SUBSTANTIAL | `/admin/onboarding` per permission |

Two consequences worth stating plainly:

- `ONBOARDING.ACCESS`, `ONBOARDING.SELF.READ` and `ONBOARDING.SELF.SUBMIT` are granted to **both**
  `EMPLOYEE` and `GUEST`. Scoping self-service to `GUEST` would make mailbox-less staff permanent
  guests; scoping it to `EMPLOYEE` would lock out candidates.
- Every HR permission sits at `SUBSTANTIAL` in `PermissionAssurancePolicy`. A mailbox-less employee
  therefore **cannot hold a reviewer permission usefully until they have a passkey** (Phase P.5, not
  started). If HR wants a warehouse supervisor reviewing inductions, that supervisor needs passkey
  registration first. This is a deliberate trade, not an oversight.

---

## 3. Trust boundaries

```text
   ┌──────────────────────────────────────────────────────────────────┐
   │ Browser — candidate's phone, or an HR desktop                    │
   │ tricab-web SPA. Route guards here are USABILITY ONLY.            │
   └───────────────────────────────┬──────────────────────────────────┘
                                   │  B1  HTTPS + Bearer token
                                   ▼
   ┌──────────────────────────────────────────────────────────────────┐
   │ BearerTokenAuthenticationMiddleware                              │
   │   validates the token, attaches VerifiedIdentity                 │
   │   VerifiedIdentity carries the PROVIDER that authenticated       │
   └───────────────────────────────┬──────────────────────────────────┘
                                   ▼
   ┌──────────────────────────────────────────────────────────────────┐
   │ IdentityResolutionService / ResolveAuthenticatedUserHandler      │
   │   provider subject or verified address  →  one identity.Users row│
   │   ProviderBindingPolicy refuses a provider on a wrong-purpose    │
   │   address, indistinguishably from an unknown address        B6   │
   └───────────────────────────────┬──────────────────────────────────┘
                                   ▼
   ┌──────────────────────────────────────────────────────────────────┐
   │ RequirePermissionMiddleware                    ← THE gate        │
   │   1. holds the permission?      no → 403 INSUFFICIENT_PERMISSION │
   │   2. assurance floor met?       no → 403 INSUFFICIENT_           │
   │                                       AUTHENTICATION_ASSURANCE   │
   └───────────────────────────────┬──────────────────────────────────┘
                                   │  B3  self  ╱╲  HR
                                   ▼
   ┌──────────────────────────────────────────────────────────────────┐
   │ Onboarding actions → core services → repositories                │
   │   B2  ownership resolved from the authenticated UserId,          │
   │       never from an id in the URL or body                        │
   └───────┬──────────────────────────────┬───────────────────┬───────┘
           │ B4 classification            │ B5 documents      │ B7 feed
           ▼                              ▼                   ▼
   ┌────────────────┐   ┌──────────────────────────┐   ┌──────────────┐
   │ Azure SQL      │   │ Private blob container   │   │ Skill Matrix │
   │ onboarding     │   │ no public URL, ever      │   │ completion   │
   │ onboarding_hr  │   │                          │   │ projection   │
   └────────────────┘   └──────────────────────────┘   └──────────────┘
```

| # | Boundary | What crosses it | Enforced by | A refusal looks like |
|---|---|---|---|---|
| B1 | Browser → API | Bearer token, form answers, uploads | `BearerTokenAuthenticationMiddleware` | 401 `UNAUTHENTICATED` |
| B2 | Session → person's own records | Everything self-service | Repository queries scoped to the authenticated `UserId` | 404, not 403 — see §4.4 |
| B3 | Self-service → HR | Nothing. The two route groups are separate and share no gate | `registerOnboardingRoutes()`, two groups | 403 `INSUFFICIENT_PERMISSION` |
| B4 | Ordinary HR → payroll → restricted HR | Form submissions and their documents | Per-class permission + separate repositories (§5) | 403 `INSUFFICIENT_PERMISSION` |
| B5 | API → document storage | Generated PDFs, candidate photos | Private container; no bytes in Azure SQL | — |
| B6 | Module → platform identity | `UserId` only | `IdentityResolutionService`, `ProviderBindingPolicy` | 401/403 via `IdentityResolutionErrorMapper` |
| B7 | Module → Skill Matrix | Person id, item id/type, completion date, evidence ref — nothing else | Versioned projection contract | — |
| B8 | Scheduled job → email provider | Reminder emails | `GUEST_EMAIL_PROVIDER` abstraction; disabled by default outside production | — |

**The SPA is not a boundary.** `ProtectedRoute`, the admin nav filter and the Onboarding sub-navigation
exist so nobody walks into a page that can only refuse them (work order §4.12). They are recomputed from
`/api/v1/me` and are trivially bypassed by typing a URL, which is why every screen's data comes from a
gated endpoint and every gated endpoint re-checks.

---

## 4. Authorization

### 4.1 Permissions

Seeded by `database/scripts/development/seed-onboarding-permissions.sql`, module code `onboarding`. That
script is the owner of the `ONBOARDING.*` family; the web catalogue in
`admin/permissions/adminBusinessPermissions.ts` deliberately does **not** list them, because the Roles
page auto-creates anything missing from its catalogue and would give them different wording wherever the
seed had not run.

| Permission | Granted to | Assurance floor | Gates |
|---|---|---|---|
| `ONBOARDING.ACCESS` | ADMINISTRATOR, EMPLOYEE, GUEST | LOW | Module entry |
| `ONBOARDING.SELF.READ` | ADMINISTRATOR, EMPLOYEE, GUEST | LOW | `GET /api/v1/onboarding/*` |
| `ONBOARDING.SELF.SUBMIT` | ADMINISTRATOR, EMPLOYEE, GUEST | LOW | Own attempts, acknowledgements, forms |
| `ONBOARDING.REVIEW` | ADMINISTRATOR | SUBSTANTIAL | Reviewer queue, permitted status transitions |
| `ONBOARDING.PEOPLE.MANAGE` | ADMINISTRATOR | SUBSTANTIAL | Invite, allocate, HR overview |
| `ONBOARDING.CONTENT.MANAGE` | ADMINISTRATOR | SUBSTANTIAL | Modules, policy versions, form definitions |
| `ONBOARDING.REPORTS.READ` | ADMINISTRATOR | SUBSTANTIAL | Operational and compliance reporting |
| `ONBOARDING.ADMIN` | ADMINISTRATOR | SUBSTANTIAL | Module administration |

The seed carries a guard that throws if any administrative `ONBOARDING.*` permission is ever mapped to
`EMPLOYEE` or `GUEST`, so a later well-meaning grant fails loudly instead of quietly widening the module.

`ONBOARDING.ADMIN` is **not** a superset. The API gates each route on exactly one permission, so neither
the middleware nor the HR sub-navigation treats it as implying the other four.

### 4.2 The two different 403s

`RequirePermissionMiddleware` distinguishes them because the remedy differs:

- `INSUFFICIENT_PERMISSION` — you do not hold it. Ask an administrator.
- `INSUFFICIENT_AUTHENTICATION_ASSURANCE` — you hold it, but not from this kind of sign-in. Sign in with
  a passkey or Microsoft account.

Collapsing the second into the first sends someone who already has access to an administrator who can do
nothing for them. `/api/v1/me` reports no assurance level, so the SPA cannot anticipate the second case;
it offers HR screens on the permission alone and explains the refusal when it arrives
(`OnboardingAdminOverviewPage`). Adding an assurance field to `/me` would let the nav be exact, and is
recorded in §9 as a proposal rather than done, because `/me` is consumed platform-wide.

### 4.3 Deny by default

Both route groups are registered with an explicit `RequirePermission` middleware and no fallback. A new
Onboarding route with no gate is a review defect, not a working endpoint. Phase 1 added seven API tests
asserting a 403 for each combination that must not pass, including "has only self permissions" and
"has only `ONBOARDING.REVIEW`" against the HR overview.

### 4.4 Ownership

Self-service endpoints never accept a person identifier. There is no `/onboarding/{traineeId}`; the
subject is always the authenticated `UserId` (work order §4.3). Where a URL must name a record — a
specific submission, say — the repository query is scoped by `UserId` as well, so a wrong id returns
"not found" rather than "forbidden". Answering 403 would confirm the record exists, which is an
enumeration oracle over a set of records whose mere existence is sensitive.

Ownership must survive a person's roles changing on their start date: a candidate becomes an employee,
the `GUEST` role may be removed, and none of that may detach them from their own induction. This is why
ownership is keyed on `UserId` and never on role or session type.

---

## 5. Data classification

The source already classifies its forms — `form_definitions.access_group` is one of `hr`, `payroll`,
`restricted_hr` — and the target keeps that distinction rather than inventing a new one. What follows
expands it to everything the module stores.

| Class | Contains | Source home | Target home | May read |
|---|---|---|---|---|
| **C0 Content** | Training modules, questions, policy wording, form definitions | `modules`, `questions`, `policies`, `policy_versions`, `form_definitions` | `onboarding` | Anyone allocated it; authored under `ONBOARDING.CONTENT.MANAGE` |
| **C1 Workflow** | Assignments, status, progress, attempt answers, acknowledgement records, notification log | `assignments`, `presentation_progress`, `attempts`, `policy_acknowledgements`, `notification_log` | `onboarding` | Own records via `SELF.READ`; queue via `REVIEW`; `REPORTS.READ` |
| **C2 Ordinary HR** | Position, department, branch, start date, manager, induction checklist, confidentiality deed, vehicle report | `trainees` (non-identity columns), `form_submissions` where `access_group='hr'` | `onboarding` | `PEOPLE.MANAGE`, `REVIEW` |
| **C3 Payroll-sensitive** | Bank name, BSB, account number, account name, allocation, payslip email, **payslip PIN**, DOB, home address | `form_submissions` where `access_group='payroll'` (`employee-details`) | `onboarding_hr` | A payroll-scoped permission only |
| **C4 Restricted HR** | Health conditions, WHS medical questionnaire, immigration status, passport number, visa number/subclass/expiry, Aboriginal or Torres Strait Islander status, cultural identity, gender identity, dietary needs, emergency contacts | `form_submissions` where `access_group='restricted_hr'`, plus fields inside `employee-details` | `onboarding_hr` | A restricted-HR-scoped permission only |
| **C5 Generated documents** | Completed form PDFs, candidate photo | `form_submissions.completed_pdf_base64`, `trainees.profile_photo_base64` | Metadata in the class of its content; **bytes in a private blob container** | Inherits its content's class |

Three things this table is saying that matter:

1. **C4 is special-category personal information** under the Australian Privacy Act — health,
   racial or ethnic origin, and biometric-adjacent photographs — plus immigration documents. It is the
   most sensitive data the TriCab platform will hold anywhere. It arrives through one form
   (`employee-details`) mixed in with C3 payroll fields and one whole form (`whs-medical-questionnaire`).
   Because it arrives mixed, a submission cannot be classified as a whole: **classification is per field
   within `submitted_data`, and the target must split the payload on the way in**, not filter it on the
   way out.
2. **Emergency contacts are third-party personal data.** The people named never consented to anything
   and have no account. They cannot be subjects of a retention decision the candidate makes.
3. **`payslipPin` is a credential, not a datum.** The source stores it in `submitted_data` and prints it
   onto the generated PDF. Carrying that pattern across would put a live credential in a document and in
   a JSON column. Flagged in §9.

`ONBOARDING.PEOPLE.MANAGE` does **not** imply C3 or C4. The eight seeded permissions have no
payroll-scoped or restricted-scoped member yet, because who may read those two classes is an owner
decision (§9). Until it is made, Phase 2 builds the split and grants the classes to nobody.

### Isolation: what is actually enforced

Two schemas, `onboarding` and `onboarding_hr`, with separate repository classes and no cross-schema
join in application code. Note honestly what that does and does not buy:

- The API connects to Azure SQL as a single principal, so the schema split is **not** a database
  permission boundary today. It is a code boundary: distinct repository interfaces, distinct permission
  gates, and a reviewable rule that a `onboarding` repository never touches an `onboarding_hr` table.
- What it buys is that a future least-privilege pass can `GRANT` per schema without moving tables, and
  that an accidental `SELECT *` over the workflow schema cannot sweep up a bank account number.
- `onboarding_hr` is the first platform schema name with an underscore (`identity`, `expense`,
  `product`, `customer`, `technical`, `migration`, `quote`, `commerce`, `site`, `audit` are all single
  words). Recorded as a deliberate small deviation; the alternative, one schema plus views, hides the
  boundary in exactly the place a reviewer looks for it.

### Documents

`form_submissions.completed_pdf_base64` and `trainees.profile_photo_base64` keep document bytes in
Postgres. The target does not: bytes go to a private blob container, following
`AzureBlobExpenseAttachmentStore` on its dedicated container, with the SQL row holding only metadata and
a reference. Work order §6 asks this to be evaluated rather than assumed, and the conclusion is to
follow the platform pattern — with one qualification:

> The expense precedent hands the browser a short-lived SAS URL. A SAS URL is a bearer capability: it
> works for whoever holds it, it lands in browser history, and it is not authorised per request. That is
> a reasonable trade for a receipt photo. It is not obviously the right trade for a medical
> questionnaire. **Recommendation: stream C3/C4 documents through an authenticated API endpoint so each
> read is authorised and auditable, and reserve SAS for C0–C2.** This costs API bandwidth and is the
> less conventional choice inside this codebase, so it is stated here for the owner to accept or reject
> before Phase 6 builds either one.

### Retention

The platform has a working precedent in `bin/tri-expense-purge-expired-receipts.php`: dry-run by
default, `--apply` to act, and an additional `--i-understand-this-deletes-data` flag in production.
Onboarding retention jobs follow it. Nothing is deleted automatically, and the retention classification
per class is an owner decision that the leaver policy (§9) partly determines.

---

## 6. Web surfaces

Two surfaces, deliberately not one, in `tricab-web`:

| Route | Shell | Gate | Notes |
|---|---|---|---|
| `/onboarding`, `/onboarding/{training,policies,forms,documents}` | `OnboardingShell` | `ONBOARDING.SELF.READ` | Its own header, nav, skip link and footer. Sibling of the `AppLayout` tree |
| `/admin/onboarding` | `OnboardingAdminShell` inside the admin tree | Any one HR permission | Overview; calls the real gated endpoint |
| `/admin/onboarding/queue` | same | `ONBOARDING.REVIEW` | |
| `/admin/onboarding/people` | same | `ONBOARDING.PEOPLE.MANAGE` | |
| `/admin/onboarding/content` | same | `ONBOARDING.CONTENT.MANAGE` | |
| `/admin/onboarding/reports` | same | `ONBOARDING.REPORTS.READ` | |
| `/admin/onboarding/settings` | same | `ONBOARDING.ADMIN` | Addition beyond work order §5's route list: `ONBOARDING.ADMIN` needs somewhere to land |

The candidate surface does not use `AppLayout`, whose marketing header navigates into the wider
platform — a pre-start candidate on a guest session must never be offered links the API would refuse.
It does not use `AdminLayout` either; a sidebar and command palette belong to nobody completing an
induction. A test asserts the candidate shell exposes **no link outside `/onboarding`**, so swapping
the shell later fails the suite rather than silently widening it.

The HR sub-navigation offers only screens whose permission the holder has, for the same reason: a
visible link that answers 403 is worse than no link. The two UI kits
(`components/onboarding/OnboardingUi.tsx`, `components/admin/onboarding/OnboardingAdminUi.tsx`) are
separate because the surfaces have different design tokens, and they share
`components/onboarding/onboardingStatus.ts` so HR and the candidate never see different words for the
same state.

---

## 7. The six-state machine

`api/_lib/status.ts` in the source is already pure — no database, no request object — and is imported by
both handlers and tests. It ports to a Core domain component almost directly. Its two load-bearing
properties, which the port must preserve:

- statuses are partitioned by **who may set them** (`SETTABLE_BY`: trainee / reviewer / system);
- `completed` may only be left by a reviewer.

That is work order invariant 7 expressed in code. The frontend renders the vocabulary
(`onboardingStatus.ts`) and decides nothing; which transitions are legal is Phase 2 API work.

---

## 8. Audit

Work order §5 requires an access audit. The platform precedent is
`audit.UserManagementEvents` (`create-user-management-audit.sql`) — a separate `audit` schema, append
only. Onboarding needs the same for status transitions (who moved an assignment, when, from what, to
what, with what note) and, distinctly, for **reads of C3/C4 documents**, which is the auditing that
matters most and the argument for streaming those downloads through the API rather than handing out SAS
URLs nobody can attribute afterwards.

---

## 9. Known gaps, and decisions owed

Gaps found while writing this, feeding the Phase 0.7 gap list:

1. **`/api/v1/me` exposes no assurance level.** The SPA cannot pre-filter navigation on the assurance
   floor, so an HR person signed in by OTP sees the console and is refused by the API. Handled by
   explaining the refusal. Proposal: add the authenticating provider's assurance to `/me`. Platform-wide
   consumer, so not done unasked.
2. **`payslipPin` is a credential in a data field.** Needs a decision: drop it, store it write-only for
   payroll, or keep source behaviour knowingly. It must not reach a generated PDF held in blob storage
   under the current design.
3. **No permission exists yet for C3 or C4.** The eight seeded permissions stop at `PEOPLE.MANAGE`.
   Who may read payroll and restricted-HR data is an owner decision, and Phase 2 should not guess it.
4. **Phase P.5 passkey-first path is not started**, so mailbox-less employees are stuck at LOW
   assurance and cannot usefully hold any HR permission.
5. **Emergency-contact data has no subject.** Retention and correction rules for third-party data need
   stating even if the answer is "delete with the submission".

Decisions owed by the owner (also at the foot of the implementation plan):

- **Leaver policy.** When a person is deactivated, do they keep read access to their own onboarding
  documents? `assertAuthenticationAllowed()` currently blocks login outright, which answers "no" by
  accident rather than by decision. Shapes retention for C2–C5.
- **Cohort semantics.** Work order invariant 8 says cohorts allocate nothing; the source re-derives
  assignments when `trainees.cohort_id` changes. Confirm which is intended before porting `cohorts`.
- **SAS versus streamed download for C3/C4** (§5).
- **Whether `onboarding_hr` may be an underscore-bearing schema name** (§5).
