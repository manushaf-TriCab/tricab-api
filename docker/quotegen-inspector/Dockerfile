# Quotegen inspection sandbox. Build context is core/python:
#   docker build --platform linux/amd64 -f docker/quotegen-inspector/Dockerfile -t quotegen-inspector:0.1.0 core/python
# No credentials are baked in or needed; run it with --network none.
FROM --platform=linux/amd64 python:3.11.14-slim-bookworm

ENV PYTHONDONTWRITEBYTECODE=1 PYTHONUNBUFFERED=1 PIP_NO_CACHE_DIR=1 \
    QG_SCRATCH=/session HOME=/session/work

# LibreOffice is only for docx/xlsx page rendering (soffice --headless --convert-to pdf).
RUN apt-get update \
 && apt-get install -y --no-install-recommends libreoffice-writer libreoffice-calc fonts-dejavu-core fonts-liberation \
 && rm -rf /var/lib/apt/lists/*

WORKDIR /app
COPY quotegen_inspector/requirements.txt /app/requirements.txt
RUN pip install -r /app/requirements.txt
COPY quotegen_inspector /app/quotegen_inspector

RUN useradd --uid 10001 --no-create-home --shell /usr/sbin/nologin inspector \
 && mkdir -p /session/files /session/work && chown -R inspector /session
USER inspector

EXPOSE 8080
# QG_TOKEN must be supplied at run time; without it every route except /health answers 503.
# One worker: the session scratch dir is per container. Threads give concurrent reads.
CMD ["gunicorn", "--bind", "0.0.0.0:8080", "--workers", "1", "--threads", "4", "--timeout", "180", "quotegen_inspector.server:create_app()"]
