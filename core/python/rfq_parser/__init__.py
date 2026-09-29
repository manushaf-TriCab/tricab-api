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

**What is not.** Stores, access control, mailbox intake, the Foundry agent and
the 4js push. The platform API owns persistence, permissions, reader jobs and
the quote handoff; mail arrives with Phase 6, and the agent is not ported
(see ``_unported``). Learned corrections come in with each request
(``corrections``) because their table is the API's.

**Where the code comes from.** Every module except this one, ``corrections``,
``_unported``, ``app`` and ``contract`` is generated from the pinned source by
``tools/sync_rfq_parser.py`` and says so in its first line. Change the source or
the tool's declared patches, never a generated file; the tool's check fails if
either side moves on its own. At cutover the source stops being the authority
and the tool is deleted.
"""
