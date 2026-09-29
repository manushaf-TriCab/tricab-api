"""Regenerate `parser-v1-paste.json`: the platform parser's `/v1/parse` answer to a
four-line paste, with the cost API offline (as under pytest), so no price in it
is today's.

    cd core/python && PYTHONPATH=. python ../tests/Rfq/Fixtures/generate_parser_v1_paste.py

The paste has a circuit label folded into the next line, a cable in cuts and a
line that matches nothing. PHP tests read it through `FixtureRfqParserClient`.
"""

from __future__ import annotations

import os
from pathlib import Path

for flag in ("RFQ_READER_ENABLED", "RFQ_AGENT_ENABLED", "RFQ_NORMALIZER_ENABLED"):
    os.environ[flag] = "false"
os.environ["RFQ_PARSER_INTERNAL_TOKEN"] = "fixture-token-000000"

from rfq_parser import cost_api  # noqa: E402


def _offline(*_args, **_kwargs):
    raise OSError("the cost API is offline for this fixture")


cost_api.CostApiClient._http_get = _offline
cost_api.CostApiClient._cached = lambda self, key: None
cost_api.CostApiClient._store = lambda self, key, result, ttl=None: None

from rfq_parser.app import app  # noqa: E402

TEXT = "DB-1\n4C 16mm2 XLPE/PVC 120m\n3C 2.5mm TPS 4 x 55m per cut\nsupply and install lighting"

response = app.test_client().post(
    "/v1/parse",
    json={
        "contract": "rfq-parser.v1",
        "correlationId": "fixture-correlation-0001",
        "deadlineMs": 60_000,
        "text": TEXT,
        "documents": [],
        "options": {"allowModel": False, "maxModelCalls": 50},
    },
    headers={"Authorization": "Bearer fixture-token-000000"},
)
assert response.status_code == 200, response.get_data(as_text=True)
Path(__file__).with_name("parser-v1-paste.json").write_text(response.get_data(as_text=True), encoding="utf-8")
