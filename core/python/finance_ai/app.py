"""The finance AI service's HTTP surface: a private service the PHP API calls.

In production this binds 127.0.0.1:8003 only and has no nginx location, like the product
finder and the RFQ parser: PHP authenticates the person and checks the permission, and this
process only holds the model key and the prompts. It runs as its own gunicorn process
(`finance_wsgi.py`).
"""

from __future__ import annotations

import logging

from flask import Flask, jsonify
from werkzeug.exceptions import HTTPException

from .contract import CONTRACT, v1

logging.basicConfig(level=logging.INFO)

# A request is a purpose's data, not a document; the larger inputs go through PHP's stores.
MAX_REQUEST_BYTES = 2 * 1024 * 1024

app = Flask(__name__, static_folder=None)
app.config["MAX_CONTENT_LENGTH"] = MAX_REQUEST_BYTES
app.register_blueprint(v1)


@app.errorhandler(HTTPException)
def _http_error(exc: HTTPException):
    """Anything outside a `/v1` handler (a wrong path, a wrong method, an oversized body) in the contract's shape."""
    code = "invalid_request" if exc.code and exc.code < 500 else "internal_error"
    return jsonify({"contract": CONTRACT, "correlationId": None, "error": {"code": code, "message": exc.name}}), exc.code or 500
