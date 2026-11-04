# RFQ mail reader (the `rfq-mail` WebJob)

What it is: a continuous WebJob on the existing `tc-web` `core` slot. Once a minute it asks Microsoft Graph what
changed in the Inbox and the folders under it (staff file mail into those child folders by hand) of the mailbox
(`RFQ_MAIL_MAILBOX`), records new messages in the `[mail]` schema (what the `/inbox`
screen shows), runs the pre-filter, has Claude on Foundry (`tricab-ai2`, `claude-haiku-5-5`) classify what is left,
and records the result. **Shadow mode only:** it creates no drafts and sends nothing (drafts are RFQ M6).

Code: `bin/run-rfq-mail-reader.php` (one pass), `deploy/app-service/webjobs/rfq-mail/run.sh` (the loop),
`core/src/Mail/Intake/` (the pass, pre-filter, classifier contract), `src/Mail/Intake/` (Graph, Foundry, files).

## Mailbox changes

**The reader does not change the Outlook mailbox.** Its Graph mail calls are GETs: delta pages, messages,
headers and attachments. It does not mark messages read or unread, set flags or categories, move or delete
messages, create Outlook drafts, or send replies. Opening a body in `/inbox` also leaves Outlook unchanged.
The inbox's Mark read / Mark unread actions update `[mail]` only.

This describes the code's behavior, not the app registration's permission boundary. The shared `GRAPH_*` app
also holds `Mail.ReadWrite` and `Mail.Send`; the reader does not use those write permissions. As checked on
19 Oct 2026, restricting the shared app's mailbox access is still an Exchange-admin follow-up; the scope must
include the mailboxes used by its other features as well as melbourne@.

## How a pass behaves

- It asks **Graph only**. It touches the database (serverless `tc`) only when Graph returns messages, plus a
  heartbeat that refreshes the mailbox's "last sync" at most every `RFQ_MAIL_HEARTBEAT_MINUTES` (default 360). An idle
  pass leaves `tc` free to pause.
- The delta cursor is the file `var/rfq-mail/state.json` (next to `pass.lock` and `pending/`). It moves only after a
  whole page of messages has been recorded and assessed, and is replaced atomically. A failed pass leaves it where the
  last finished page put it, so nothing is lost; Graph redelivers and the recorder dedupes on `internetMessageId`.
- **It reads the Inbox and every folder below it, recursively**, each with its own delta cursor (`cursors` in
  `state.json`, keyed by folder id). It never reads Sent Items, Drafts, Junk, Outbox, Deleted Items, Clutter or
  Conversation History. The folder list (`GET mailFolders/inbox/childFolders`, recursively) is cached in `state.json` and
  listed again every `RFQ_MAIL_FOLDER_REFRESH_MINUTES` (default 30), or the next pass after a folder's delta answers 404
  (a deleted folder loses its cursor). A new folder starts from `RFQ_MAIL_FIRST_RUN_DAYS` ago. If listing the folders
  fails, that pass reads the Inbox and the last known folders and the log says so. An idle pass is now one delta call per
  folder (about 8), still Graph only. `RFQ_MAIL_EXCLUDE_FOLDERS` leaves named folders out (and everything under them).
