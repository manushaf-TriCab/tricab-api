#!/usr/bin/env bash
# App Service startup: nginx config, then the product-finder service, then the
# RFQ parser (only when RFQ_PARSER_ENABLED=true).
#
# Set the Linux App Service startup command to:
#
#     bash /home/site/wwwroot/startup.sh
#
# It replaces the previous one-liner
# (`cp /home/site/wwwroot/default /etc/nginx/sites-available/default && service
# nginx reload`), which is still the first thing this does.
#
# The finder runs as a persistent process on 127.0.0.1:8001 and PHP proxies to
# it. There is deliberately **no nginx location** for it: catalogue visibility
# is enforced in PHP by `AssistantCatalogueVisibilityResolver`, which fails
# closed to PUBLIC and grants STAFF only to an Entra identity holding
# PRODUCT.READ. A public route here would be a second, unguarded door to the
# same data.
#
# Nothing below is allowed to fail the deployment. If Python is missing or the
# dependencies did not ship, the site still serves: `HttpProductFinderClient`
# degrades to `sizing: unavailable` rather than 500ing, the same primary/fallback
# posture as `FallbackAssistantResponseProvider`. It logs loudly instead of
# failing silently — a finder that is quietly absent looks exactly like a finder
# that has nothing to say.
#
# There is no install step. An earlier revision built a virtualenv here and
# pip-installed into it; that cannot work on this image, which ships python3
# but not Debian's python3-venv, so `python3 -m venv` dies on a missing
# ensurepip. The wheels are vendored into the release instead.

set -u

WWWROOT=/home/site/wwwroot
APP_PYTHON="$WWWROOT/app/python"
# The dependencies ship in the release, already resolved for this image's
# CPython on x86_64: `_vendor/cp311` for the PHP|8.3 image (Debian 12, python3
# 3.11) and `_vendor/cp312` for PHP|8.5 (Ubuntu 24.04, python3 3.12), chosen
# from `python3 -V` below. There is no virtualenv and no pip at runtime: this
# image has python3 but not Debian's python3-venv, so `python3 -m venv` fails
# with "ensurepip is not available" and no amount of retrying helps. The build
# script vendors the wheels instead -- see docs/product-finder/DEPLOYMENT.md.
VENDOR_ROOT="$APP_PYTHON/_vendor"
VENDOR="$VENDOR_ROOT/cp$(python3 -c 'import sys; print("%d%d" % sys.version_info[:2])' 2>/dev/null || echo unknown)"
LOG_DIR=/home/LogFiles/product-finder
BIND=127.0.0.1:8001
WORKERS="${PRODUCT_FINDER_WORKERS:-2}"

mkdir -p "$LOG_DIR"
LOG="$LOG_DIR/startup.log"

log() { echo "[$(date -u +%FT%TZ)] $*" | tee -a "$LOG"; }

# ── 1. nginx ─────────────────────────────────────────────────────────────────

if [ -f "$WWWROOT/default" ]; then
    cp "$WWWROOT/default" /etc/nginx/sites-available/default && service nginx reload
    log "nginx config applied"
else
    log "WARNING: $WWWROOT/default is missing; nginx left as shipped"
fi

# ── 1b. PHP runtime: OPcache + php-fpm pool ──────────────────────────────────
#
# Neither is shipped by the image in a useful shape. Measured on
# mcr.microsoft.com/appsvc/php:8.3-fpm and :8.5-fpm (Debian 12 / Ubuntu 24.04,
# identical paths and identical /bin/init_container.sh):
#   opcache.max_accelerated_files=4000 (the release has ~10,000 PHP files, so
#   the cache thrashes), memory_consumption=128, interned_strings_buffer=8,
#   and php-fpm `pm.max_children = 5` -- five concurrent requests in total, while
#   one assistant SSE turn or one long RFQ call holds a worker for minutes.
#
# How this gets applied without an app setting (PHP_INI_SCAN_DIR would be one):
# init_container.sh runs "<startup command>; php-fpm;" in sequence, so php-fpm
# starts AFTER this script returns and reads conf.d and php-fpm.d fresh. Nothing
# needs reloading. The pool file is named zz-tricab.conf so it sorts after
# zz-docker.conf and wins; it does not touch `listen`.
#
# Nothing here may fail the deployment: a missing directory skips with a log
# line, and a pool file that php-fpm rejects is removed again.

PHP_CONF_DIR="${PHP_INI_DIR:-/usr/local/etc/php}/conf.d"
FPM_POOL_DIR=/usr/local/etc/php-fpm.d

