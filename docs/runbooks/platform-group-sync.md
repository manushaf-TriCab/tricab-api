# Platform group sync (Entra → platform groups)

Tri-Task company plan, Phase 1 (`platform/plans/TRI-TASK-COMPANY-PLAN.md`). Groups live in `identity.Groups` / `identity.GroupMembers`. An Administrator **links** an Entra mail group; this sync keeps its members in step with Entra. Platform groups (made by hand) are never touched.

- **Code:** `src/Identity/Groups/GroupSync.php`, Graph reader `MicrosoftGraphEntraGroupDirectory.php`
- **CLI:** `bin/sync-platform-groups.php`
- **WebJob:** `platform-group-sync`
- **SQL (run first):** `database/scripts/development/create-identity-groups.sql`, then `seed-identity-groups-permissions.sql` (`IDENTITY.GROUPS.MANAGE` to ADMINISTRATOR)
- **Switch:** app setting `IDENTITY_GROUP_SYNC_ENABLED=true`. Off by default: each WebJob pass exits 3 at once and touches no database. "Sync now" on a group page works regardless of the switch.

## The rule

- Only groups an Administrator linked are read. Members are Entra's **transitive** user members (nested groups flattened).
- A member is matched to a platform user by Entra object id, then email (mail, then UPN).
- A member with no platform account is created as a **`pending`** user and added. **Sync never activates anyone**; an Administrator does that by hand. Pending members are flagged in the group page.
- A disabled Entra account with no platform account is not created (counted as `skippedDisabled`). An existing user is still a member.
- Only `entra`-sourced member rows are added or removed. Members of an Entra-linked group cannot be edited by hand.
- **Short-read guard** (`GroupSync::SHORT_READ_RATIO` = 0.5, as HR's short-refresh guard): if a group has Entra members and Entra returns fewer than half of them (zero included), nobody is removed from it that pass; additions still happen. The group shows outcome `guarded` with a warning. An Administrator accepts a real shrink with Sync now plus the override: `POST /api/v1/admin/groups/{id}/sync` with body `{"allowShrink": true}`.
- A group that no longer exists in Entra is marked **unlinked**, not deleted; its members stay. Linking it again clears the flag.

## Graph permission

Needs the application permission **`GroupMember.Read.All`** on the API's app registration (the `GRAPH_*` settings), with tenant admin consent. Until it is granted:

- A pass reads nothing and changes nothing. It notes `permission_not_granted` on every linked group (shown on the group page), prints a typed outcome and exits **6**.
- The WebJob then waits 6 hours before asking again (no loop).
- In the API, the Entra routes (`entra-search`, `link`, group `sync`) answer **503 `GRAPH_PERMISSION_NOT_GRANTED`**. Creating platform groups and editing their members keep working.
- `GRAPH_NOT_CONFIGURED` / `graph_not_configured` is the same, for missing `GRAPH_TENANT_ID` / `GRAPH_CLIENT_ID` / `GRAPH_CLIENT_SECRET`.

## Running it

```bash
php bin/sync-platform-groups.php                # one pass over every linked group
php bin/sync-platform-groups.php --if-enabled   # as the WebJob runs it
```

Output is one JSON line of counts, never a person's details.

| Exit | Meaning |
|---|---|
| 0 | Done, or nothing linked |
| 1 | A group failed (its reason is on the group); the others synced |
| 3 | Switched off (`--if-enabled` without `IDENTITY_GROUP_SYNC_ENABLED=true`) |
| 6 | Graph permission not granted, or Graph not configured. **Needs a person.** |
| 75 | The serverless database is resuming; the next pass retries |

## The WebJob

`platform-group-sync` is a continuous WebJob on the core slot (singleton). It tries a pass every 2 hours on weekdays between 06:00 and 20:00 Melbourne time, so the serverless database can pause overnight and at weekends. Safe if two instances overlap: each group's sync takes a row lock and applying the same membership twice changes nothing.