- **Filing mail does not record it twice.** A message moved from the Inbox into a child folder is reported as removed
  from the Inbox and as a new row in the folder. Recording is by mailbox + `internetMessageId`, which does not change on a
  move (with the reader's immutable ids the Graph id does not change either), so the second sighting is counted as a
  duplicate: no new record, no classification, no body fetch. A removal never deletes or hides what was recorded.
- The budget (`RFQ_MAIL_PASS_SECONDS`, 40 pages) is shared across folders: the Inbox always gets its turn, and a folder
  the budget did not reach waits for the next pass with its cursor unchanged.
- The first pass (no cursor) reads the last `RFQ_MAIL_FIRST_RUN_DAYS` days (default 2), not the whole mailbox. This is per
  folder.
- Bodies are fetched from Graph only for messages that pass the pre-filter, used for the classifier call and never
  stored. Attachments are recorded as metadata only.
- **A deploy empties `var/`.** The next pass then starts again from `RFQ_MAIL_FIRST_RUN_DAYS` ago. Nothing is recorded
  twice (dedupe), but messages that were already assessed are not classified again, so it only costs Graph calls.

## Settings (app settings on the slot; all off by default)

| Setting | Default | Meaning |
|---|---|---|
| `RFQ_MAIL_READ_ENABLED` | `false` | The pause switch. Must be `true` to read anything. |
| `RFQ_MAIL_MODE` | `off` | `off` does nothing at all; `shadow` classifies and records; `live` is `shadow` plus a log warning unless `RFQ_MAIL_DRAFTS_ENABLED` is also `true` (see Drafts below). |
| `RFQ_MAIL_DRAFTS_ENABLED` | `false` | M6 "drafts on". Takes effect only with `RFQ_MAIL_MODE=live`. Do not set it until the accuracy gate (RFQ plan M5) has passed. |
| `RFQ_MAIL_NOTIFY_ENABLED` | `false` | New-email-RFQ bell notices (see Notifications below). Needs drafts on too. |
| `RFQ_MAIL_DRAFT_BRANCH` | `VIC` | The branch stamped on drafts made from mail. Draft branches are state codes (`VIC`, `NSW`, `QLD`, `SA`, `TAS`, `NZNTH` on tricab-mel, 3 Nov 2026); melbourne@ is `VIC`. |
| `RFQ_MAIL_GRAPH_TENANT_ID` / `_CLIENT_ID` / `_CLIENT_SECRET` | empty | A Graph registration of the reader's own. Empty (all three), it uses the platform's `GRAPH_*`, the RFQ Graph app `ai-rfq-parser-mail`, which already holds `Mail.Read` (19 Oct 2026). Set all three to override. |
| `FOUNDRY_ENABLED`, `FOUNDRY_ENDPOINT`, `FOUNDRY_AUTH_MODE` | platform's | The classifier uses the platform's Foundry connection (managed identity). |
| `RFQ_MAIL_CLASSIFIER_MODEL` | `claude-haiku-5-5` | Foundry deployment name. |
| `RFQ_MAIL_CLASSIFIER_TIMEOUT_SECONDS` | `30` | Per model call; a timeout is recorded as `uncertain`. |
| `RFQ_MAIL_MAILBOX` | `melbourne@tricab.com` | The mailbox read. Changing it is safe without clearing state: the saved delta cursor belongs to the mailbox it was issued for, so the new mailbox starts from `RFQ_MAIL_FIRST_RUN_DAYS` ago. Reading is `GET` only: nothing in the mailbox is marked read, moved, flagged or changed. |
| `RFQ_MAIL_FIRST_RUN_DAYS` | `2` | Window for a pass with no cursor. |
| `RFQ_MAIL_PASS_SECONDS` | `100` | A pass stops fetching further pages after this long and resumes next minute. |
| `RFQ_MAIL_FOLDER_REFRESH_MINUTES` | `30` | How long the discovered Inbox folder list is trusted before it is listed again (1 to 1440). |
| `RFQ_MAIL_EXCLUDE_FOLDERS` | empty | Comma-separated folder names (any case) the reader skips, with everything below them. |
| `RFQ_MAIL_HEARTBEAT_MINUTES` | `360` | Longest gap between "last sync" refreshes while idle. |
| `RFQ_MAIL_STATE_PATH` | `var/rfq-mail` | Cursor, lock, pending markers. |
| `RFQ_MAIL_OWN_ADDRESSES` | empty | Extra addresses treated as our own mail (the mailbox itself always is). |

## Drafts (RFQ M6): off by default

Drafts need **both** `RFQ_MAIL_MODE=live` and `RFQ_MAIL_DRAFTS_ENABLED=true`. Either alone is the shadow pass (with
`live` alone the reader logs why). Before switching on, run `database/scripts/development/alter-mail-followup-links.sql` on the `[mail]` database, make sure the mailbox user
(`RFQ_MAIL_MAILBOX`) is a platform user holding `RFQ.DRAFT.CREATE` (the RFQ sales role), and that the RFQ parser
(`RFQ_PARSER_*`) is configured for the WebJob too, because the reader calls it.

With drafts on, after a message is classified:

- `rfq`: a Quotegen draft (source `email`) is made through the upload's intake: the email body as `email-body.txt`
  plus every attachment the parser reads, owned by and assigned to the mailbox user, branch `RFQ_MAIL_DRAFT_BRANCH`,
  with the message ids and original sender in `email_meta`. The thread is marked `drafted` and links the draft.
  A message flagged for injection never gets an automatic draft.
- `rfq_followup` (or a second `rfq` message in a conversation that already has a draft): linked to that draft
  (Related tab, Activity `followup_linked`). The draft's lines are not touched.
- `uncertain`: waits in the inbox for "Create draft" (same intake) or "Ignore". `not_rfq`: recorded only.

**One message is one draft.** The draft's id is a hash of the mailbox and the message's internetMessageId, so a retry,
an overlapping pass, Graph redelivering or a reviewer's click all land on the same primary key; the second one adopts
the first's draft. A draft that cannot be made now (parser or Graph down) is written to `state.json` (`draftRetries`)
before the attempt and retried on later passes with a back-off (2, 4, 8 ... minutes, at most an hour) up to 8 times; then
the log says `Gave up making a draft` and the conversation stays in the inbox. A pass that has drafts on prints
`drafted`, `followups`, `draftRetries` and `draftFailures` in its count line. `--status` shows `draftsEnabled` and
`draftsOn`.

**Switch off:** set `RFQ_MAIL_DRAFTS_ENABLED=false` (the reader returns to the shadow pass at once; drafts already
made stay). "Create draft" then answers 503 again.

## Notifications: new email RFQs

Drafts land unassigned in the Quotegen queue, so with `RFQ_MAIL_NOTIFY_ENABLED=true` the reader also tells people. When
(and only when) the reader makes a **new** draft from an `rfq` message, one row per active user holding
`RFQ.DRAFT.CREATE` (the Quotegen Sales and Administrator roles; the mailbox's own user is skipped) is written to
`[mail].[rfq_notifications]`. The platform's bell (top bar) shows them as "New email RFQ: <subject>", with the unread
count added to the badge, linking to `/admin/quotegen/<draft>`. Opening the bell marks them read.

- Never sent for follow-ups, for the inbox's "Create draft" button, or in shadow mode (no automation exists then).
- At most one notice per person per draft (unique index), so a retry or overlapping pass cannot double up.
- Best effort: a failure is logged (`RFQ mail notification not sent` / `not stored`) and never undoes the draft.
- Needs drafts on (`RFQ_MAIL_MODE=live` + `RFQ_MAIL_DRAFTS_ENABLED=true`). Run
  `database/scripts/development/create-mail-rfq-notifications.sql` first (idempotent). With the flag off the API answers
  an empty list and touches no table.
- Switch off: `RFQ_MAIL_NOTIFY_ENABLED=false`; existing rows stay and are hidden.

## Pause

Set `RFQ_MAIL_READ_ENABLED=false` (or `RFQ_MAIL_MODE=off`) on the slot. App-setting changes restart the app and its
WebJobs, so the next pass sees it. Each pass then exits 3 without reading anything. If the mailbox had been connected,
the first paused pass records the mailbox as `paused` (the screen shows it); a reader that was never switched on
touches nothing. The cursor is kept, so on resume the reader picks up exactly where it stopped, including mail that
arrived while paused.

## Resume

Set `RFQ_MAIL_READ_ENABLED=true` and `RFQ_MAIL_MODE=shadow`. The next pass records the mailbox as `connected` and reads
what changed since the cursor. If it was paused for longer than Graph keeps delta state (Graph expires old delta state and answers 410),
the reader notices, logs it, and starts again from `RFQ_MAIL_FIRST_RUN_DAYS` ago.

## Reset the delta token

Use when the cursor is suspect, or to re-read the recent window after fixing something.

1. Open the slot's SSH/Kudu console in `/home/site/wwwroot/app`.
2. `php bin/run-rfq-mail-reader.php --status` shows what the reader remembers (Inbox cursor present or not, how many
   folder cursors, the folder list, connection, switches), with no Graph or database call.
3. `php bin/run-rfq-mail-reader.php --reset-cursor` clears every folder's cursor and the folder list (it waits for no pass: if one is running it
   exits 5, try again). Or delete `var/rfq-mail/state.json` by hand.
4. The next pass reads the last `RFQ_MAIL_FIRST_RUN_DAYS` days. Messages already recorded are not recorded again and
   are not classified again, so a reset is safe.

To reclassify a message, that is a later job (M5 tooling): the recorder appends a new assessment and the latest wins.

## Read the WebJob log

Kudu (`https://<app>.scm.azurewebsites.net`) -> WebJobs -> `rfq-mail` -> Logs (the files live under
`/home/data/jobs/continuous/rfq-mail/`). `run.sh` writes one line per failed pass (`rfq mail pass exited N`), plus the
reader's own message on stderr. A pass that received mail prints one JSON line of counts (pages, received, recorded,
duplicates, skipped, classified, uncertainFailures, removed, resynced); an idle pass prints nothing. Counts only, never mail
text. Passes that exit 3 (paused) are not logged. A model failure is logged as `Classifier failed: ...` and recorded on the
message as `uncertain` with the reason.

