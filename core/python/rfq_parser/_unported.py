"""What the source does that is not brought across: the Foundry agent, and the mailbox half of `mail_ingest`.

**The mailbox half of `matcher/mail_ingest.py`** (RFQ mail intake plan M2.2). The reading of
one email is generated into `mail_ingest` (and `reader_intake`); these parts of the source file
are not, each for a reason:

- `process_message` / `process_mailbox`: the orchestration. It lists the mailbox, calls the
  customer and operator lookups, saves drafts, replies, pushes to 4js, marks the message read and
  writes the audit row. The platform's mail reader (M3, PHP) owns the mailbox and the stores; the
  parse it needs is `POST /v1/parse` with `email`.
- the internetMessageId **dedupe** (`audit.already_processed`): a store lookup (`MailAudit`). The
  key is `mail_envelope.dedupe_key`; the store is `rfq_mail_audit` / `[mail]` (M3.3).
- **auto-replies, bounces, bulk and calendar mail**: the source skips none of them (only
  `is_own_mail`'s three guards, which are generated). The pre-filter is M3.4, on headers the
  envelope does not carry.
- `find_quote_attachments`, `quote_request`, ALIFLEX / REPRICE by subject keyword: the subject-driven
  import of a TriCab quote. `/v1/parse` reads a TriCab quote attachment as the upload does
  (`quote_import`) and PHP offers ALIFLEX as a button; the keyword flow is not reproduced.
- the optional model read of the body (`RFQ_READER_TEXT_ENABLED`, `attachment_reader.read_text`):
  a model read is a reader job here (`/v1/read`), not part of a parse.
- directive capture (`directives`), reply and review-PDF builders, customer resolution
  (`matcher.db`): the API's, or the quote handoff's.

**The Foundry agent.** The one translator the source runs that is not brought across.

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
