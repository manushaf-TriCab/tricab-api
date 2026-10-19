# CableFit research agent (the `cablefit-research` WebJob)

What it is: a continuous WebJob on the existing `tc-web` `core` slot. When a TSD engineer asks for research on a
CableFit candidate, the API saves a `queued` run in `[cablefit].research_runs` and signals the worker; the worker
asks Claude on Foundry (`tricab-ai2`, `CABLEFIT_RESEARCH_MODEL`) to search the web, limited to the domains in
`[cablefit].research_sources`, and saves the findings. **Advisory only:** it never changes a fit score or a design.

Code: `bin/run-cablefit-research.php` (one pass), `deploy/app-service/webjobs/cablefit-research/run.sh` (the loop),
`core/src/CableFit/Application/Research*.php` (service, runner, prompt, parser), `src/CableFit/Research/` (Foundry
model, file wake), `src/CableFit/Persistence/AzureSqlResearch*Repository.php`. API contract: `tmp/cablefit/c3-contract.md`.

## How a pass behaves

- A request writes a `wake` file in `var/cablefit-research/` (web app and WebJob share the app root). The worker runs
  every minute but goes to the database (serverless `tc`) only when: a `wake` file exists, a `pending` file exists
  (a pass is under way or left runs unfinished, or died with the database resuming), or the `checked` file is older
  than `CABLEFIT_RESEARCH_IDLE_CHECK_MINUTES` (default a day; finds a lost signal or a fresh deploy, which empties `var/`).
  An idle pass touches nothing, so `tc` can pause. It never polls the database every minute.
- A pass takes `var/cablefit-research/pass.lock`, then claims queued runs one at a time (at most 3 a pass; more
  stay queued and the `pending` file brings the next pass back). A claim moves the run to `running` in one statement
  (`UPDLOCK, READPAST`), so two workers never get the same run. A run still `running` after 30 minutes is a crashed
  worker's and is claimed again.
- For each run: enabled source domains -> prompt (the parameters that are set, the application, the 24-month rule)
  -> Foundry Messages call with the `web_search_20250305` tool (`allowed_domains` = enabled domains, `max_uses` =
  `CABLEFIT_RESEARCH_MAX_SEARCHES`), continued after `pause_turn` up to 5 rounds -> parse the JSON answer -> drop any
  finding whose URL host is not an enabled domain or a subdomain of one (counted in `dropped_findings`) -> save findings
  and cost -> `succeeded`. A model error, refusal, cut-off, timeout or unreadable answer ends the run `failed` with the
  reason in `error`; the run list shows it. A database error leaves the run `running` and the next pass takes it again.
- Dates: a search result's `page_age` (matched by URL) wins over the date the model states; "March 11, 2026" is stored as
  `2026-03-11`. `date_basis` is `page_age`, `stated_on_page` or `undated`. `withinRecentWindow` is computed when a run
  is read: dated, and no more than 24 months before the run's `requested_at` (a partial date counts from its first day).

## Exit codes

| Code | Meaning |
|---|---|
| 0 | A pass ran, or had nothing to do. |
| 1 | Unexpected failure (the class is on stderr, never the message). |
| 2 | Switched on but not configured: Foundry settings missing, or the run store is in memory (`USER_REPOSITORY` is not `azure-sql`). |
| 3 | Off by design: `CABLEFIT_RESEARCH_ENABLED` is not `true`. |
| 5 | Another pass holds `pass.lock`. |
| 75 | The database is resuming; the work stays pending and the next pass retries. |

## Settings (app settings on the slot)

| Setting | Default | Meaning |
|---|---|---|
| `CABLEFIT_RESEARCH_ENABLED` | `false` | Off, a request is a 503 `CABLEFIT_RESEARCH_OFF` and the worker exits 3. |
| `CABLEFIT_RESEARCH_MODEL` | `claude-sonnet-5` | Foundry deployment name. |
| `CABLEFIT_RESEARCH_MAX_SEARCHES` | `8` | `max_uses` of the search tool per run (1-20). |
| `CABLEFIT_RESEARCH_TIMEOUT_SECONDS` | `300` | Budget for a whole run's model exchange, and per HTTP call (30-900). |
| `CABLEFIT_RESEARCH_IDLE_CHECK_MINUTES` | `1440` | Longest gap between database looks while idle (1-10080). |
| `CABLEFIT_RESEARCH_WAKE_PATH` | `var/cablefit-research` | Wake files and lock; web app and WebJob must share it. |
| `FOUNDRY_ENABLED`, `FOUNDRY_ENDPOINT`, `FOUNDRY_AUTH_MODE`, `FOUNDRY_API_KEY` | platform's | The Foundry connection (managed identity in production). |

The web search tool must stay `web_search_20250305`: the `_20260209` variant is a 400 on this deployment (C3.1, 19 Oct).

## Sources

`[cablefit].research_sources` holds the domains the agent may search and cite (a domain also covers its subdomains).
`database/scripts/development/seed-cablefit-research-sources.sql` inserts the first list (standards bodies,
manufacturers, technical publishers) and never overwrites a row. **Chabilal reviews that list** (open question in the
plan). Edit it through `/api/v1/tsd/cablefit/research-sources`; with no enabled source a request is a 422.

## Pause

Set `CABLEFIT_RESEARCH_ENABLED=false` on the slot. App-setting changes restart the app and its WebJobs; each pass then
exits 3 without touching anything. Runs already queued stay queued and are picked up on resume (the idle check or the
next request wakes the worker).

## A run is stuck or failed

- `queued` for long: check the WebJob is running and its log for exit codes; `touch var/cablefit-research/wake` makes
  the next pass look.
- `running` for more than 30 minutes: the next pass claims it again.
- `failed`: the `error` column says why (model unreachable, declined, ran out of output space, not valid JSON, no
  enabled source). Ask for a new run; a failed run is not retried.
- Cost: `input_tokens`, `output_tokens` and `search_requests` are on every run that reached the model.
