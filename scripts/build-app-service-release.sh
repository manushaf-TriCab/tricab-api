#!/usr/bin/env bash
#
# Build the combined frontend/API App Service package on macOS or Linux.
#
# This is a port of build-app-service-release.ps1 and produces the same
# artifact layout: the Vite output at the archive root, the Slim application
# under app/, and the Azure front controller under api/.
#
# Usage:
#   scripts/build-app-service-release.sh [--web-origin URL] [--skip-npm-ci]

set -euo pipefail

WEB_ORIGIN='https://tc-web-core-e7b7gdbjgadjd3ff.australiasoutheast-01.azurewebsites.net'
SKIP_NPM_CI=0

# The finder's Python dependencies are cross-compiled for the App Service image,
# not for this build machine. Keep these in step with the image's interpreter --
# `startup.log` records it as "using /usr/bin/python3 (Python 3.11.2)".
PY_VERSION='3.11'
PY_PLATFORM='manylinux2014_x86_64'

while [ $# -gt 0 ]; do
    case "$1" in
        --web-origin)
            [ $# -ge 2 ] || { echo "--web-origin needs a value" >&2; exit 1; }
            WEB_ORIGIN="$2"
            shift 2
            ;;
        --skip-npm-ci)
            SKIP_NPM_CI=1
            shift
            ;;
        *)
            echo "Unknown argument: $1" >&2
            exit 1
            ;;
    esac
done

if ! printf '%s' "$WEB_ORIGIN" | grep -Eq '^https://[^/]+$'; then
    echo "Web origin must look like https://host with no trailing path: $WEB_ORIGIN" >&2
    exit 1
fi

script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
api_root="$(cd "$script_dir/.." && pwd)"
workspace_root="$(cd "$api_root/.." && pwd)"
web_root="$workspace_root/tricab-web"
artifacts_root="$api_root/artifacts/app-service"
release_root="$artifacts_root/release"
zip_path="$artifacts_root/tricab-app-service.zip"

