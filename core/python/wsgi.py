"""Gunicorn entry point: `gunicorn --chdir <this dir> wsgi:application`.

Importing this builds the catalogue on the first request rather than at import,
because `rules._load()` is lazy. That is deliberate for a preload-less gunicorn:
a worker that dies mid-load restarts without taking the master with it.
"""

from __future__ import annotations

from product_finder.app import app as application

__all__ = ["application"]