apply_php_runtime() {
    if [ -d "$PHP_CONF_DIR" ]; then
        # opcache.validate_timestamps stays ON deliberately. The deploy is an
        # in-place zipdeploy and nothing here proves the container restarts on
        # every one, so a stale-forever cache is a worse failure than the stat
        # cost. revalidate_freq stays at the image's 60 s: the production
        # workflow's /health SHA check already tolerates that, and a longer
        # window would only delay the new release becoming visible. Revisit once
        # a restart on every deploy is confirmed.
        #
        # max_accelerated_files: OPcache rounds up to the next prime (32531).
        # Realpath cache: /home is network storage, so path resolution is the
        # other per-request stat cost; the tree has no symlinks, so a 10 minute
        # TTL is safe.
        cat > "$PHP_CONF_DIR/zz-tricab.ini" <<'INI'
; Written by startup.sh. Edit deploy/app-service/startup.sh, not this file.
opcache.enable=1
opcache.memory_consumption=256
opcache.interned_strings_buffer=32
opcache.max_accelerated_files=20000
opcache.validate_timestamps=1
opcache.revalidate_freq=60
realpath_cache_size=8M
realpath_cache_ttl=600
INI
        log "PHP ini written to $PHP_CONF_DIR/zz-tricab.ini"
    else
        log "WARNING: $PHP_CONF_DIR is missing; PHP ini tuning skipped"
    fi

    if [ -d "$FPM_POOL_DIR" ] && command -v php-fpm >/dev/null 2>&1; then
        # Sizing: the plans are P1mv3 / P2v3 (16 GB each). The Python services
        # take about 1-1.5 GB (up to three gunicorns x 2 workers, ~150 MB each
        # for the catalogue); nginx and the continuous WebJobs a little more. A
        # PHP worker is typically 40-80 MB and capped by memory_limit (128 MB
        # default), so 40 children is ~3 GB expected and 5 GB at the hard worst
        # case, which still fits an 8 GB plan. Workers are held for a whole
        # assistant SSE stream or RFQ call (nginx fastcgi_read_timeout 3600), so
        # the image's 5 was the real concurrency ceiling. pm.max_requests recycles
        # a worker after 500 requests to bound leaks (only ever between requests).
        # No request_terminate_timeout: it would cut the SSE streams.
        cat > "$FPM_POOL_DIR/zz-tricab.conf" <<'POOL'
; Written by startup.sh. Edit deploy/app-service/startup.sh, not this file.
[www]
pm = dynamic
pm.max_children = 40
pm.start_servers = 8
pm.min_spare_servers = 4
pm.max_spare_servers = 12
pm.max_requests = 500
POOL
        if php-fpm -t >>"$LOG" 2>&1; then
            log "php-fpm pool override written to $FPM_POOL_DIR/zz-tricab.conf"
        else
            rm -f "$FPM_POOL_DIR/zz-tricab.conf"
            log "WARNING: php-fpm rejected the pool override and it was removed; image defaults apply (pm.max_children=5)"
        fi
    else
        log "WARNING: $FPM_POOL_DIR or php-fpm is missing; php-fpm pool tuning skipped"
    fi

    # Effective values, so the live ones can be confirmed after a deploy.
    # `php -i` is the CLI SAPI; `php-fpm -i` is the FPM SAPI that serves
    # requests (they can differ); the pool comes from `php-fpm -tt`.
    local pat='^(opcache\.(memory_consumption|max_accelerated_files|interned_strings_buffer|validate_timestamps|revalidate_freq)|realpath_cache_(size|ttl)) '
    log "effective PHP (cli): $(php -i 2>/dev/null | grep -E "$pat" | awk -F' => ' '{printf "%s=%s; ", $1, $2}')"
    if command -v php-fpm >/dev/null 2>&1; then
        log "effective PHP (fpm): $(php-fpm -i 2>/dev/null | grep -E "$pat" | awk -F' => ' '{printf "%s=%s; ", $1, $2}')"
        log "effective php-fpm pool: $(php-fpm -tt 2>&1 | grep -E 'NOTICE:[[:space:]]+pm(\.(max_children|start_servers|min_spare_servers|max_spare_servers|max_requests))? =' | sed 's/.*NOTICE:[[:space:]]*//' | tr '\n' ';')"
    fi
    return 0
}

apply_php_runtime || log "PHP runtime tuning hit an error; continuing with the image defaults"

# ── 2. the product finder ────────────────────────────────────────────────────

