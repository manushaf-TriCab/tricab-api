from __future__ import annotations

import pytest

from cbsync import cablebuilder as cbmod
from cbsync.cablebuilder import CableBuilder, SessionRejected

LOGIN = b"<html><title>Sign In to CableBuilder</title><input name=\"userid\"></html>"
XML = b'<?xml version="1.0"?><lookuptables><lookuptable/></lookuptables>'
ERROR = b"<html>java.lang.NumberFormatException</html>"


class Resp:
    def __init__(self, content: bytes) -> None:
        self.content = content


class FakeHttp:
    """Answers like the servlet: XML while signed in, the login form otherwise."""

    password = "right"
    script: list[bytes] = []  # bodies for GETs, consumed first; then signed-in state decides

    def __init__(self) -> None:
        self.headers: dict = {}
        self.cookies = type("Jar", (), {"clear": lambda s: None})()
        self.signed_in = False
        self.posts: list[dict] = []
        self.gets: list[dict] = []

    def get(self, url, params=None, timeout=None):
        self.gets.append(params)
        if params.get("requestType") == "logOn":
            return Resp(LOGIN)
        if FakeHttp.script:
            return Resp(FakeHttp.script.pop(0))
        return Resp(XML if self.signed_in else LOGIN)

    def post(self, url, data=None, timeout=None):
        self.posts.append(data)
        self.signed_in = data["password"] == FakeHttp.password
        return Resp(b"<html>welcome</html>" if self.signed_in else LOGIN)


@pytest.fixture(autouse=True)
def fake_http(monkeypatch):
    FakeHttp.script = []
    monkeypatch.setattr(cbmod.requests, "Session", FakeHttp)
    monkeypatch.setattr(cbmod.time, "sleep", lambda s: None)


def test_signs_in_with_the_login_form_fields():
    cb = CableBuilder(user="cbsync", password="right", pause=0)
    assert cb.http.posts == [{
        "requestType": "logOn", "xsl": "login.xsl", "requestURL": "",
        "userid": "cbsync", "password": "right", "useLangCode": "", "colour": "0",
    }]
    assert cb.http.gets[0] == {"requestType": "logOn", "xsl": "login.xsl"}  # form first
    assert cb.check() == 1


def test_wrong_password_is_reported_not_retried():
    with pytest.raises(SessionRejected, match="refused"):
        CableBuilder(user="cbsync", password="wrong", pause=0)


def test_expired_session_signs_in_again_once_and_retries():
    cb = CableBuilder(user="cbsync", password="right", pause=0)
    FakeHttp.script = [LOGIN]  # the next request finds the session gone
    assert cb.check() == 1
    assert len(cb.http.posts) == 2


def test_a_servlet_error_page_does_not_trigger_a_sign_in():
    cb = CableBuilder(user="cbsync", password="right", pause=0)
    FakeHttp.script = [ERROR, ERROR]  # getDesign and getDesignHeader both fail
    assert cb.design("X", "0", "0") == ("failed", None)
    assert len(cb.http.posts) == 1


def test_falls_back_to_a_browser_cookie(monkeypatch):
    monkeypatch.delenv("CB_USER", raising=False)
    monkeypatch.delenv("CB_PASSWORD", raising=False)
    cb = CableBuilder(cookie="abc123", pause=0)
    assert cb.http.headers["Cookie"] == "JSESSIONID=abc123" and cb.http.posts == []
