"""The RFQ parser: customer text and files in, matched and priced quote lines out.

Plan Phase 3 (`platform/RFQ-IMPLEMENTATION-PLAN.md`). This is the production
parser's extraction, matching and pricing, moved into core beside the product
finder and importing the same ``tricab_cable_rules``, so the two cannot drift.

**What is here.** ``file_parser`` reads spreadsheets, Word, PDF text, CSV and
text into work items; ``pipeline.match_items`` turns work items into matched
rows and prices them through ``cost_api``; ``quote_readiness`` says what still
blocks a quote. ``reader_intake.read_one_file`` is the source's one way of
reading a file: the terra reader (``attachment_reader``, re-checked by
``attachment_validate``) for spreadsheets, PDFs (scanned ones from the page
image), documents and images, with ``vision_ocr`` behind it for images and
``parse_file`` when no reader is on. The AI paths (``llm_fallback``,
``normalizer``, ``table_shape``, ``reader_normalizer``, the reader and OCR)
behave as in the source: off unless their settings are present.

**Email.** ``mail_envelope`` is the ``rfq-mail-envelope.v1`` contract (validator,
derived text and tables, the forwarded chain, the dedupe key); ``mail_ingest`` is the
source's mail-reading half (HTML to text, tables, the original sender, the inline-image
filter, the loop guards); ``mail_parse`` reads an envelope's body into work items and
merges an email's lines. ``POST /v1/parse`` takes the envelope as ``email``
(``contract``).

**What is not.** Stores, access control, the Graph client and mailbox polling, replies,
the Foundry agent and the 4js push. The platform API owns persistence, permissions,
reader jobs, the mailbox and the quote handoff; the agent is not ported
(see ``_unported``). Learned corrections come in with each request
(``corrections``) because their table is the API's.

**Where the code comes from.** Every module except this one, ``corrections``,
``_unported``, ``app``, ``contract``, ``mail_envelope`` and ``mail_parse`` (and the other
platform-owned modules named in ``tests/test_rfq_parser_sync.py``) is generated from the pinned source by
``tools/sync_rfq_parser.py`` and says so in its first line. Change the source or
the tool's declared patches, never a generated file; the tool's check fails if
either side moves on its own. At cutover the source stops being the authority
and the tool is deleted.
"""
