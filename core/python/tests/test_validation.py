"""Field validation on the finder's HTTP surface.

Each case here used to come back as a product code (or a 500): -2 cores as
`KL-PAXN/-2C16OR`, an unknown style as the house default, an unknown cable
type as a ValueError. They must now come back `invalid`, naming the field.
"""

from __future__ import annotations

import pytest

from product_finder import rules
from product_finder.app import app

requires_rules = pytest.mark.skipif(
    not rules.status().available, reason="the cable catalogue did not load"
)


def find(body: dict) -> dict:
    response = app.test_client().post("/api/find", json=body)
    assert response.status_code == 200
    return response.get_json()["data"]


def error_fields(data: dict) -> list[str]:
    return [error["field"] for error in data["errors"]]


@requires_rules
@pytest.mark.parametrize("style", ["KL", "LP", "FR", "XN"])
def test_a_stated_style_is_the_style_of_the_code(style):
    cores = 1 if style == "LP" else 4
    data = find({"style": style, "cores": cores, "crossSection": 16, "cableType": 4})
    assert data["status"] == "matched"
    assert data["productCode"].startswith(f"{style}-")


@requires_rules
def test_style_is_normalised():
    data = find({"style": " kl- ", "cores": 4, "crossSection": 16})
    assert data["productCode"].startswith("KL-")


@requires_rules
@pytest.mark.parametrize(
    ("body", "field"),
    [
        ({"style": "ZZ", "cores": 4, "crossSection": 16}, "style"),
        ({"style": "KLX", "cores": 4, "crossSection": 16}, "style"),
        ({"style": "LP", "cores": 4, "crossSection": 16}, "coreCount"),
        ({"style": "KL", "cores": 0, "crossSection": 16}, "coreCount"),
        ({"style": "KL", "cores": -2, "crossSection": 16}, "coreCount"),
        ({"style": "KL", "cores": 2.5, "crossSection": 16}, "coreCount"),
        ({"style": "KL", "cores": "abc", "crossSection": 16}, "coreCount"),
        ({"style": "KL", "cores": 500, "crossSection": 16}, "coreCount"),
        ({"style": "KL", "cores": 4, "crossSection": -16}, "crossSection"),
        ({"style": "KL", "cores": 4, "crossSection": 17}, "crossSection"),
        ({"cableType": 4, "load": -50, "length": 30}, "loadAmps"),
        ({"cableType": 4, "load": 50, "length": 0}, "lengthM"),
        ({"cableType": 99, "cores": 4, "crossSection": 16}, "cableType"),
        ({"cableType": 4, "load": 50, "length": 30, "install": 99}, "install"),
        ({"cableType": 4, "load": 50, "length": 30, "phase": 9}, "phase"),
        ({"cableType": 4, "load": 50, "length": 30, "voltage": 0}, "voltage"),
        ({"cableType": 4, "load": 50, "length": 30, "ambient": 90}, "ambient"),
    ],
)
def test_bad_fields_are_invalid_not_matched(body, field):
    data = find(body)
    assert data["status"] == "invalid"
    assert data["productCode"] is None
    assert field in error_fields(data)


@requires_rules
def test_every_bad_field_is_reported_at_once():
    data = find({"cableType": 4, "load": 50, "length": 30, "phase": 9, "voltage": 0, "ambient": 90})
    assert set(error_fields(data)) == {"phase", "voltage", "ambient"}


@requires_rules
def test_a_non_standard_size_names_the_nearest_ones():
    data = find({"style": "KL", "cores": 4, "crossSection": 17})
    assert "16 mm²" in data["errors"][0]["message"]


@requires_rules
def test_valid_requests_carry_no_errors():
    data = find({"style": "KL", "cores": 2, "crossSection": 0.75})
    assert data["status"] == "matched"
    assert data["errors"] == []
