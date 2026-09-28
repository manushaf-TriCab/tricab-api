"""Guards for the RFQ parser tests: nothing reaches a model or the cost API by default.

The first three are the source suite's own (`rfq/.../tests/conftest.py`), for the
same reasons: a live model answer is not deterministic and costs money, so a test
that wants an AI path stubs the transport or sets the variables itself.

The cost API guard is the platform's addition. The source suite relies on each
pricing test patching `cost_api`, and seven of the tests brought across do not:
anything that runs `match_items` prices its rows, so in the source they quietly
priced against the live website. Here the network is simply unavailable, which
the client already handles (rows come back unpriced), so those tests are about
matching and quantities again, and no test depends on today's prices.
The helper modules the source's other guards patch (`matcher.db`,
`default_margins_for_customer`) are not part of this package, so those guards
have nothing to do and are left out.
"""

from __future__ import annotations

import os

import pytest


@pytest.fixture(autouse=True)
def _translation_layers_offline(monkeypatch):
    monkeypatch.setenv("RFQ_READER_ENABLED", "false")
    monkeypatch.setenv("RFQ_AGENT_ENABLED", "false")
    monkeypatch.setenv("RFQ_NORMALIZER_ENABLED", "false")
    yield


@pytest.fixture(autouse=True)
def _cost_api_offline(monkeypatch):
    def refuse(*_args, **_kwargs):
        raise OSError("the cost API is offline under test")

    monkeypatch.setattr("rfq_parser.cost_api.CostApiClient._http_get", refuse, raising=True)
    # Both cache tiers too: the disk tier lives in the system temp directory, so without
    # this a test would quietly read whatever a developer's last real run cached there.
    monkeypatch.setattr("rfq_parser.cost_api.CostApiClient._cached", lambda self, key: None, raising=True)
    monkeypatch.setattr("rfq_parser.cost_api.CostApiClient._store", lambda self, key, result, ttl=None: None, raising=True)
    yield


def pytest_configure(config):  # noqa: ARG001 - pytest hook signature
    os.environ["RFQ_READER_ENABLED"] = "false"
    os.environ["RFQ_AGENT_ENABLED"] = "false"
    os.environ["RFQ_NORMALIZER_ENABLED"] = "false"
