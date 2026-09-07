#!/usr/bin/env bash
# App Service startup: nginx config, then the product-finder service.
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
# CPython 3.11 on x86_64. There is no virtualenv and no pip at runtime: this
# image has python3 but not Debian's python3-venv, so `python3 -m venv` fails
# with "ensurepip is not available" and no amount of retrying helps. The build
# script vendors the wheels instead -- see docs/product-finder/DEPLOYMENT.md.
VENDOR="$APP_PYTHON/_vendor"
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
        log "ERROR: $VENDOR carries no gunicorn. The release zip was built without the vendored dependencies."
        return 1
    fi
    if [ -f "$VENDOR/VENDORED.txt" ]; then
        log "vendored dependencies: $(tr '\n' ' ' < "$VENDOR/VENDORED.txt")"
    fi

    # The wheels are compiled for CPython 3.11. A different minor version on the
    # image cannot import MarkupSafe's extension, and the failure would surface
    # as a worker that dies on every request rather than a clear startup error.
    local py_minor
    py_minor="$("$PYTHON_BIN" -c 'import sys; print("%d.%d" % sys.version_info[:2])' 2>/dev/null || echo unknown)"
    if [ "$py_minor" != "3.11" ]; then
        log "WARNING: vendored wheels target CPython 3.11 but this image runs $py_minor; rebuild the release with PY_VERSION=$py_minor"
    fi

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
            "$PYTHON_BIN" -m gunicorn \
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

log "startup complete"
