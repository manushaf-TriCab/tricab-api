"""Fixtures for the cable_routes tests; the synthetic-extract builder lives in cr_support.py."""

import pytest

from cr_support import Extract, build_store


@pytest.fixture
def extract():
    return Extract()


@pytest.fixture
def store(tmp_path):
    cons = []

    def make(ex):
        con = build_store(ex, tmp_path)
        cons.append(con)
        return con

    yield make
    for c in cons:
        c.close()
