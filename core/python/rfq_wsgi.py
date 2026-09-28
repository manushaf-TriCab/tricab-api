"""Gunicorn entry point for the RFQ parser: `gunicorn --chdir <this dir> rfq_wsgi:application`.

A separate process from the product finder (`wsgi.py`), on its own port, so a
long schedule on one never holds up a request on the other. The catalogue loads
on the first request that needs it, as in the finder.
"""

from __future__ import annotations

from rfq_parser.app import app as application

__all__ = ["application"]
