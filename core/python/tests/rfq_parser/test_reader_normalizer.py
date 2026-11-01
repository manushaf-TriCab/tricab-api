# Generated from the RFQ parser's tests/test_reader_normalizer.py by tools/sync_rfq_parser.py.
# Change the source or the tool's declared patches, never this file.
"""The translator (reader_normalizer): the reader's model doing the normalizer's job,
and its wiring in `match_items`.

The transport is mocked throughout -- these assert what the module does with a response,
not that Azure OpenAI works.

Two claims matter:

1. `reader_normalizer.normalize_items` speaks the normalizer's contract on the reader's
   wire: same row envelope (`batch_rows`), same `_row_from_payload` parser, and it records
   why it failed on `last_run` so the pipeline can stamp the draft degraded rather than
   shipping a silently regex-parsed one.
2. `match_items` routes reader-sourced rows straight to the translator, bypassing the
   deterministic regex trial gate. The row is absent from `trial_results` on purpose: the
   reader already structured it, so the regex extractor's verdict must not decide whether
   it reaches the AI. If the translator fails, the assembly loop runs the regex path fresh
   -- the degraded fallback, not a gate in front of the AI.
"""

from __future__ import annotations

import json

import pytest

from rfq_parser import pipeline
from rfq_parser import reader_normalizer as rn
from rfq_parser.normalizer import NormalizedRow


@pytest.fixture(autouse=True)
def configured(monkeypatch):
    monkeypatch.setenv("RFQ_READER_ENABLED", "true")
    monkeypatch.setenv("RFQ_READER_NORMALIZER_ENABLED", "true")
    monkeypatch.setenv("RFQ_READER_ENDPOINT", "https://example.openai.azure.com")
    monkeypatch.setenv("RFQ_READER_API_KEY", "test-key")
    monkeypatch.setenv("RFQ_READER_MODEL", "gpt-test")
    monkeypatch.delenv("OPENROUTER_API_KEY", raising=False)
    monkeypatch.delenv("RFQ_NORMALIZER_ENABLED", raising=False)
    monkeypatch.delenv("RFQ_AGENT_ENABLED", raising=False)
    rn.reset_run()


def _entry(**kwargs):
    """A valid payload entry for `_request_schema` / `_row_from_payload`."""
    base = {
        "reasoning": "one 16mm2 four-core copper cable",
        "description": "4C 16mm2 Cu",
        "quantity": None,
        "number_of_lengths": None,
        "length_each": None,
        "unit": None,
        "circuit_label": None,
        "core_count": 4,
        "core_type": "C",
        "cross_section_mm2": 16,
        "conductor": "Cu",
        "has_earth_core": False,
        "earth_multiplicity": None,
        "is_earth_only": False,
        "cable_type_hint": None,
        "screen_requested": False,
        "armoured_requested": False,
        "fire_rated_requested": False,
        "recognized_standard": None,
        "standard_sheath": None,
        "style_series_code": None,
        "confidence": 0.97,
        "notes": "",
    }
    base.update(kwargs)
    return base


def _response(row_indexes=(0,)):
    rows = [
        {"index": index, "entries": [_entry()]}
        for index in row_indexes
    ]
    return {
        "status": "completed",
        "model": "gpt-test",
        "usage": {"input_tokens": 10, "output_tokens": 20, "total_tokens": 30},
        "output": [
            {
                "type": "message",
                "content": [
                    {"type": "output_text",
                     "text": json.dumps({"rows": rows})}
                ],
            }
        ],
    }


@pytest.fixture
def stub(monkeypatch):
    state = {"payload": None, "response": _response()}

    def fake_post(path, payload, timeout):
        state["payload"] = payload
        response = state["response"]
        if isinstance(response, Exception):
            raise response
        if response == "echo_each_chunk":
            # Answer every row the payload asked for, on whatever chunk it lands in.
            rows = chunk_rows(payload)
            return _response(tuple(row["index"] for row in rows))
        return response

    monkeypatch.setattr(rn, "_post", fake_post)
    return state


def chunk_rows(payload):
    return json.loads(payload["input"][0]["content"][0]["text"])["rows"]


# --- the module itself --------------------------------------------------------------

def test_translator_returns_normalized_rows(stub):
    out = rn.normalize_items([{"text": "4C 16mm2 Cu", "source": {"input_type": "attachment_reader"}}])
    assert isinstance(out.get(0), NormalizedRow)
    assert out[0].entries[0].fields["count"] == 4
    assert out[0].entries[0].fields["cross_section"] == "16"
    assert out[0].entries[0].fields["conductor"] == "N"
    # The compliance record: everything translated, nothing failed, no degraded reason.
    assert rn.last_run()["ok"] is True
    assert rn.last_run()["chunks_failed"] == 0
    assert rn.degraded_reason() is None


