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
# install fails, the site still serves: `HttpProductFinderClient` degrades to
# `sizing: unavailable` rather than 500ing, the same primary/fallback posture as
# `FallbackAssistantResponseProvider`. It logs loudly instead of failing
# silently — a finder that is quietly absent looks exactly like a finder that
# has nothing to say.

set -u

WWWROOT=/home/site/wwwroot
APP_PYTHON="$WWWROOT/app/python"
# Outside wwwroot on purpose: a zip deploy replaces wwwroot wholesale, so a venv
# under it is rebuilt on every release (minutes of pip per deploy). /home is the
# persistent share, so this survives deploys and is shared across instances.
# `tc-web` puts its venv at /home/site/wwwroot/rfq-parser/.venv; do not copy that.
VENV=/home/data/python/venv
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

    # One instance builds the venv; the others wait rather than racing pip
    # against a shared Azure Files directory. mkdir is the atomic primitive
    # available on that share.
    local stamp="$VENV/.requirements.sha256"
    local want
    want="$(sha256sum "$APP_PYTHON/requirements.txt" | cut -d' ' -f1)"

    if [ ! -x "$VENV/bin/python" ] || [ "$(cat "$stamp" 2>/dev/null || true)" != "$want" ]; then
        mkdir -p "$(dirname "$VENV")"
        if mkdir "$VENV.lock" 2>/dev/null; then
            trap 'rmdir "$VENV.lock" 2>/dev/null || true' EXIT
            log "building the venv at $VENV"
            "$PYTHON_BIN" -m venv "$VENV" >>"$LOG" 2>&1 || { log "ERROR: venv creation failed"; return 1; }
            "$VENV/bin/pip" install --no-cache-dir --disable-pip-version-check \
                -r "$APP_PYTHON/requirements.txt" >>"$LOG" 2>&1 \
                || { log "ERROR: pip install failed; see $LOG"; return 1; }
            echo "$want" > "$stamp"
            rmdir "$VENV.lock" 2>/dev/null || true
            trap - EXIT
            log "venv ready"
        else
            log "another instance is building the venv; waiting"
            for _ in $(seq 1 60); do
                sleep 5
                [ -x "$VENV/bin/python" ] && [ "$(cat "$stamp" 2>/dev/null || true)" = "$want" ] && break
            done
            if [ ! -x "$VENV/bin/python" ]; then
                log "ERROR: timed out waiting for the venv"
                return 1
            fi
        fi
    else
        log "venv is current"
    fi

    # Gunicorn's master supervises its workers; nothing supervises the master.
    # An OOM kill or a bad deploy takes it out and the finder is simply gone
    # until the next restart, which on an App Service can be days. Hence the
    # loop -- backed off, so a launch that fails instantly does not spin.
    (
        delay=2
        while true; do
            log "starting gunicorn on $BIND ($WORKERS workers)"
            "$VENV/bin/gunicorn" \
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
