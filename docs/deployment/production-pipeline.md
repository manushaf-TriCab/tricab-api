# Production pipeline

`.github/workflows/deploy-production.yml` deploys the combined website/API package on API `main` pushes or manual dispatches.

API validation runs alongside website revision resolution. After resolution, frontend tests and packaging use the same pinned website commit and run concurrently:

- **API tests and static analysis:** runs the complete `composer check` gate. Composer downloads and PHPStan's incremental result cache are persisted; no test-success marker is used to skip checks. PHPStan validates changes before reusing analysis.
- **Frontend tests:** runs all application tests across four shards (`npm test -- src --shard=N/4`).
- **Release package:** checks out the pinned website commit, installs dependencies once, and builds the production package with `--skip-npm-ci`. The independent `tools/product-3d` package has its own dependencies and test command and is outside this application gate. API and website commit IDs are recorded in the job summary.

The deployment job requires API checks, all four frontend shards, and packaging to succeed, downloads the exact package from that run, deploys it, and checks `/health`. The ZIP is retained for seven days and uploaded without redundant compression. Production deployments remain serialized.

Node and Python download caches are retained; Python's cache key covers both runtime and test requirements. PHPStan results live in the ignored `var/cache/phpstan` directory and are not part of the release package.

## Validate without deployment

Dispatch with `deploy=false`. Optionally set `website_ref` to a website branch or commit under test. This runs all checks and packaging but skips Azure deployment. Branch dispatches cannot deploy; production only runs from API `main`. Production always packages website `main`, regardless of `website_ref`.

```sh
gh workflow run deploy-production.yml --repo tricab/tricab-api \
  --ref perf/deployment-pipeline -f deploy=false \
  -f website_ref=test/frontend-deployment-gate
```

The first run fills caches. Later runs should be compared using step timings, since Azure deployment and runner scheduling are variable. The combined package still includes PHP/Python and frontend assets; independently deploying those components is a separate architecture change.
