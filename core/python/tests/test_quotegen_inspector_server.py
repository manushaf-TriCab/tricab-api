"""Quotegen inspector HTTP server, via the Flask test client."""

from __future__ import annotations

import io

import pytest

from quotegen_inspector.server import create_app

from quotegen_fixtures import build_eml, build_pdf, build_xlsx

AUTH = {"Authorization": "Bearer s3cret"}


@pytest.fixture
def client(tmp_path):
    return create_app(scratch=str(tmp_path / "session"), token="s3cret").test_client()


def upload(client, path, name=None):
    r = client.post("/files", data={"file": (io.BytesIO(path.read_bytes()), name or path.name)}, headers=AUTH,
                    content_type="multipart/form-data")
    assert r.status_code == 201, r.data
    return r.get_json()


def test_health_open_everything_else_needs_token(client):
    assert client.get("/health").get_json()["status"] == "ok"
    assert client.get("/files").status_code == 401
    assert client.get("/files", headers={"Authorization": "Bearer wrong"}).status_code == 401


def test_no_token_configured_fails_closed(tmp_path):
    c = create_app(scratch=str(tmp_path / "s"), token="").test_client()
    assert c.get("/files", headers=AUTH).status_code == 503


def test_upload_list_download_generated_names(client, tmp_path):
    meta = upload(client, build_pdf(tmp_path / "../evil name.pdf".replace("../", "")), name="../../etc/passwd.pdf")
    assert meta["originalName"] == "passwd.pdf" and meta["mediaType"] == "application/pdf"
    assert meta["fileId"].isalnum()
    assert [f["fileId"] for f in client.get("/files", headers=AUTH).get_json()["files"]] == [meta["fileId"]]
    d = client.get(f"/files/{meta['fileId']}", headers=AUTH)
    assert d.status_code == 200 and d.data.startswith(b"%PDF")
    assert client.get("/files/nope", headers=AUTH).status_code == 404
    assert client.get("/files/..%2f..%2fetc", headers=AUTH).status_code == 404


def test_inspect_eml_and_xlsx(client, tmp_path):
    e = upload(client, build_eml(tmp_path / "m.eml"))
    r = client.post("/inspect", json={"fileId": e["fileId"], "tool": "eml_overview", "args": {}}, headers=AUTH).get_json()
    assert r["result"]["headers"]["Subject"] == "RFQ test"
    x = upload(client, build_xlsx(tmp_path / "w.xlsx"))
    r = client.post("/inspect", json={"fileId": x["fileId"], "tool": "xlsx_read", "args": {"sheet": "Schedule", "range": "A1:C3"}}, headers=AUTH)
    assert r.get_json()["result"]["cells"]


def test_inspect_errors(client, tmp_path):
    x = upload(client, build_xlsx(tmp_path / "w.xlsx"))
    assert client.post("/inspect", json={"fileId": x["fileId"], "tool": "pdf_overview"}, headers=AUTH).status_code == 400
    assert client.post("/inspect", json={"fileId": x["fileId"], "tool": "bogus"}, headers=AUTH).status_code == 400
    assert client.post("/inspect", json={"fileId": x["fileId"], "tool": "xlsx_read"}, headers=AUTH).status_code == 400
    assert client.post("/inspect", json={"fileId": "zzz", "tool": "xlsx_overview"}, headers=AUTH).status_code == 404


def test_render_pdf_png(client, tmp_path):
    p = upload(client, build_pdf(tmp_path / "a.pdf"))
    r = client.post("/render", json={"fileId": p["fileId"], "page": 1, "scale": 1}, headers=AUTH)
    assert r.status_code == 200 and r.mimetype == "image/png" and r.data[:4] == b"\x89PNG"
    assert client.post("/render", json={"fileId": p["fileId"], "page": 9}, headers=AUTH).status_code == 400


def test_render_xlsx_sheet_grid(client, tmp_path):
    x = upload(client, build_xlsx(tmp_path / "w.xlsx"))
    r = client.post("/render", json={"fileId": x["fileId"], "sheet": "Schedule", "range": "A1:C3"}, headers=AUTH)
    assert r.status_code == 200 and r.data[:4] == b"\x89PNG"


def test_execute_runs_and_captures_new_files(client):
    r = client.post("/execute", json={"code": "print(6*7)\nopen('out.csv','w').write('a,b\\n1,2\\n')"}, headers=AUTH).get_json()
    assert r["exitCode"] == 0 and r["stdout"].strip() == "42"
    assert r["newFiles"][0]["path"] == "out.csv" and r["newFiles"][0]["mediaType"] == "text/csv"
    again = client.get(f"/files/{r['newFiles'][0]['fileId']}", headers=AUTH)
    assert again.data == b"a,b\n1,2\n"


def test_execute_error_and_timeout(client):
    r = client.post("/execute", json={"code": "raise SystemExit(3)"}, headers=AUTH).get_json()
    assert r["exitCode"] == 3
    r = client.post("/execute", json={"code": "import time; time.sleep(30)", "timeoutSeconds": 1}, headers=AUTH).get_json()
    assert r["timedOut"] and r["exitCode"] == -9


def test_execute_cwd_is_session_scratch_and_env_is_clean(client):
    r = client.post("/execute", json={"code": "import os;print(os.getcwd());print(sorted(k for k in os.environ if 'TOKEN' in k or 'QG_TOKEN' in k))"}, headers=AUTH).get_json()
    assert r["stdout"].splitlines()[0].endswith("/work") and r["stdout"].splitlines()[1] == "[]"