def test_translator_sends_the_normalizers_row_envelope(stub):
    rn.normalize_items([{"text": "4C 16mm2 Cu", "context": ["Controls"]}])
    rows = json.loads(stub["payload"]["input"][0]["content"][0]["text"])["rows"]
    assert rows == [{"index": 0, "text": "4C 16mm2 Cu", "current_section": "Controls", "context": ["Controls"]}]


def test_an_empty_answer_is_a_failure_not_an_absence_of_cables(monkeypatch, stub):
    stub["response"] = {
        "status": "completed",
        "model": "gpt-test",
        "usage": {"total_tokens": 1},
        "output": [{"type": "message", "content": [{"type": "output_text", "text": ""}]}],
    }
    out = rn.normalize_items([{"text": "4C 16mm2 Cu"}])
    assert out == {}
    assert rn.last_run()["ok"] is False
    assert rn.degraded_reason() is not None


def test_translator_disabled_returns_empty(stub):
    import os
    os.environ["RFQ_READER_NORMALIZER_ENABLED"] = "false"
    rn.reset_run()
    out = rn.normalize_items([{"text": "4C 16mm2 Cu"}])
    assert out == {}
    assert rn.degraded_reason() is None  # disabled is not a failure


# --- the pipeline wiring -------------------------------------------------------------

def _reader_item(text, **source):
    base = {"input_type": "attachment_reader"}
    base.update(source)
    return {"text": text, "context": [], "metadata": {}, "source": base}


def test_reader_rows_go_straight_to_the_translator(monkeypatch, stub):
    """A reader-sourced row must reach the translator even though the regex parser would
    resolve it confidently (a plain `4C 16mm2 Cu XLPE/PVC SWA 0.6/1kV` line does). The
    regex verdict does not get a vote on whether it is sent to the AI."""
    stub["response"] = "echo_each_chunk"
    items = [
        _reader_item("4C 16mm2 Cu XLPE/PVC SWA 0.6/1kV, 250m"),
        _reader_item("2C 1.5mm2 PVC/PVC, 500m", input_type="text_reader"),
    ]
    results = pipeline.match_items(items, message_context={"subject": "RFQ"})
    assert results
    # Every reader row that asked for a length came through as a matched cable using the
    # translator's field overrides.
    for result in results:
        assert result["status"] in ("matched", "needs_clarification")
    assert rn.last_run()["ran"] is True
    assert rn.last_run()["rows"] == 2


def test_reader_bypass_keeps_the_row_out_of_trial_results():
    """The seam: reader rows are added straight to ai_indexes and never get a regex trial.
    If the translator then fails, the fallback must be `match_source_line` run fresh -- not
    the reactive OpenRouter fallback, which would silently substitute a different provider
    for a Terra reading."""
    assert "attachment_reader" in pipeline.READER_INPUT_TYPES
    assert "text_reader" in pipeline.READER_INPUT_TYPES
    assert "reader_ungrounded" in pipeline.READER_INPUT_TYPES
    assert pipeline._reader_sourced({"input_type": "attachment_reader"}) is True
    assert pipeline._reader_sourced({"input_type": "email_html_table"}) is False

# --- the claim the whole module rests on ---------------------------------------------

def test_the_prompt_and_schema_are_the_normalizers_byte_for_byte(stub):
    """The reason a later measurement can say "terra against gemini" and mean it.

    If this path ever grows its own copy of the prompt or the schema, a comparison across
    the two reports a prompt difference as a model difference -- READER_STABILISATION
    §3/§5.1, arrived at the hard way on `23-Allison`.
    """
    from rfq_parser import normalizer

    rn.normalize_items([{"text": "4C 16mm2 Cu"}])
    payload = stub["payload"]
    assert payload["instructions"] == normalizer._SYSTEM_PROMPT
    fmt = payload["text"]["format"]
    assert fmt["strict"] is True
    assert fmt["schema"] == normalizer._request_schema()


def test_no_reasoning_block_is_sent_unless_asked_for(monkeypatch, stub):
    """The confirmed-working request shape carries no `reasoning` field. Sending one the
    deployment has not been asked for turns a working call into a 400, unattended."""
    rn.normalize_items([{"text": "4C 16mm2 Cu"}])
    assert "reasoning" not in stub["payload"]

    monkeypatch.setenv("RFQ_READER_NORMALIZER_EFFORT", "high")
    rn.normalize_items([{"text": "4C 16mm2 Cu"}])
    assert stub["payload"]["reasoning"] == {"effort": "high"}


def test_an_index_the_model_invented_is_dropped(stub):
    """Guessing which row was meant attaches a translation to the wrong line."""
    stub["response"] = _response(row_indexes=(7,))
    out = rn.normalize_items([{"text": "4C 16mm2 Cu"}])
    assert out == {}
    assert rn.last_run()["ok"] is False


