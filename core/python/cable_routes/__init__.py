"""Cable routes engine: how each finished TriCab cable is made, rebuilt from a D365 extract.

Stdlib only. `python -m cable_routes build` reads the extract folder and writes a SQLite
store (schema.sql) that the API serves read-only. See docs/cable-routes/CONTRACT.md.
"""

__version__ = "1.0.0"
SCHEMA_VERSION = 1
