"""Conductor schedule engine: restock (which finished conductors to make) and, later, the finite-capacity schedule.

Stdlib only; reuses `conductor_process` for the build tree. `python -m conductor_schedule build` reads the source folder
and writes a SQLite store (schema.sql) that the API serves read-only. See docs/conductor/SCHEDULE-CONTRACT.md.
"""

__version__ = "1.0.0"
SCHEMA_VERSION = 1
