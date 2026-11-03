"""HTTP server for the inspection sandbox. Flask app; gunicorn in the container.

Auth: every route except /health needs `Authorization: Bearer $QG_TOKEN`. With no
token configured the server refuses requests (fail closed).
"""
import hmac
import json
import os
import resource
import shutil
import subprocess
import sys
import tempfile
import time
import uuid

from flask import Flask, Response, jsonify, request, send_file

from . import __version__
from .inventory import inventory
from .limits import LimitError, MAX_FILE_BYTES
from .tools import render, run_tool

MAX_EXEC_SECONDS = 60
EXEC_MEM_BYTES = int(os.environ.get("QG_EXEC_MEM_BYTES", 1024 * 1024 * 1024))
EXEC_OUTPUT_CHARS = 20000


def create_app(scratch=None, token=None):
    app = Flask(__name__)
    app.config["MAX_CONTENT_LENGTH"] = MAX_FILE_BYTES + 1024 * 1024
    root = scratch or os.environ.get("QG_SCRATCH") or tempfile.mkdtemp(prefix="qg-session-")
    files_dir = os.path.join(root, "files")
    work_dir = os.path.join(root, "work")
    os.makedirs(files_dir, exist_ok=True)
    os.makedirs(work_dir, exist_ok=True)
    tok = token if token is not None else os.environ.get("QG_TOKEN", "")
    app.config.update(QG_ROOT=root)

    def meta_path(fid):
        return os.path.join(files_dir, fid + ".json")

    def load_meta(fid):
        if not fid or not fid.isalnum():
            return None
        try:
            with open(meta_path(fid)) as f:
                return json.load(f)
        except OSError:
            return None

    def store(src_path, name, produced_by=None):
        fid = uuid.uuid4().hex
        dest = os.path.join(files_dir, fid + ".bin")
        shutil.move(src_path, dest) if src_path.startswith(files_dir) else shutil.copyfile(src_path, dest)
        try:
            inv = inventory(dest)
        except LimitError as e:
            os.remove(dest)
            raise e
        meta = {"fileId": fid, "originalName": name, "uploadedAt": time.time(), "producedBy": produced_by, **inv}
        with open(meta_path(fid), "w") as f:
            json.dump(meta, f)
        return meta

    def err(status, msg, **extra):
        return jsonify({"error": msg, **extra}), status

    @app.before_request
    def auth():
        if request.path == "/health":
            return None
        if not tok:
            return err(503, "server has no QG_TOKEN configured")
        got = request.headers.get("Authorization", "")
        if not hmac.compare_digest(got.encode(), f"Bearer {tok}".encode()):
            return err(401, "unauthorised")

    @app.get("/health")
    def health():
        from .docx import soffice_available
        return jsonify({"status": "ok", "version": __version__, "libreoffice": soffice_available()})

    @app.post("/files")
    def upload():
        up = request.files.get("file")
        data = request.get_data() if up is None else None
        name = (up.filename if up else request.headers.get("X-Filename")) or "unnamed"
        tmp = os.path.join(files_dir, uuid.uuid4().hex + ".tmp")
        with open(tmp, "wb") as f:
            if up:
                up.save(f)
            else:
                f.write(data)
        if os.path.getsize(tmp) == 0:
            os.remove(tmp)
            return err(400, "empty upload")
        try:
            meta = store(tmp, os.path.basename(name))
        except LimitError as e:
            return err(413, str(e))
        except Exception as e:
            return err(422, f"cannot register file: {e}")
        return jsonify(meta), 201

    @app.get("/files")
    def list_files():
        out = []
        for n in sorted(os.listdir(files_dir)):
            if n.endswith(".json"):
                m = load_meta(n[:-5])
                if m:
                    out.append(m)
        return jsonify({"files": out, "truncated": False, "next_cursor": None})

    @app.get("/files/<fid>")
    def download(fid):
        m = load_meta(fid)
        if not m:
            return err(404, "no such file")
        return send_file(os.path.join(files_dir, fid + ".bin"), mimetype=m["mediaType"], as_attachment=True,
                         download_name=m["originalName"])

    @app.post("/inspect")
    def inspect_():
        body = request.get_json(silent=True) or {}
        m = load_meta(body.get("fileId"))
        if not m:
            return err(404, "no such file")
        try:
            res = run_tool(os.path.join(files_dir, m["fileId"] + ".bin"), m["mediaType"], body.get("tool"), body.get("args") or {})
        except (ValueError, LimitError) as e:
            return err(400, str(e))
        except Exception as e:  # corrupt file etc.: say so, don't 500 blindly
            return err(422, f"{type(e).__name__}: {e}", unreadable=[{"reason": str(e)}])
        return jsonify({"fileId": m["fileId"], "tool": body.get("tool"), "result": res})

    @app.post("/render")
    def render_():
        body = request.get_json(silent=True) or {}
        m = load_meta(body.get("fileId"))
        if not m:
            return err(404, "no such file")
        try:
            png, meta = render(os.path.join(files_dir, m["fileId"] + ".bin"), m["mediaType"], body)
        except (ValueError, LimitError) as e:
            return err(400, str(e))
        except Exception as e:
            return err(422, f"{type(e).__name__}: {e}")
        return Response(png, mimetype="image/png", headers={"X-Render-Meta": json.dumps(meta)})

    def _limits(secs):
        def fn():
            resource.setrlimit(resource.RLIMIT_CPU, (secs + 1, secs + 1))
            try:
                resource.setrlimit(resource.RLIMIT_AS, (EXEC_MEM_BYTES, EXEC_MEM_BYTES))
            except (ValueError, OSError):
                pass  # macOS does not honour RLIMIT_AS; the container does
            resource.setrlimit(resource.RLIMIT_FSIZE, (100 * 1024 * 1024,) * 2)
            os.setsid()
        return fn

    @app.post("/execute")
    def execute():
        body = request.get_json(silent=True) or {}
        code = body.get("code")
        if not isinstance(code, str) or not code.strip():
            return err(400, "code is required")
        secs = max(1, min(int(body.get("timeoutSeconds") or 20), MAX_EXEC_SECONDS))
        before = {os.path.relpath(os.path.join(d, f), work_dir) for d, _, fs in os.walk(work_dir) for f in fs}
        env = {"PATH": os.environ.get("PATH", ""), "HOME": work_dir, "PYTHONDONTWRITEBYTECODE": "1",
               "QG_FILES_DIR": files_dir, "MPLCONFIGDIR": work_dir}
        t0 = time.time()
        timed_out = False
        try:
            p = subprocess.run([sys.executable, "-I", "-c", code], cwd=work_dir, env=env, capture_output=True,
                               timeout=secs, preexec_fn=_limits(secs))
            out, errs, rc = p.stdout, p.stderr, p.returncode
        except subprocess.TimeoutExpired as e:
            out, errs, rc, timed_out = e.stdout or b"", e.stderr or b"", -9, True
        new = []
        for d, _, fs in os.walk(work_dir):
            for f in fs:
                rel = os.path.relpath(os.path.join(d, f), work_dir)
                if rel not in before:
                    full = os.path.join(d, f)
                    try:
                        meta = store(full, f, produced_by="execute")
                        new.append({"path": rel, "fileId": meta["fileId"], "size": meta["size"], "mediaType": meta["mediaType"]})
                    except Exception as e:
                        new.append({"path": rel, "error": str(e)})
        so, st = out.decode("utf-8", "replace"), errs.decode("utf-8", "replace")
        return jsonify({"exitCode": rc, "timedOut": timed_out, "seconds": round(time.time() - t0, 3),
                        "stdout": so[:EXEC_OUTPUT_CHARS], "stderr": st[:EXEC_OUTPUT_CHARS],
                        "truncated": len(so) > EXEC_OUTPUT_CHARS or len(st) > EXEC_OUTPUT_CHARS, "newFiles": new,
                        "note": "work files are copied into the file store; uploaded files are in $QG_FILES_DIR as <fileId>.bin"})

    return app


def main():  # pragma: no cover
    port = int(os.environ.get("PORT", "8080"))
    create_app().run(host="0.0.0.0", port=port)


if __name__ == "__main__":  # pragma: no cover
    main()
