# docker build -t upload-safety-sidecar .
# docker build --build-arg MODELS=models.other.json -t upload-safety-sidecar:other .

FROM python:3.12-slim AS export

ENV PYTHONUNBUFFERED=1 \
    PIP_DISABLE_PIP_VERSION_CHECK=1 \
    HF_HOME=/tmp/hf \
    HF_HUB_DISABLE_TELEMETRY=1

WORKDIR /build
COPY export/requirements.txt /build/requirements.txt
RUN pip install --no-cache-dir torch==2.14.1 torchvision==0.29.1 --index-url https://download.pytorch.org/whl/cpu \
    && pip install --no-cache-dir -r /build/requirements.txt

ARG MODELS=models.json
COPY export/export.py /build/export.py
COPY ${MODELS} /build/models.json
RUN python /build/export.py /build/models.json /models


FROM python:3.12-slim

ENV PYTHONUNBUFFERED=1 \
    PIP_DISABLE_PIP_VERSION_CHECK=1 \
    MODELS_DIR=/models \
    MALLOC_ARENA_MAX=2

WORKDIR /app
COPY requirements.txt /app/requirements.txt
RUN pip install --no-cache-dir -r /app/requirements.txt

COPY --from=export /models /models
COPY serve.py /app/serve.py

# the sidecar frequently decodes untrusted images, so it does not run as root
USER 65534:65534
EXPOSE 8000
HEALTHCHECK --interval=15s --timeout=5s --start-period=30s --retries=5 \
    CMD ["python", "-c", "import urllib.request; urllib.request.urlopen('http://127.0.0.1:8000/health', timeout=4)"]

CMD ["python", "/app/serve.py"]
