"""Fixtures for the conductor_process tests; the synthetic-extract builder lives in cd_support.py."""

import pytest

from cd_support import Extract, build_store, factory


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


@pytest.fixture
def fx(store):
    """The contract section 4 fixture, built."""
    return store(factory())
