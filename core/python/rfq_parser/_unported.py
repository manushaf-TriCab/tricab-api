"""The one translator the source runs that is not brought across: the Foundry agent.

``pipeline.match_items`` chooses between three AI translators: the terra
translator (``reader_normalizer``), the Foundry agent (``agent``) and the
OpenRouter normalizer. The terra translator came across with the reader (plan
3.5) because production runs it. The agent did not: production has
``RFQ_AGENT_ENABLED`` off, and it loses to the translator whenever both are on
(``pipeline.match_items``), so porting it would add a second provider SDK
(``anthropic``) and a Foundry credential for a path nothing takes.

Until someone decides to switch it on, this stand-in answers "not enabled",
which is exactly what the source does when its settings are off, so
``match_items`` stays byte-for-byte the source's code and never takes that
branch. If it is ever wanted, port ``matcher/agent.py`` with the sync tool,
delete this file and drop it from the pipeline patch that imports it.
"""

from __future__ import annotations


class _NotPorted:
    def __init__(self, name: str) -> None:
        self._name = name
        self._last_run: dict = {}

    def agent_enabled(self) -> bool:
        return False

    def degraded_reason(self) -> str | None:
        return None

    def last_run(self) -> dict:
        return dict(self._last_run)

    def normalize_items(self, *_args, **_kwargs):
        raise RuntimeError(f"rfq_parser: {self._name} is not ported (production runs it off).")


agent = _NotPorted("agent")
