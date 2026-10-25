"""Gunicorn entry point: `gunicorn --chdir <this dir> wsgi:application`.

Importing this builds the catalogue on the first request rather than at import,
because `rules._load()` is lazy. That stays the default, so a worker that dies
mid-load restarts without taking the master with it.

`startup.sh` runs gunicorn with `--preload` and `TRICAB_WSGI_WARM=1`, which loads
the catalogue once in the master before it forks, so the workers share those
pages copy-on-write instead of each building ~150 MB of its own. The saving is
partial: refcount updates dirty shared pages over time. Nothing at import opens a
socket, thread or DB connection (the DB connection is per request, in
`product_finder.db`), so sharing the imported state across fork is safe.
"""

from __future__ import annotations

import logging
import os

from product_finder.app import app as application

if os.environ.get("TRICAB_WSGI_WARM") == "1":
    try:
        from product_finder import rules

        rules.status()  # builds and caches the catalogue; failure is recorded, not raised
    except Exception:  # noqa: BLE001 - a failed warm-up must not stop the master
        logging.getLogger(__name__).exception("catalogue warm-up failed; workers will load lazily")

__all__ = ["application"]
