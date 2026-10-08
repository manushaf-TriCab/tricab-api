"""Gunicorn entry point for the finance AI service: `gunicorn --chdir <this dir> finance_wsgi:application`.

A third process beside the product finder (`wsgi.py`) and the RFQ parser
(`rfq_wsgi.py`), on its own loopback port (8003), so a slow model call here never holds up
either of them.
"""

from __future__ import annotations

from finance_ai import purposes
from finance_ai.app import app as application

# Each app's purposes (Tri-Task's five). Not done in `finance_ai.app`, so the service's own tests keep
# an empty registry.
purposes.register_all()
# Tri-AR (Phase 5): classification, prose, reply drafts and remittance extraction.
purposes.register_ar()

__all__ = ["application"]
