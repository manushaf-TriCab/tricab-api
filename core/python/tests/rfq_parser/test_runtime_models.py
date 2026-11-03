"""Live model choices stay inside one RFQ parser request context."""

from contextvars import Context

from rfq_parser import model_settings


def test_model_override_is_isolated_from_another_request(monkeypatch):
    monkeypatch.setenv("RFQ_READER_MODEL", "reader/default")
    assert model_settings.install({"RFQ_READER_MODEL": "reader/new"})
    assert model_settings.get("RFQ_READER_MODEL") == "reader/new"
    assert Context().run(model_settings.get, "RFQ_READER_MODEL") == "reader/default"


def test_unknown_or_malformed_model_is_refused():
    assert not model_settings.install({"OTHER_MODEL": "reader/new"})
    assert not model_settings.install({"RFQ_READER_MODEL": "bad model"})


def test_legacy_route_clears_previous_request_model(monkeypatch):
    from rfq_parser.app import app

    monkeypatch.setenv("RFQ_READER_MODEL", "reader/default")
    assert model_settings.install({"RFQ_READER_MODEL": "reader/previous"})
    app.test_client().get("/api/health")
    assert model_settings.get("RFQ_READER_MODEL") == "reader/default"


def test_an_openrouter_id_left_in_platform_settings_is_dropped_not_sent_to_foundry():
    from contextvars import Context

    def run():
        assert model_settings.install({"RFQ_LLM_MODEL": "openai/gpt-4o-mini", "RFQ_VISION_MODEL": "claude-haiku-5-5"})
        return model_settings.get("RFQ_LLM_MODEL", "claude-haiku-5-5"), model_settings.get("RFQ_VISION_MODEL")

    assert Context().run(run) == ("claude-haiku-5-5", "claude-haiku-5-5")
