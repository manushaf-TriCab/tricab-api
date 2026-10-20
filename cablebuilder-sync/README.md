# cablebuilder-sync

A nightly archive of CableBuilder designs in Azure Blob Storage, run as a WebJob
on the `tc-web` `core` slot. This is step 1 of Jeff's plan: get the data into Blob
Storage first, then define a database schema from what is stored.

CableBuilder's vendor ERP API costs $9K (quoted October 2026), so this reads the
servlet that CableBuilder's own web UI uses, as the first pull did (see
`planning/cablebuilder/README.md` and `planning/reveng-research/04-cablebuilder.md`).
It only reads.

## How it decides what to pull

1. **Change feed.** Dynamics logs every design CableBuilder sends it in
   `CsCbiMessages`. Each night the job reads the messages since the last run and
   takes the item code from `Name` (`DesignBOMVersion: <code>[A0V0L0]`).
   `ConversationName` is often a batch label, so it is not used. A typical day has
   about 100 items, which takes about 2 minutes against CableBuilder.
2. **Sub-designs.** Any sub-design (a BOM line in metres) that a pulled design
   names and the archive lacks is pulled too. This closes the gap of about 1,750
   sub-designs left by the first pull.
3. **Backstop.** Each night 70 pages of the design list (about 14,000 rows, about
   5,600 at alternative 0) are re-checked, so every design is re-checked weekly
   even if it never reaches Dynamics.

Re-pulling an unchanged design writes nothing except a timestamp.
CableBuilder returns byte-identical XML for an unchanged design (checked
2026-10-20), so the SHA-256 of the body is the change signal.

## Blob layout (container `cablebuilder`)

| Path | What |
|---|---|
| `designs/<code>/a<alt>v<ver>/<sha16>.<full\|header>.xml.gz` | One blob per distinct body. The folder is the design's history. `<code>` is percent-encoded, so it is reversible |
| `lookups/<tablecode>/<sha16>.xml.gz` | Lookup tables, same idea |
| `index/designs.jsonl.gz` | Manifest: one line per design, alternative and version, with the current blob, first seen, last checked and earlier blobs |
| `index/lookups.jsonl.gz` | Same for lookup tables |
| `state/changefeed.json`, `state/rotate.json`, `state/nightly.json`, `state/lock.json` | Watermark, backstop cursor, last run, run lock |
| `runs/<utc>-<command>.json` | One summary per run: counts, changed designs, failures |

`header` blobs are designs where `getDesign` failed and only `getDesignHeader`
worked.

## Commands

```
python -m cbsync [--store archive | --store blob:cablebuilder] <command>

status                          counts, watermark, cursor
seed --raw <planning/cablebuilder/raw>   load the August 2026 pull (no CableBuilder calls)
pull CODE ... [--no-recurse]    pull named designs (+ missing sub-designs)
feed --since 2026-10-19         show what Dynamics says changed (no CableBuilder calls)
nightly [--run-key D] [--rotate-pages N]
rotate --pages N
lookups                         all 186 lookup tables
```

## Settings

| Setting | Use |
|---|---|
| `BLOB_CONNECTION_STRING` | Already on the tc-web slots. `AZURE_STORAGE_CONNECTION_STRING` also works |
| `CB_COOKIE` | CableBuilder `JSESSIONID`. **Expires**, see open items |
| `IDENTITY_ENDPOINT`, `IDENTITY_HEADER` | Set by App Service; used for the Dynamics token |
| `D365_TENANT_ID`, `D365_CLIENT_ID`, `D365_CLIENT_SECRET` | Alternative to the managed identity |
| `CBSYNC_ENABLED` | `true` to run. The WebJob ships switched off |
| `CBSYNC_HOUR`, `CBSYNC_ROTATE_PAGES`, `CBSYNC_STORE` | Default `1` (01:00 Melbourne), `70`, `blob:cablebuilder` |

## Deploying on the core slot

The slot's image has `python3` 3.11 but no `venv`, so the dependencies are
vendored into the release, as the product finder's are.
`scripts/build-app-service-release.sh` stages `cbsync/` at `app/cbsync/cbsync`,
vendors `requirements.txt` into `app/cbsync/_vendor` for CPython 3.11 manylinux
x86_64 (about 30 MB, ELF-checked like the finder's), and installs
`deploy/app-service/webjobs/cablebuilder-sync/run.sh` as a continuous WebJob.
`azure-identity` is not vendored: the slot uses `BLOB_CONNECTION_STRING`.

The release ships with the job switched off. Set the app settings, then turn it
on with `CBSYNC_ENABLED=true`. `build-app-service-release.ps1` was already behind
the bash script (it carries neither finance-jobs nor the Python vendoring) and is
not changed.

## Open items before it can run unattended

- **CableBuilder sign-in.** `CB_COOKIE` is a browser session and will expire.
  The job needs to sign in by itself with a dedicated read-only account (the
  shared login disconnects other users). The login form has not been examined
  yet.
- **Dynamics access.** A D365 administrator must add the core slot's managed
  identity (or an app registration) on the *Microsoft Entra applications* page,
  mapped to a read-only user. Tested 2026-10-20: an `az` token for an account
  with a D365 user works (`az login --allow-no-subscriptions`, then
  `cbsync feed`: 322 messages, 117 items for 19 October). An account without a
  D365 user gets 403. So a manual run only needs `az login` as a D365 user;
  the unattended job still needs the managed identity registered.
- **Licence.** Confirm CableBuilder's agreement allows scheduled read-only access.
- **Network.** Not yet confirmed that the core slot can reach `40.115.95.39:8080`.
  It is an Azure address, so this is expected to work.

## Tests

```
python -m pytest -q   (from cablebuilder-sync/)
```
