"""Conductor process engine: how copper conductor is made on the conductor section of the factory map.

Stdlib only; reuses the Cable routes loader and explosion. `python -m conductor_process build` reads the
extract folder and writes a SQLite store (schema.sql) that the API serves read-only.
See docs/conductor/CONTRACT.md.
"""

__version__ = "1.0.0"
SCHEMA_VERSION = 1
