"""Central model snapshots must not leak between finder requests."""

import json
from contextvars import copy_context
from dataclasses import replace
from io import BytesIO

import pytest

from product_finder import nl


def test_provider_uses_snapshot_then_restores_environment_default(monkeypatch):
    config = replace(nl.CONFIG, llm=replace(
        nl.CONFIG.llm, enabled=True, api_key="test-key", model="provider/baseline",
    ))
    monkeypatch.setattr(nl, "CONFIG", config)
    models = []

    def fake_open(request, **kwargs):
        models.append(json.loads(request.data)["model"])
        return BytesIO(json.dumps({"choices": [{"message": {"content": "{}"}}]}).encode())

    monkeypatch.setattr(nl.urllib.request, "urlopen", fake_open)
    isolated = copy_context()
    with nl.model_snapshot("provider/selected"):
        nl.ai_extract("cable", {})
        isolated.run(nl.ai_extract, "cable", {})
    nl.ai_extract("cable", {})
    assert models == ["provider/selected", "provider/baseline", "provider/baseline"]


def test_snapshot_is_restored_after_failure():
    with pytest.raises(RuntimeError), nl.model_snapshot("provider/selected"):
        raise RuntimeError("failed request")
    assert nl._runtime_model.get() is None


@pytest.mark.parametrize("model", [12, {}, "", "model with spaces"])
def test_http_rejects_invalid_runtime_model(model):
    from product_finder.app import app

    response = app.test_client().post("/api/find", json={"text": "cable", "runtimeModel": model})
    assert response.status_code == 422
