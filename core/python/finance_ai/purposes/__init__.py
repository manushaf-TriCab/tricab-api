"""The purposes each finance app registers with `finance_ai`.

Not imported by `finance_ai.app`, so the service's own tests keep an empty registry and register
a fake. `finance_wsgi.py` calls `register_all()` once when the service starts.
"""

from __future__ import annotations


def register_all() -> list[str]:
    """Registers every app's purposes (idempotent). Returns the names added."""
    from . import task

    return task.register_all()


def register_ar() -> list[str]:
    """Registers Tri-AR's four purposes (idempotent). Returns the names added.

    Separate from `register_all()` (Tri-Task's five) so the Tri-Task tests, which assert exactly their own names, are
    unchanged. `finance_wsgi.py` calls both when the service starts.
    """
    from . import ar

    return ar.register_all()
