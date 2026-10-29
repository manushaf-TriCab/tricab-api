#!/bin/sh
# Local dev server. Forces the Microsoft ODBC driver onto OpenSSL 3: Homebrew's default
# libssl.dylib is now OpenSSL 4, which msodbcsql18 cannot load. Certificate validation stays on.
OPENSSL3="$(brew --prefix openssl@3 2>/dev/null)/lib"
export DYLD_LIBRARY_PATH="$OPENSSL3${DYLD_LIBRARY_PATH:+:$DYLD_LIBRARY_PATH}"
cd "$(dirname "$0")/.." && exec php -S "${1:-localhost:8080}" -t public public/router.php