# --- retry, and what must NOT be retried ---------------------------------------------

def test_an_unusable_response_halves_the_batch(monkeypatch, stub):
    """A chunk size that works on most files still overflows on one with longer
    descriptions. Halving is what stops a whole sheet coming back untranslated."""
    calls = []

    def fake_post(path, payload, timeout):
        rows = chunk_rows(payload)
        calls.append(len(rows))
        if len(rows) > 1:
            return {"status": "incomplete", "incomplete_details": {"reason": "max_output_tokens"},
                    "usage": {"total_tokens": 5}, "output": []}
        return _response(tuple(row["index"] for row in rows))

    monkeypatch.setattr(rn, "_post", fake_post)
    out = rn.normalize_items([{"text": "4C 16mm2 Cu"}, {"text": "2C 1.5mm2 Cu"}])
    assert sorted(out) == [0, 1], "both halves must come back"
    assert calls == [2, 1, 1]


def test_a_transport_failure_is_not_retried_on_smaller_batches(monkeypatch, stub):
    """A 401 or a timeout fails identically on both halves and costs twice to learn it."""
    calls = []

    def fake_post(path, payload, timeout):
        calls.append(len(chunk_rows(payload)))
        raise rn.ReaderError("Azure OpenAI HTTP 401: unauthorized")

    monkeypatch.setattr(rn, "_post", fake_post)
    out = rn.normalize_items([{"text": "4C 16mm2 Cu"}, {"text": "2C 1.5mm2 Cu"}])
    assert out == {}
    assert calls == [2], "one attempt, not three"
    assert "401" in rn.degraded_reason()


# --- the ceilings ---------------------------------------------------------------------

def test_the_token_ceiling_stops_the_next_chunk_and_says_so(monkeypatch, stub):
    monkeypatch.setenv("RFQ_READER_NORMALIZER_MAX_ITEMS", "1")
    monkeypatch.setenv("RFQ_READER_NORMALIZER_MAX_TOKENS", "10")
    stub["response"] = "echo_each_chunk"  # 30 tokens per call, so chunk 2 is over
    out = rn.normalize_items([{"text": "4C 16mm2 Cu"}, {"text": "2C 1.5mm2 Cu"}])
    assert sorted(out) == [0], "the second chunk must not run"
    assert rn.last_run()["chunks_failed"] == 1
    reason = rn.degraded_reason()
    assert "token ceiling" in reason, reason


def test_the_llm_budget_stops_the_run(monkeypatch, stub):
    monkeypatch.setenv("RFQ_READER_NORMALIZER_MAX_ITEMS", "1")
    stub["response"] = "echo_each_chunk"
    budget = {"used": 0, "limit": 1}
    out = rn.normalize_items(
        [{"text": "4C 16mm2 Cu"}, {"text": "2C 1.5mm2 Cu"}], budget=budget
    )
    assert sorted(out) == [0]
    assert budget["used"] == 1
    assert "budget exhausted" in rn.degraded_reason()


def test_skip_indexes_never_reach_the_model(stub):
    stub["response"] = "echo_each_chunk"
    rn.normalize_items(
        [{"text": "4C 16mm2 Cu"}, {"text": "BVA-PAXN5C10BK"}], skip_indexes={1}
    )
    assert [row["index"] for row in chunk_rows(stub["payload"])] == [0]


# --- precedence, and the one-flag swap ------------------------------------------------

@pytest.mark.skip(reason='The Foundry agent is not ported (production runs it off; see rfq_parser._unported).')
def test_the_translator_wins_over_the_agent(monkeypatch, stub):
    """The swap must be ONE App Settings change on a poller that is already auto-pushing.
    With the agent winning instead, switching terra on did nothing until RFQ_AGENT_ENABLED
    was also cleared -- two restarts, and a window where the flags disagree."""
    from unittest import mock
    from rfq_parser import agent

    stub["response"] = "echo_each_chunk"
    monkeypatch.setenv("RFQ_AGENT_ENABLED", "true")
    monkeypatch.setenv("RFQ_FOUNDRY_API_KEY", "key")
    monkeypatch.setenv("RFQ_FOUNDRY_RESOURCE", "tricab-ai2")
    assert agent.agent_enabled() is True, "the agent must really be on for this to mean anything"

    with mock.patch.object(agent, "normalize_items") as agent_call:
        pipeline.match_items(
            [_reader_item("4C 16mm2 Cu XLPE/PVC SWA 0.6/1kV, 250m")],
            message_context={"subject": "RFQ"},
        )
    agent_call.assert_not_called()
    assert rn.last_run()["ran"] is True


