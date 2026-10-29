"""The product finder's HTTP surface.

Response envelope matches `tricab-api`'s `JsonResponder` — `{success, data}` and
`{success, error:{code, message}}` — so `HttpProductFinderClient` can hand the
body through with the shape intact.

In production this binds 127.0.0.1 only and is never given an nginx location:
PHP proxies to it, so catalogue visibility stays enforced in
`AssistantCatalogueVisibilityResolver` rather than being re-implemented here.
`wsgi.py` is the gunicorn entry point; `__main__` below is for local runs.
"""

from __future__ import annotations

import logging
import os
from pathlib import Path
from typing import Any

from flask import Flask, jsonify, request, send_from_directory

from .config import CONFIG
from .nl import model_snapshot
from .selection import FINDER, FinderRequest, FinderResult, health, vocabularies
from .validation import validate_payload

logging.basicConfig(level=logging.INFO)
log = logging.getLogger("product-finder")

# `preview.html` stayed behind in `platform/product-finder/` — it is a developer
# harness, not part of the deployed service, and the App Service never serves
# this app directly (PHP proxies to it on localhost). Point PRODUCT_FINDER_PREVIEW
# at it to keep the harness working.
PREVIEW_DIR = Path(
    os.environ.get("PRODUCT_FINDER_PREVIEW")
    or Path(__file__).resolve().parents[4] / "product-finder"
)

app = Flask(__name__, static_folder=None)


def success(data: Any):
    return jsonify({"success": True, "data": data})


def failure(code: str, message: str, status: int = 400):
    return jsonify({"success": False, "error": {"code": code, "message": message}}), status


@app.after_request
def add_cors_headers(response):
    response.headers["Access-Control-Allow-Origin"] = "*"
    response.headers["Access-Control-Allow-Headers"] = "Content-Type"
    response.headers["Access-Control-Allow-Methods"] = "GET, POST, OPTIONS"
    return response


@app.get("/")
def index():
    if not (PREVIEW_DIR / "preview.html").is_file():
        return failure("NO_PREVIEW", f"No preview.html under {PREVIEW_DIR}.", 404)
    return send_from_directory(PREVIEW_DIR, "preview.html")


@app.get("/api/health")
def api_health():
    return success(health())


@app.get("/api/vocab")
def api_vocab():
    return success(vocabularies())


@app.post("/api/find")
def api_find():
    payload = request.get_json(silent=True)
    if not isinstance(payload, dict):
        return failure("VALIDATION_FAILED", "A JSON object body is required.", 422)

    if (
        not payload.get("text")
        and payload.get("cableType") in (None, "")
        and not payload.get("style")
    ):
        return failure(
            "VALIDATION_FAILED",
            "Give a free-text requirement, a cable type or a style.",
            422,
        )

    # A 200 with `status: invalid`, not a 4xx: `HttpProductFinderClient` turns
    # a 4xx into `unavailable`, which would lose the per-field messages.
    errors = validate_payload(payload)
    if errors:
        return success(FinderResult(
            status="invalid",
            errors=[error.public() for error in errors],
            detail=" ".join(error.message for error in errors),
        ).public())

    model = payload.get("runtimeModel")
    if model is not None and (
        not isinstance(model, str) or not model.strip() or len(model) > 200
        or any(character.isspace() for character in model)
    ):
        return failure("VALIDATION_FAILED", "Invalid runtime model.", 422)

    try:
        finder_request = FinderRequest.from_payload(payload)
        with model_snapshot(model):
            result = FINDER.find(finder_request)
    except Exception as exc:  # noqa: BLE001 - surfaced as a clean API error
        log.exception("Find failed")
        return failure("FIND_FAILED", str(exc), 500)

    return success(result.public())


if __name__ == "__main__":
    log.info("Product Finder on http://%s:%s", CONFIG.host, CONFIG.port)
    app.run(host=CONFIG.host, port=CONFIG.port, debug=CONFIG.debug)
