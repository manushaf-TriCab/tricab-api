# Onboarding completion feed — contract v1

Phase 6 of the Onboarding migration (work order §7). **Defined and tested; not deployed.** Nothing
connects to it, no role holds its permission, and no VM or firewall change has been made.

Skill Matrix, on VM 109, polls this feed outbound to learn who has completed which onboarding item.
It is architecture boundary **B7**: person id, item id and type, completion date, and an evidence
reference — nothing else (`docs/onboarding-architecture.md` §3).

## Request

```
GET /api/v1/integrations/onboarding/completions?cursor={cursor}&limit={limit}
Authorization: Bearer <token for the service identity>
```

| Parameter | | |
|---|---|---|
| `cursor` | optional | The `nextCursor` of the last page processed. Omit, or `0`, to start from the beginning. Opaque: send back what you were given. |
| `limit` | optional | 1–500, default 100. Out-of-range values are clamped. |

**Permission:** `ONBOARDING.COMPLETIONS.READ`, at `SUBSTANTIAL` assurance or above — an emailed code is
never enough. No HR or self-service permission opens this route.

## Response

`200`, in the platform's success envelope (`{"success": true, "data": …}`). `data`:

```json
{
  "schemaVersion": "onboarding.completions.v1",
  "items": [
    {
      "eventId": "eeeeeeee-0000-4000-8000-000000000041",
      "cursor": "41",
      "type": "completion.recorded",
      "occurredAtUtc": "2026-09-24T01:15:00Z",
      "person": { "userPublicId": "aaaaaaaa-0000-4000-8000-000000000007" },
      "item": {
        "type": "training",
        "publicId": "bbbbbbbb-0000-4000-8000-000000000001",
        "slug": "corporate-whse-induction",
        "title": "Corporate and WHSE Induction",
        "versionNumber": 1
      },
      "evidenceReference": "onboarding:assignment:cccccccc-0000-4000-8000-000000000041"
    }
  ],
  "nextCursor": "41",
  "hasMore": false
}
```

The full fixture is `tests/Fixtures/Onboarding/completion-feed-v1.json`; the endpoint test requires the
response to equal it exactly and holds every object to the key list above.

| Field | Meaning |
|---|---|
| `schemaVersion` | `onboarding.completions.v1`. A breaking change gets a new version; fields are never repurposed. |
| `eventId` | The status event's public id. **De-duplicate on it.** |
| `cursor` | This event's position. Monotonic. |
| `type` | `completion.recorded` — the item became `completed`. `completion.reopened` — a reviewer moved a completed item out of `completed`; treat the earlier completion as withdrawn. |
| `occurredAtUtc` | When the status changed, UTC. |
| `person.userPublicId` | `identity.Users.PublicId` — the platform's stable person id. Never an email address or a name. |
| `item` | The item and the version completed. `slug` is fixed when the item is created. |
| `evidenceReference` | A reference HR resolves on the platform. Never a URL: the documents behind it stay behind the platform's audited routes. |

**Never present:** scores, answers, form values, reviewer notes, email addresses, names of people,
document URLs.

## Polling, replay and ordering

- Store `nextCursor` **after** processing a page, and send it next time. A page whose processing failed
  is simply requested again with the same cursor: the same cursor returns the same events.
- `hasMore: true` means ask again at once; otherwise wait for the next poll.
- Events are served only once they are **60 seconds** old. A cursor is an identity value, handed out at
  insert and visible at commit, so a younger event could still be overtaken by a lower one committing; the
  settle window is what makes "never skipped" true.
- An empty page returns the cursor it was asked with.

## Errors

| Status | Code | When |
|---|---|---|
| 401 | `UNAUTHENTICATED` | No or bad token |
| 403 | `INSUFFICIENT_PERMISSION` | The identity does not hold `ONBOARDING.COMPLETIONS.READ` |
| 403 | `INSUFFICIENT_AUTHENTICATION_ASSURANCE` | Held, but from an emailed-code sign-in |
| 422 | `VALIDATION_FAILED` | A cursor this feed never issued |

## Audit

Every page read writes one `audit.OnboardingAdminEvents` row, type `completions.read`, attributed to
the service identity, with `cursorFrom`, `cursorTo`, `count` and `schemaVersion` — the range read, not
the people in it (they are in the events themselves).

## Source

`audit.OnboardingStatusEvents`, which is append-only: a completion later reopened is a second event,
never an edited first one. `AzureSqlOnboardingCompletionFeedRepository`; the clustered primary key is
the cursor, so a page is a range seek. Read only.

## Before it can be used — owed

1. **The service identity.** The platform authenticates people only: every bearer token resolves to a
   row in `identity.Users`. Skill Matrix needs a machine identity — most likely an Entra app registration
   using client credentials, mapped to a dedicated service user holding a role with this one permission.
   Accepting app-only tokens is a change to **shared platform authentication**, so it needs its own
   approval (work order §3).
2. **How Skill Matrix matches people.** The feed carries `userPublicId` only. Skill Matrix must hold
   that id for each person, or a mapping must be agreed (an employee number, say) — never by name.
3. **Deployment:** the network path from VM 109 to the API, and the poll interval.