# Keep destructive work inside the API repository's artifacts directory.
case "$artifacts_root" in
    "$api_root"/artifacts/*) ;;
    *)
        echo "Artifact path must stay inside the API repository's artifacts directory: $artifacts_root" >&2
        exit 1
        ;;
esac

# Refuse to clean or ship a tree containing symlinks. The local checkout
# symlinks core/ into vendor/, and that link must never reach the release.
assert_no_symlinks() {
    local root="$1"
    local found
    found="$(find "$root" -type l -print -quit 2>/dev/null || true)"
    if [ -n "$found" ]; then
        echo "Refusing to continue: unexpected symlink under $root: $found" >&2
        exit 1
    fi
}

for required in \
    "$api_root/composer.json" \
    "$api_root/composer.lock" \
    "$api_root/core/composer.json" \
    "$web_root/package.json" \
    "$web_root/package-lock.json" \
    "$api_root/deploy/app-service/default" \
    "$api_root/deploy/app-service/startup.sh" \
    "$api_root/deploy/app-service/api/index.php" \
    "$api_root/core/python/requirements.txt" \
    "$api_root/core/python/wsgi.py"
do
    if [ ! -f "$required" ]; then
        echo "Required release input is missing: $required" >&2
        exit 1
    fi
done

# The release vendors the finder's wheels, so the build needs pip even though
# nothing Python runs here.
if ! python3 -m pip --version >/dev/null 2>&1; then
    echo "python3 -m pip is required to vendor the finder's dependencies." >&2
    exit 1
fi

if [ -e "$artifacts_root" ]; then
    if [ ! -d "$artifacts_root" ] || [ -L "$artifacts_root" ]; then
        echo "Artifact root must be a real directory: $artifacts_root" >&2
        exit 1
    fi
    assert_no_symlinks "$artifacts_root"
    rm -rf "$artifacts_root"
fi

mkdir -p "$release_root"

echo "==> Building the frontend against $WEB_ORIGIN"
(
    cd "$web_root"
    export VITE_API_BASE_URL="$WEB_ORIGIN"
    export VITE_ENTRA_REDIRECT_URI="$WEB_ORIGIN"
    if [ "$SKIP_NPM_CI" -eq 0 ]; then
        npm ci
    fi
    npm run build
)

if [ ! -f "$web_root/dist/index.html" ]; then
    echo "Vite output is missing index.html: $web_root/dist" >&2
    exit 1
fi

cp -R "$web_root/dist/." "$release_root/"

app_root="$release_root/app"
mkdir -p "$app_root"

echo "==> Staging the API"
for directory in src resources PROMPTS core; do
    if [ ! -d "$api_root/$directory" ]; then
        echo "Required API directory is missing: $api_root/$directory" >&2
        exit 1
    fi
    cp -R "$api_root/$directory" "$app_root/$directory"
done

for file in composer.json composer.lock; do
    cp "$api_root/$file" "$app_root/$file"
done

for directory in var/cache/app var/cache/apple-jwks var/cache/entra-jwks var/cache/google-jwks var/log; do
    mkdir -p "$app_root/$directory"
done

# The core path repository is symlinked in local development so core/src is
# never stale. The release must be self-contained instead: force Composer to
# mirror it, because the staged core directory is deleted further down.
echo "==> Installing PHP dependencies"
(
    cd "$app_root"
    COMPOSER_MIRROR_PATH_REPOS=1 composer install \
        --no-dev \
        --prefer-dist \
        --optimize-autoloader \
        --classmap-authoritative \
        --no-interaction
)

vendor_core="$app_root/vendor/tricab/tricab-core"
if [ -L "$vendor_core" ]; then
    echo "Release vendor Core must be mirrored, not linked: $vendor_core" >&2
    exit 1
fi
if [ ! -d "$vendor_core" ]; then
    echo "Release vendor Core is missing: $vendor_core" >&2
    exit 1
fi

# The product finder's Python. `app/core` is deleted below because Composer has
# already mirrored it into vendor/tricab/tricab-core, so Python left under
# core/ would go with it -- it would survive only in vendor, at a path nothing
# should depend on. Stage it as `app/python` instead: a stable location for the
# gunicorn --chdir, and outside the vendor tree.
staged_core="$app_root/core"
source_python="$staged_core/python"
if [ ! -d "$source_python" ]; then
    echo "Required API directory is missing: $source_python" >&2
    exit 1
fi

# A virtualenv under core/python is hundreds of megabytes and Composer's path
# copy is wholesale, so it would already be in vendor by now. Fail rather than
# ship it.
for unwanted in .venv venv .pytest_cache; do
    stray="$(find "$source_python" -type d -name "$unwanted" -print -quit 2>/dev/null || true)"
    if [ -n "$stray" ]; then
        echo "core/python must not contain a $unwanted directory: $stray" >&2
        exit 1
    fi
done

app_python="$app_root/python"
cp -R "$source_python" "$app_python"
find "$app_python" -type d \( -name __pycache__ -o -name .pytest_cache \) -prune -exec rm -rf {} + 2>/dev/null || true

# `tests/` and `tools/` are development surface, not runtime.
for dev_only in tests tools; do
    rm -rf "$app_python/$dev_only"
done

# The finder's Python dependencies, vendored into the release.
#
# The App Service cannot build a virtualenv for itself: the Linux PHP image
# ships python3 (3.11) but not Debian's python3-venv, so `python3 -m venv`
# fails with "ensurepip is not available" and gunicorn never starts. Rather
# than mutate the container with apt at every cold start, or download
# get-pip.py over the internet on boot, resolve the wheels here and ship them.
#
# Cross-compiled deliberately: this runs on a developer's macOS machine but the
# target is the image's CPython 3.11 on x86_64 Linux, so the platform triple is
# pinned and --only-binary is required for pip to honour it. MarkupSafe is the
# one dependency with a native extension; the rest are pure Python.
echo "==> Vendoring the finder's Python dependencies for $PY_PLATFORM / cp$PY_VERSION"
vendor_python="$app_python/_vendor"
python3 -m pip install \
    --quiet \
    --target "$vendor_python" \
    --requirement "$api_root/core/python/requirements.txt" \
    --platform "$PY_PLATFORM" \
    --python-version "$PY_VERSION" \
    --implementation cp \
    --only-binary=:all:

if [ ! -d "$vendor_python/gunicorn" ]; then
    echo "Vendored dependencies are missing gunicorn: $vendor_python" >&2
    exit 1
fi

# Console scripts carry this build machine's shebang, so they would point at a
# python that does not exist on the instance. startup.sh runs `python3 -m
# gunicorn` against PYTHONPATH instead; drop the scripts so nothing is tempted.
rm -rf "$vendor_python/bin"

# A wheel built for the wrong platform is the failure this step exists to
# prevent, and it is silent until import time on the instance.
if find "$vendor_python" -name '*.so' | grep -qv 'x86_64-linux-gnu'; then
    echo "Vendored tree contains a native extension that is not x86_64 Linux:" >&2
    find "$vendor_python" -name '*.so' | grep -v 'x86_64-linux-gnu' >&2
    exit 1
fi

# requirements.txt is unpinned, so record what this build actually resolved.
# The release is the only place those versions are fixed.
find "$vendor_python" -maxdepth 1 -name '*.dist-info' -exec basename {} .dist-info \; \
    | sort > "$vendor_python/VENDORED.txt"
echo "    vendored $(wc -l < "$vendor_python/VENDORED.txt" | tr -d ' ') distributions"

# Composer mirrored core/ wholesale, Python included. Drop that second copy:
# two copies of the selection rules on one disk is the exact thing
# tricab_cable_rules exists to prevent, even when one of them is unreachable.
rm -rf "$vendor_core/python"

assert_no_symlinks "$staged_core"
rm -rf "$staged_core"

echo "==> Staging the Azure front controller"
api_public_root="$release_root/api"
mkdir -p "$api_public_root"
cp "$api_root/deploy/app-service/api/index.php" "$api_public_root/index.php"
cp "$api_root/deploy/app-service/default" "$release_root/default"
cp "$api_root/deploy/app-service/startup.sh" "$release_root/startup.sh"

# Nothing in the archive may be a symlink -- App Service unpacks it verbatim.
assert_no_symlinks "$release_root"

echo "==> Packaging"
rm -f "$zip_path"
(
    cd "$release_root"
    zip -r -q -X "$zip_path" . -x '.DS_Store' '*/.DS_Store'
)

zip_bytes="$(wc -c < "$zip_path" | tr -d ' ')"
zip_mib="$(awk -v b="$zip_bytes" 'BEGIN { printf "%.2f", b / 1048576 }')"

echo
echo "Release directory: $release_root"
echo "Release ZIP: $zip_path"
echo "Release ZIP size: $zip_mib MiB"
echo "Frontend origin: $WEB_ORIGIN"
