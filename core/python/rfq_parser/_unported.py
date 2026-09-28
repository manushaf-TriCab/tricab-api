"""The two translators the source runs only for email, not yet brought across.

``pipeline.match_items`` chooses between three AI translators: the terra
translator (``reader_normalizer``), the Foundry agent (``agent``) and the
OpenRouter normalizer. The first two run only when a ``message_context`` is
passed — an email, or a portal upload re-read by the reader worker — and they
belong to mailbox intake and the attachment reader, which arrive with plan
Phases 3.5 and 6.

Until then these stand-ins answer "not enabled", which is exactly what the source
does when their settings are off, so ``match_items`` stays byte-for-byte the
source's code and simply never takes those branches. When the real modules are
ported, delete this file and the pipeline patch that imports it; nothing else
changes.
"""

from __future__ import annotations


class _NotPorted:
    def __init__(self, name: str) -> None:
        self._name = name
        self._last_run: dict = {}

    def agent_enabled(self) -> bool:
        return False

    def translator_enabled(self) -> bool:
        return False

    def degraded_reason(self) -> str | None:
        return None

    def last_run(self) -> dict:
        return dict(self._last_run)

    def normalize_items(self, *_args, **_kwargs):
        raise RuntimeError(f"rfq_parser: {self._name} is not ported yet (plan Phase 3.5 / 6).")


agent = _NotPorted("agent")
reader_normalizer = _NotPorted("reader_normalizer")