start_finder() {
    if [ ! -d "$APP_PYTHON" ]; then
        log "ERROR: $APP_PYTHON is missing. The release zip did not carry core/python."
        return 1
    fi

    PYTHON_BIN="$(command -v python3 || true)"
    if [ -z "$PYTHON_BIN" ]; then
        # The PHP App Service image is not guaranteed to ship python3. If this
        # fires, the finder cannot run here and the deployment needs a different
        # answer -- see docs/product-finder/DEPLOYMENT.md.
        log "ERROR: no python3 on PATH. The product finder will not start; the API degrades to sizing: unavailable."
        return 1
    fi
    log "using $PYTHON_BIN ($("$PYTHON_BIN" --version 2>&1))"

    if [ ! -d "$VENDOR/gunicorn" ]; then
        log "ERROR: $VENDOR carries no gunicorn. The release zip has no wheels for this image's Python (has: $(ls "$VENDOR_ROOT" 2>/dev/null | tr '\n' ' '))."
        return 1
    fi
    if [ -f "$VENDOR/VENDORED.txt" ]; then
        log "vendored dependencies: $(tr '\n' ' ' < "$VENDOR/VENDORED.txt")"
    fi

    # Wheels are compiled per CPython minor version and VENDOR already points at
    # the one matching this image. If it is absent the failure above says so.
    # PYTHONPATH carries both the dependencies and the finder itself, so
    # `wsgi:application` and `product_finder.app` resolve without a venv.
    export PYTHONPATH="$VENDOR:$APP_PYTHON"

    # The pinned cable-calculator snapshots. The finder can infer this from its
    # own location, but the release layout is not the repository layout -- the
    # zip stages core/python as app/python, so the relative walk differs -- and
    # a wrong guess here surfaces as "sizing unavailable" while matching still
    # works. State it instead of inferring it.
    local dataset="$WWWROOT/app/resources/cable-calculator/v1"
    if [ -f "$dataset/cableSpecs.json" ]; then
        export PRODUCT_FINDER_DATASET_DIR="$dataset"
        log "sizing dataset: $dataset"
    else
        log "WARNING: $dataset is missing; matching will work but sizing will report unavailable"
    fi

    # Gunicorn's master supervises its workers; nothing supervises the master.
    # An OOM kill or a bad deploy takes it out and the finder is simply gone
    # until the next restart, which on an App Service can be days. Hence the
    # loop -- backed off, so a launch that fails instantly does not spin.
    (
        delay=2
        while true; do
            log "starting gunicorn on $BIND ($WORKERS workers)"
            # --preload + TRICAB_WSGI_WARM: load the catalogue once in the master and
            # share it copy-on-write (see wsgi.py). Partial saving only.
            env TRICAB_WSGI_WARM=1 "$PYTHON_BIN" -m gunicorn \
                --preload \
                --chdir "$APP_PYTHON" \
                --bind "$BIND" \
                --workers "$WORKERS" \
                --timeout 120 \
                --graceful-timeout 30 \
                --access-logfile "$LOG_DIR/access.log" \
                --error-logfile "$LOG_DIR/error.log" \
                wsgi:application >>"$LOG" 2>&1
            log "gunicorn exited ($?); restarting in ${delay}s"
            sleep "$delay"
            delay=$(( delay < 60 ? delay * 2 : 60 ))
        done
    ) &
    disown 2>/dev/null || true
    return 0
}

if ! start_finder; then
    log "product finder not started; the API will report sizing: unavailable"
fi

# ── 3. the RFQ parser ────────────────────────────────────────────────────────
#
# Same model as the finder: its own gunicorn on loopback, no nginx location, PHP
# in front of it. A separate process so a long schedule never holds up a finder
# request. Off unless RFQ_PARSER_ENABLED=true: each worker holds its own
# catalogue (~150 MB), and nothing calls the parser until the RFQ module ships.

RFQ_LOG_DIR=/home/LogFiles/rfq-parser
RFQ_BIND=127.0.0.1:8002
RFQ_WORKERS="${RFQ_PARSER_WORKERS:-2}"
# A reader job's read waits on the model for up to its 600 s deadline
# (`RfqServiceLimits::BACKGROUND_READ_DEADLINE_MS`); the worker must outlive it,
# or gunicorn kills the read and the job is retried from nothing. The worker CLI
# reads one draft at a time, so one worker is always left for interactive parses.
RFQ_WORKER_TIMEOUT="${RFQ_PARSER_WORKER_TIMEOUT_SECONDS:-660}"