## State file compatibility

`var/rfq-mail/state.json` from before folders has one `cursor` + `cursorMailbox`. It still loads: that cursor is the
Inbox's cursor for that mailbox (a cursor still belongs to its mailbox), and every other folder starts from
`RFQ_MAIL_FIRST_RUN_DAYS` ago on the first pass. The Inbox cursor is also still written under the old keys, so rolling
the release back keeps it.

## Backfill (one-off): mail filed before the reader looked in the folders

The reader first read only the Inbox, so mail staff had already filed into child folders was never recorded.
`bin/backfill-rfq-mail.php` records it. The release ships it next to the reader. It reads Graph with GET only (a list
with a `receivedDateTime` filter, not delta), never touches the reader's cursors or state, skips messages already
recorded (by `internetMessageId`, whatever folder they are in), records the mailbox's own mail as pre-filter skipped like
the reader, and in `--apply` records and classifies in **shadow**: it never makes a draft, link or notification,
whatever `RFQ_MAIL_MODE` is. Run it from the slot's SSH console:

```
cd /home/site/wwwroot/app
php bin/backfill-rfq-mail.php --days=14 --include-deleted            # 1. dry run (the default): counts only
php bin/backfill-rfq-mail.php --days=14 --include-deleted --apply --limit=10   # 2. try a few (optional)
php bin/backfill-rfq-mail.php --days=14 --include-deleted --apply    # 3. the rest
```

