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

| Population | User type | Signs in with | Assurance | Reaches |
|---|---|---|---|---|
| Pre-start candidate | Trainee (`GUEST`) | Email OTP on a personal address | LOW | `/onboarding` self-service only |
| Employee with no TriCab mailbox (warehouse, yard) | Trainee (`EMPLOYEE`) | Email OTP, then passkey once P.5 lands | LOW → SUBSTANTIAL | Self-service, plus whatever their role permits at their assurance |
| Employee with a TriCab mailbox | Trainee (`EMPLOYEE`) | Microsoft Entra | HIGH | Self-service, plus their role |
| The people who run onboarding | Admin (`ONBOARDING_ADMIN`) | Entra, or passkey | HIGH / SUBSTANTIAL | All of `/admin/onboarding`, plus their own induction |

**There are two user types, decided 21 Sep 2026: admin and trainee.** An admin
creates, reads, updates and deletes trainees, allocates inductions, reviews what
comes in, authors content and reads reports. A trainee reaches their own
onboarding and nothing else. There is no separate reviewer: reviewing is
something an admin does.

The eight permissions are finer-grained than those two types and stay that way.
The API gates each route on exactly one of them, which is what makes an
endpoint's authority readable from the route table and lets a narrower role be
added later without touching code. The mapping from permissions to these two
types lives in the seed scripts — `seed-onboarding-permissions.sql` and
`seed-onboarding-roles.sql` — not in the application.

Three consequences worth stating plainly:

- `ONBOARDING.ACCESS`, `ONBOARDING.SELF.READ` and `ONBOARDING.SELF.SUBMIT` are granted to **both**
  `EMPLOYEE` and `GUEST`. Scoping self-service to `GUEST` would make mailbox-less staff permanent
  guests; scoping it to `EMPLOYEE` would lock out candidates. The trainee user type needs no role of
  its own as a result, which matters: a role that has to be assigned per person is one somebody will
  forget, locking a new starter out of their own onboarding on their first morning.
- Every administrative permission sits at `SUBSTANTIAL` in `PermissionAssurancePolicy`. A mailbox-less
  employee therefore **cannot be an Onboarding admin usefully until they have a passkey** (Phase P.5,
  not started). If a warehouse supervisor is to run inductions, that supervisor needs passkey
  registration first. This is a deliberate trade, not an oversight.
- **An admin is also a trainee**, holding both `ONBOARDING.SELF.SUBMIT` over their own record and
  `ONBOARDING.REVIEW` over everyone's. Holding the review permission therefore no longer means "not
  the person who did the work", so `SelfReviewPolicy` enforces separately that nobody reviews their
  own onboarding. See §7.

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
| `ONBOARDING.ACCESS` | ONBOARDING_ADMIN, EMPLOYEE, GUEST, ADMINISTRATOR | LOW | Module entry |
| `ONBOARDING.SELF.READ` | ONBOARDING_ADMIN, EMPLOYEE, GUEST, ADMINISTRATOR | LOW | `GET /api/v1/onboarding/*` |
| `ONBOARDING.SELF.SUBMIT` | ONBOARDING_ADMIN, EMPLOYEE, GUEST, ADMINISTRATOR | LOW | Own attempts, acknowledgements, forms |
| `ONBOARDING.REVIEW` | ONBOARDING_ADMIN, ADMINISTRATOR | SUBSTANTIAL | Review queue, permitted status transitions |
| `ONBOARDING.PEOPLE.MANAGE` | ONBOARDING_ADMIN, ADMINISTRATOR | SUBSTANTIAL | Trainee CRUD, allocation, admin overview |
| `ONBOARDING.CONTENT.MANAGE` | ONBOARDING_ADMIN, ADMINISTRATOR | SUBSTANTIAL | Modules, policy versions, form definitions |
| `ONBOARDING.REPORTS.READ` | ONBOARDING_ADMIN, ADMINISTRATOR | SUBSTANTIAL | Operational and compliance reporting |
| `ONBOARDING.ADMIN` | ONBOARDING_ADMIN, ADMINISTRATOR | SUBSTANTIAL | Module administration |

`ADMINISTRATOR` holding all eight is inherited from the first seed and is **not** the intended end
state. TriExpense's pattern is better: a platform administrator holds only the module's `ADMIN`
permission, so they can grant access to a module without being able to read its data. Narrowing it
would remove access from the only role that currently has it, so it waits until real people hold
`ONBOARDING_ADMIN`.

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
| **C3 Payroll-sensitive** | Bank name, BSB, account number, account name, allocation, payslip email, **payslip PIN**, DOB, home address | `form_submissions` where `access_group='payroll'` (`employee-details`) | `onboarding_hr` | `ONBOARDING.PEOPLE.MANAGE` — the admin user type |
| **C4 Restricted HR** | Health conditions, WHS medical questionnaire, immigration status, passport number, visa number/subclass/expiry, Aboriginal or Torres Strait Islander status, cultural identity, gender identity, dietary needs, emergency contacts | `form_submissions` where `access_group='restricted_hr'`, plus fields inside `employee-details` | `onboarding_hr` | `ONBOARDING.PEOPLE.MANAGE` — the admin user type |
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

**Who reads C3 and C4** was settled on 21 Sep 2026 along with the two user types: the admin reads
everything, because an admin who cannot open a trainee's forms cannot do the job the role exists for.
No payroll-scoped or restricted-scoped permission is introduced.

The classification is still worth building, for three reasons that survive the simplification:

1. An accidental `SELECT *` over the workflow schema cannot sweep up a bank account number.
2. Reads of C3/C4 are the ones worth auditing (§8), and auditing them requires knowing which they are.
3. If a narrower role is ever wanted — a coordinator who allocates inductions but does not see medical
   questionnaires — the split is already there and it becomes a permission grant rather than a
   migration.

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
Onboarding retention jobs follow it. Nothing is deleted automatically.