def test_the_translator_stays_off_the_web_path(monkeypatch, stub):
    """No message in hand means the PHP request path, which spawns rfq_cli.py per request
    under a 100s cap. Same gate as the agent, and it cannot be flipped from App Settings."""
    stub["response"] = "echo_each_chunk"
    pipeline.match_items([_reader_item("4C 16mm2 Cu XLPE/PVC SWA 0.6/1kV, 250m")])
    assert rn.last_run()["ran"] is False
    assert stub["payload"] is None


# --- what the draft is told ------------------------------------------------------------

def test_a_translator_failure_is_carried_out_on_degraded(monkeypatch, stub):
    """Returning {} drops the rows to regex. Without this the draft is indistinguishable
    from a clean one, which is exactly how the dead OPENROUTER_API_KEY went unnoticed."""
    def fake_post(path, payload, timeout):
        raise rn.ReaderError("Azure OpenAI HTTP 500: upstream")

    monkeypatch.setattr(rn, "_post", fake_post)
    degraded = {}
    results = pipeline.match_items(
        [_reader_item("4C 16mm2 Cu XLPE/PVC SWA 0.6/1kV, 250m")],
        message_context={"subject": "RFQ"},
        degraded=degraded,
    )
    assert results, "the row must still be quoted, on the regex path"
    assert degraded["degraded"] is True
    assert "500" in degraded["reason"]
    assert degraded["run"]["model"] == "gpt-test"


@pytest.mark.skip(reason='The Foundry agent is not ported (production runs it off; see rfq_parser._unported).')
def test_a_row_with_no_reading_is_counted_against_the_translator(monkeypatch, stub):
    """It used to land on `agent._last_run` whatever ran -- a count filed under a record
    reading `ran: False`."""
    from rfq_parser import agent

    def nothing_to_say(items_, *, budget=None, skip_indexes=None):
        rn._last_run.update(ran=True, ok=True, rows=1, chunks=1, chunks_failed=0)
        return {0: NormalizedRow(entries=[], model="gpt-test", confidence=0.9)}

    agent.reset_run()
    monkeypatch.setattr(rn, "normalize_items", nothing_to_say)
    rows = pipeline.match_items(
        [_reader_item("4C 16mm2 Cu XLPE/PVC SWA 0.6/1kV, 250m")],
        message_context={"subject": "RFQ"},
    )
    assert rows, "the row must not vanish"
    assert any("no reading" in w for w in (rows[0].get("warnings") or []))
    assert rn.last_run()["rows_without_reading"] == 1
    assert agent.last_run().get("rows_without_reading") is None


# --- health ----------------------------------------------------------------------------

@pytest.mark.skip(reason="The source CLI's health action; the platform reports reader state on /api/health.")
def test_health_reports_the_switch_where_production_can_see_it(monkeypatch):
    """`rfq_api.php?action=health` shells into rfq_cli.py. Anything absent there is
    invisible in production no matter what app.py reports."""
    import argparse
    import rfq_cli

    out = rfq_cli.action_health(argparse.Namespace(), {})
    assert out["reader"]["model"] == "gpt-test"
    translator = out["reader_translator"]
    assert translator["enabled"] is True
    assert translator["requires_message_context"] is True
    assert translator["model"] == "gpt-test"


# --- the guard the bypass skips ---------------------------------------------------------

def test_a_code_the_customer_stated_survives_the_bypass(stub):
    """Reader rows skip `find_exact_product_code`, which used to keep a row naming a real
    catalog code away from the AI entirely. Measured rather than assumed: the code still
    wins, because `_match_llm_entry` resolves against the customer's own text and the
    field overrides ride alongside it. Asserted with a DELIBERATELY wrong translation
    (the stub says 4C 16mm2) -- if a mistranslation ever starts moving a stated code,
    this fails and the guard has to come back.
    """
    code = next(c for c in pipeline.catalog.products_by_code if c)
    results = pipeline.match_items(
        [_reader_item(f"{code} 250m")], message_context={"subject": "RFQ"}
    )
    assert results[0]["selected_product_code"] == code


def test_schedule_evidence_reaches_translator_without_rewriting_route(stub):
    schedule = {
        'source_description': 'A2-E | 120mm2 | CU | 2 | 45',
        'conductor_spec': {'active_size': '120mm2', 'conductor_material': 'CU', 'cores': '2'},
        'component': 'separate_earth', 'cuts': 2, 'total_m': 90,
        'source_cells': [{'field': 'active_size', 'cell': 'Sheet1!F5'}],
    }
    item = _reader_item('Earth', schedule=schedule)
    item['metadata'] = {'table_quantity': 90, 'table_unit': 'm',
                        'quantity_basis': 'total', 'number_of_lengths': 2}
    rn.normalize_items([item])
    sent = chunk_rows(stub['payload'])[0]
    assert sent['text'] == 'Earth'
    assert sent['schedule'] == schedule
    assert sent['quantity_metadata'] == item['metadata']
    assert item['source']['schedule'] == schedule
