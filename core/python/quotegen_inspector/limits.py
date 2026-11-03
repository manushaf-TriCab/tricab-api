"""Central limits. Override with QG_* environment variables."""
import os


def _int(name, default):
    try:
        return int(os.environ.get(name, default))
    except ValueError:
        return default


MAX_FILE_BYTES = _int("QG_MAX_FILE_BYTES", 50 * 1024 * 1024)
MAX_NESTED_DEPTH = _int("QG_MAX_NESTED_DEPTH", 5)
MAX_DECOMPRESSION_RATIO = _int("QG_MAX_DECOMPRESSION_RATIO", 100)
MAX_UNCOMPRESSED_BYTES = _int("QG_MAX_UNCOMPRESSED_BYTES", 500 * 1024 * 1024)
MAX_PAGES_RENDERED = _int("QG_MAX_PAGES_RENDERED", 5)
MAX_CELLS_PER_READ = _int("QG_MAX_CELLS_PER_READ", 2000)
MAX_TEXT_CHARS = _int("QG_MAX_TEXT_CHARS", 20000)
MAX_ITEMS = _int("QG_MAX_ITEMS", 200)
MAX_RENDER_PIXELS = _int("QG_MAX_RENDER_PIXELS", 4000)


class LimitError(ValueError):
    """A configured limit was exceeded; the file is refused, not truncated."""


def page(items, cursor, size=None):
    """Slice `items` from an integer cursor; return (slice, truncated, next_cursor)."""
    size = size or MAX_ITEMS
    start = int(cursor or 0)
    chunk = items[start:start + size]
    end = start + len(chunk)
    more = end < len(items)
    return chunk, more, (str(end) if more else None)


def clip(text, limit=None):
    """Clip text, returning (text, truncated)."""
    limit = limit or MAX_TEXT_CHARS
    if text is None:
        return "", False
    return (text[:limit], True) if len(text) > limit else (text, False)