`onboarding.RetentionClasses` is where a retention period is stated, one row per class C0–C5, and
**every row is seeded with `RetentionDays = NULL`** — "no figure decided; keep". The job reads the
table, finds NULL everywhere and deletes nothing. Setting a figure is a deliberate act recorded
against whoever made it: `CK_onboarding_RetentionClasses_Decided` refuses a period without a
`DecidedByUserId` and a date.

#### The leaver policy — decided 21 September 2026

**A departed person keeps read access to their own onboarding records for fourteen days.**

That is the whole decision, and it answers the leaver question in §9. Two clocks were at risk of being
confused here, so state them apart:

| | What it is | Figure |
|---|---|---|
| **Departure grace window** | How long a leaver may still *read* their own records | **14 days**, decided |
| **Record retention** | How long the records are *kept* | Not decided; measured in years, not days |

Fourteen days serves two people: a leaver who wants their own signed induction paperwork on the way
out, and HR, who would otherwise field that request by hand every time. It is **read-only** —
submitting stops at the departure instant, because evidence dated after somebody's last day is worse
than no evidence, since it looks like evidence.

It is emphatically **not** a deletion schedule. Onboarding holds employee records, and the Fair Work
Act 2009 (Cth) s.535 requires an employer to keep those for seven years. A retention job that removed
an induction a fortnight after somebody left would destroy evidence the business is obliged to hold,
in exactly the case where it is most likely to be asked for.

The rule lives in `TriCab\Core\Onboarding\Domain\Service\LeaverPolicy` (pure, 16 tests) and in
`onboarding.TraineeProfiles`: `DepartedAtUtc`, plus a persisted computed `AccessEndsAtUtc` so "whose
window has closed" is an index seek. The constant is stated in both places, so
`OnboardingSchemaTest::test_the_computed_access_window_matches_the_leaver_policy` fails if they ever
drift — a grace period changed in one place and not the other is a silent authorization change that no
ordinary test would catch.

A future `DepartedAtUtc` means *serving out notice*, not *gone*: HR records a last day as soon as it is
known, and somebody working their notice is a current employee who may still be finishing their
induction.

**What is not built.** A grace window needs the person to be able to sign in during it, and today they
cannot: deactivating an account sets `identity.Users` to `disabled`, `AllowsLogin = 0`, and
`assertAuthenticationAllowed()` refuses the login before any Onboarding permission is consulted. Closing
that needs a platform identity change — a departed person who keeps login while holding nothing but the
self-service three — which is a change to shared authentication and is not made unasked. Until it
lands, the fourteen days bind HR rather than the leaver: they are the window in which HR must still be
able to produce a leaver's records, and the window retention tooling must never shorten.

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
(`onboardingStatus.ts`) and decides nothing.

### Nobody reviews their own onboarding

The status machine stops a *trainee* completing their own work, which is enough only while reviewing is
a separate job held by someone else. With two user types it is not: an admin has an induction of their
own, so one person holds `ONBOARDING.SELF.SUBMIT` over their own record and `ONBOARDING.REVIEW` over
everyone's. Without a further rule they could hand in their own medical questionnaire and accept it in
the next request, leaving a record that says a reviewer approved it.

`SelfReviewPolicy` closes that, in two parts:

- `actorFor($actingUser, $subject, $holdsReviewPermission)` derives which hat a person is wearing, and
  is **biased towards trainee on one's own record**. Somebody opening their own induction is doing it,
  not reviewing it. The alternative — letting the caller declare which hat they are wearing — makes the
  privilege the default for whoever asks nicely.
- `assertNotSelfReview()` refuses it outright as a second line, for any path that establishes the actor
  some other way.

Note what this means operationally: **a sole admin cannot complete their own induction.** Somebody else
has to, which is the point. If TriCab ever has exactly one Onboarding admin and that person needs their
own induction signed off, that is a second admin, not an exception in the code.

The source application has this hole — `api/_lib/handlers/admin.ts` calls `requireAdmin()` and then
`assertCanSet('reviewer', …)` with no comparison between reviewer and subject, and `trainees.is_admin`
sits on the trainee's own row. With a handful of people who all know each other it never mattered. It
matters the first time the evidence answers a regulator asking who checked what.

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
3. ~~**No permission exists yet for C3 or C4.**~~ **Resolved 21 Sep 2026:** two user types, and the
   admin reads every class. No payroll-scoped or restricted-scoped permission is introduced. The
   classification split is still built, for the reasons in §5.
4. **Phase P.5 passkey-first path is not started**, so mailbox-less employees are stuck at LOW
   assurance and cannot usefully hold any HR permission.
5. **Emergency-contact data has no subject.** Retention and correction rules for third-party data need
   stating even if the answer is "delete with the submission".

Decisions owed by the owner (also at the foot of the implementation plan):

- ~~**Leaver policy.**~~ **Answered 21 Sep 2026: fourteen days of read access after departure**, read
  only, and separate from how long records are kept — see §5. What it leaves owed is narrower: the
  platform identity change that lets a departed person sign in during the window, and the record
  retention figure per class, which is measured in years and needs advice behind it.
- **Cohort semantics.** Work order invariant 8 says cohorts allocate nothing; the source re-derives
  assignments when `trainees.cohort_id` changes. Confirm which is intended before porting `cohorts`.
- **SAS versus streamed download for C3/C4** (§5).
- **Whether `onboarding_hr` may be an underscore-bearing schema name** (§5).
- **Whether a sole admin may ever sign off their own induction** (§7). The answer in code is no; if
  that is operationally impossible at TriCab's size, the remedy is a second admin, not a code change.