start_rfq_parser() {
    if [ "${RFQ_PARSER_ENABLED:-false}" != "true" ]; then
        log "RFQ parser disabled (RFQ_PARSER_ENABLED is not true)"
        return 0
    fi
    PYTHON_BIN="$(command -v python3 || true)"
    if [ -z "$PYTHON_BIN" ] || [ ! -d "$VENDOR/gunicorn" ] || [ ! -f "$APP_PYTHON/rfq_wsgi.py" ]; then
        log "ERROR: the RFQ parser cannot start (python3, vendored gunicorn or rfq_wsgi.py missing); the API reports the parser unavailable"
        return 1
    fi
    mkdir -p "$RFQ_LOG_DIR"
    (
        export PYTHONPATH="$VENDOR:$APP_PYTHON"
        delay=2
        while true; do
            log "starting the RFQ parser on $RFQ_BIND ($RFQ_WORKERS workers)"
            # --timeout above every deadline PHP sends (interactive 60 s, a read
            # 600 s), so the parser answers `timeout` itself rather than gunicorn
            # killing a worker mid-answer.
            # --preload + TRICAB_WSGI_WARM: one catalogue in the master, shared
            # copy-on-write by the workers (see rfq_wsgi.py). Partial saving only.
            env TRICAB_WSGI_WARM=1 "$PYTHON_BIN" -m gunicorn \
                --preload \
                --chdir "$APP_PYTHON" \
                --bind "$RFQ_BIND" \
                --workers "$RFQ_WORKERS" \
                --timeout "$RFQ_WORKER_TIMEOUT" \
                --graceful-timeout 30 \
                --access-logfile "$RFQ_LOG_DIR/access.log" \
                --error-logfile "$RFQ_LOG_DIR/error.log" \
                rfq_wsgi:application >>"$LOG" 2>&1
            log "RFQ parser exited ($?); restarting in ${delay}s"
            sleep "$delay"
            delay=$(( delay < 60 ? delay * 2 : 60 ))
        done
    ) &
    disown 2>/dev/null || true
    return 0
}

if ! start_rfq_parser; then
    log "RFQ parser not started"
fi

# ── 4. the finance AI service ────────────────────────────────────────────────
#
# The third loopback Python process (`finance_ai`, contract finance-ai.v1), modelled on
# the RFQ parser above: its own gunicorn, no nginx location, PHP in front of it. Off
# unless FINANCE_AI_ENABLED=true. It needs FINANCE_AI_INTERNAL_TOKEN (the service refuses
# every call without one) and reads the finance apps' OpenRouter key from
# FINANCE_OPENROUTER_API_KEY; both are App Service settings, set by hand, never here.
# It must never be able to fail the deployment: any problem is logged and skipped.

FINANCE_AI_LOG_DIR=/home/LogFiles/finance-ai
FINANCE_AI_BIND=127.0.0.1:8003
FINANCE_AI_WORKERS="${FINANCE_AI_WORKERS:-2}"
# A model call waits up to its purpose's timeout (at most 200 s,
# `FinanceAiContract::MAX_DEADLINE_MS`); the worker must outlive it, or gunicorn
# kills the call mid-answer instead of the service answering `timeout` itself.
FINANCE_AI_WORKER_TIMEOUT="${FINANCE_AI_WORKER_TIMEOUT_SECONDS:-330}"

start_finance_ai() {
    if [ "${FINANCE_AI_ENABLED:-false}" != "true" ]; then
        log "finance AI disabled (FINANCE_AI_ENABLED is not true)"
        return 0
    fi
    PYTHON_BIN="$(command -v python3 || true)"
    if [ -z "$PYTHON_BIN" ] || [ ! -d "$VENDOR/gunicorn" ] || [ ! -f "$APP_PYTHON/finance_wsgi.py" ]; then
        log "ERROR: the finance AI service cannot start (python3, vendored gunicorn or finance_wsgi.py missing); the API reports it unavailable"
        return 1
    fi
    if [ -z "${FINANCE_AI_INTERNAL_TOKEN:-}" ]; then
        log "WARNING: FINANCE_AI_INTERNAL_TOKEN is empty; the finance AI service will refuse every call"
    fi
    mkdir -p "$FINANCE_AI_LOG_DIR" || return 1
    (
        export PYTHONPATH="$VENDOR:$APP_PYTHON"
        delay=2
        while true; do
            log "starting the finance AI service on $FINANCE_AI_BIND ($FINANCE_AI_WORKERS workers)"
            # --preload shares the imported modules only: this service loads no
            # catalogue, and finance_wsgi registers purposes in memory at import
            # (no sockets, threads or connections), so a fork is safe.
            "$PYTHON_BIN" -m gunicorn \
                --preload \
                --chdir "$APP_PYTHON" \
                --bind "$FINANCE_AI_BIND" \
                --workers "$FINANCE_AI_WORKERS" \
                --timeout "$FINANCE_AI_WORKER_TIMEOUT" \
                --graceful-timeout 30 \
                --access-logfile "$FINANCE_AI_LOG_DIR/access.log" \
                --error-logfile "$FINANCE_AI_LOG_DIR/error.log" \
                finance_wsgi:application >>"$LOG" 2>&1
            log "finance AI service exited ($?); restarting in ${delay}s"
            sleep "$delay"
            delay=$(( delay < 60 ? delay * 2 : 60 ))
        done
    ) &
    disown 2>/dev/null || true
    return 0
}

if ! start_finance_ai; then
    log "finance AI service not started"
fi

log "startup complete"
