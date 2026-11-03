"""Quotegen file inspector: look at original RFQ files, structure and visuals.

Pure functions returning JSON-serialisable dicts. Every result is bounded; when
something is cut off the result says so (`truncated`, `next_cursor`) and anything
that could not be read is listed in `unreadable`, never silently dropped.
"""

__version__ = "0.1.0"