| Option | Meaning |
|---|---|
| `--days=N` | Look back N days. 1 to 30, default 14. |
| `--include-deleted` | Also read Deleted Items. Without it, only the Inbox and its child folders. |
| `--dry-run` | The default. Per folder: messages seen, already recorded, new. Nothing is recorded and no model is called. |
| `--apply` | Record and classify the new ones. Needs the Foundry settings. |
| `--limit=N` | With `--apply`, stop after N new messages. A later run carries on. |
| `--pause=S` | Seconds between model calls (default 1). On a 429 or 529 it waits 5, 10, 20, 40, 80 s and retries. A message still throttled after that is **deferred**: not recorded at all (no record, no `uncertain` assessment), so a re-run picks it up as new. After three deferrals in a row it stops and says so. |

It prints one line per folder (seen, already recorded, new; with `--apply` also recorded now, classified, pre-filter
skipped, deferred, errors) and a total. Safe to run again: recorded messages are skipped, so a run stopped by throttling, a
database resume (exit 75) or Graph (exit 4) is finished by running it again. Deferred messages (throttled after retries) are
counted as `deferred` and left unrecorded, so a throttled Foundry cannot put false `uncertain` rows in the M5 labelled data;
any other model failure is recorded `uncertain` with the reason, as the reader does, and counted under errors. The backfill
classifies before it records, so it passes no draft hint (the conversation has no thread yet). The dry run reads
`[mail]` to compare, so it can wake the serverless `tc`; it writes nothing. Run it while the reader is running: an
overlap is safe (one record per message). Exit codes: 0 done, 1 failed, 2 not configured, 4 Graph, 64 bad option, 75 database resuming.

## Exit codes (`bin/run-rfq-mail-reader.php`)

| Code | Meaning | What to do |
|---|---|---|
| 0 | A pass ran, or had nothing to do. | Nothing. |
| 1 | Unexpected failure. The cursor is at the last finished page. | Read the log line (class and message). Pending messages are finished on the next pass. |
| 2 | Switched on but not configured: missing Graph credentials (`RFQ_MAIL_GRAPH_*`, or `GRAPH_*` when those are empty), Foundry settings, or the mail store is in memory (`USER_REPOSITORY` is not `azure-sql`). The log names the settings. | Set them. Nothing was read. |
| 3 | Paused by design: `RFQ_MAIL_MODE=off` or `RFQ_MAIL_READ_ENABLED=false`. | Expected while off. Not logged by `run.sh`. |
| 4 | Microsoft Graph refused (401/403: consent or Application Access Policy) or could not be reached. The cursor is unchanged. The mailbox is shown as `error`. | Check the registration, its secret expiry, and that the mailbox is in its policy scope. |
| 5 | Another pass holds `var/rfq-mail/pass.lock` (a slot swap, or a long pass). Nothing done. | Nothing; the next minute retries. |
| 75 | The database is resuming (serverless `tc`). The cursor is unchanged; the next pass is handed the same messages. | Nothing, unless it persists for many minutes. |

Messages that Graph reports removed from a folder (deleted or moved) are counted and left alone: what was recorded stays
on the screen.
