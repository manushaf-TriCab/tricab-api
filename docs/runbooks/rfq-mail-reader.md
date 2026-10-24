# RFQ mail reader (the `rfq-mail` WebJob)

What it is: a continuous WebJob on the existing `tc-web` `core` slot. Once a minute it asks Microsoft Graph what
changed in the Inbox of `melbourne@tricab.com`, records new messages in the `[mail]` schema (what the `/inbox`
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
- The first pass (no cursor) reads the last `RFQ_MAIL_FIRST_RUN_DAYS` days (default 2), not the whole mailbox.
- Bodies are fetched from Graph only for messages that pass the pre-filter, used for the classifier call and never
  stored. Attachments are recorded as metadata only.
- **A deploy empties `var/`.** The next pass then starts again from `RFQ_MAIL_FIRST_RUN_DAYS` ago. Nothing is recorded
  twice (dedupe), but messages that were already assessed are not classified again, so it only costs Graph calls.

## Settings (app settings on the slot; all off by default)

| Setting | Default | Meaning |
|---|---|---|
| `RFQ_MAIL_READ_ENABLED` | `false` | The pause switch. Must be `true` to read anything. |
| `RFQ_MAIL_MODE` | `off` | `off` does nothing at all; `shadow` classifies and records; `live` is `shadow` plus a log warning (no drafts until M6). |
| `RFQ_MAIL_GRAPH_TENANT_ID` / `_CLIENT_ID` / `_CLIENT_SECRET` | empty | A Graph registration of the reader's own. Empty (all three), it uses the platform's `GRAPH_*`, the RFQ Graph app `ai-rfq-parser-mail`, which already holds `Mail.Read` (19 Oct 2026). Set all three to override. |
| `FOUNDRY_ENABLED`, `FOUNDRY_ENDPOINT`, `FOUNDRY_AUTH_MODE` | platform's | The classifier uses the platform's Foundry connection (managed identity). |
| `RFQ_MAIL_CLASSIFIER_MODEL` | `claude-haiku-5-5` | Foundry deployment name. |
| `RFQ_MAIL_CLASSIFIER_TIMEOUT_SECONDS` | `30` | Per model call; a timeout is recorded as `uncertain`. |
| `RFQ_MAIL_MAILBOX` | `melbourne@tricab.com` | The mailbox read. |
| `RFQ_MAIL_FIRST_RUN_DAYS` | `2` | Window for a pass with no cursor. |
| `RFQ_MAIL_PASS_SECONDS` | `100` | A pass stops fetching further pages after this long and resumes next minute. |
| `RFQ_MAIL_HEARTBEAT_MINUTES` | `360` | Longest gap between "last sync" refreshes while idle. |
| `RFQ_MAIL_STATE_PATH` | `var/rfq-mail` | Cursor, lock, pending markers. |
| `RFQ_MAIL_OWN_ADDRESSES` | empty | Extra addresses treated as our own mail (the mailbox itself always is). |

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
2. `php bin/run-rfq-mail-reader.php --status` shows what the reader remembers (cursor present or not, connection,
   switches), with no Graph or database call.
3. `php bin/run-rfq-mail-reader.php --reset-cursor` clears the cursor (it waits for no pass: if one is running it
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

Messages that Graph reports removed from the Inbox (deleted or moved) are counted and left alone: what was recorded stays
on the screen.
