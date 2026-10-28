"""Fixtures for the conductor_schedule tests; the synthetic source builder lives in cs_support.py."""

import pytest

from cs_support import build, make_source


@pytest.fixture
def source_dir(tmp_path):
    return make_source(tmp_path)


@pytest.fixture
def built(tmp_path):
    run, con = build(tmp_path)
    yield run, con
    con.close()
