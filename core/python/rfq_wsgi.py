"""Gunicorn entry point for the RFQ parser: `gunicorn --chdir <this dir> rfq_wsgi:application`.

A separate process from the product finder (`wsgi.py`), on its own port, so a
long schedule on one never holds up a request on the other. The catalogue loads
on the first request that needs it, as in the finder, unless `startup.sh` runs
gunicorn with `--preload` and `TRICAB_WSGI_WARM=1`, in which case the master loads
it once before forking and the workers share it copy-on-write (a partial saving:
refcounts dirty the pages). The module-level state is a lazy catalogue, a
`CostApiClient` whose HTTP connection is created per thread on first use, and
`ThreadPoolExecutor`s that live only inside a call: no socket, thread or
connection exists at import, so a fork is safe.
"""

from __future__ import annotations

import logging
import os

from rfq_parser.app import app as application

if os.environ.get("TRICAB_WSGI_WARM") == "1":
    try:
        from rfq_parser import pipeline

        pipeline.catalog._resolve()  # noqa: SLF001 - load the lazy catalogue in the master
    except Exception:  # noqa: BLE001 - a failed warm-up must not stop the master
        logging.getLogger(__name__).exception("catalogue warm-up failed; workers will load lazily")

__all__ = ["application"]
