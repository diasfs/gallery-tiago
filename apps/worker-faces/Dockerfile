# InsightFace CPU worker (Task 7). Model weights for `buffalo_l` (~350MB)
# are NOT baked into this image: insightface downloads them on first call to
# FaceAnalysis.prepare() and caches them under ~/.insightface/models. In
# docker-compose that cache lives inside the container's writable layer, so
# it re-downloads on every `docker compose build`/recreate; mount a named
# volume at /root/.insightface if that first-run download cost matters for
# your deployment.
FROM python:3.12-slim

# build-essential/python3-dev: insightface ships some Cython extensions that
# may need to compile if no prebuilt wheel matches this platform/Python
# version. libglib2.0-0/libgomp1: runtime shared libs opencv-headless and
# onnxruntime dlopen even without a GUI.
RUN apt-get update && apt-get install -y --no-install-recommends \
        build-essential \
        python3-dev \
        libglib2.0-0 \
        libgomp1 \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app

COPY requirements.txt .
RUN pip install --no-cache-dir -r requirements.txt

COPY . .
COPY docker-entrypoint.sh /usr/local/bin/gallery-faces-entrypoint.sh
RUN chmod +x /usr/local/bin/gallery-faces-entrypoint.sh

ENTRYPOINT ["/usr/local/bin/gallery-faces-entrypoint.sh"]
CMD ["python", "main.py"]
