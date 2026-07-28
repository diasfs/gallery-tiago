# RAM++ open-vocabulary tagging worker.
# Model weights (~3GB for ram_plus_swin_large_14m) download on first inference
# into ~/.cache (or RAM_CHECKPOINT). Mount a named volume at /root/.cache if
# rebuilds should not re-download.
FROM python:3.12-slim

RUN apt-get update && apt-get install -y --no-install-recommends \
        build-essential \
        python3-dev \
        git \
        libglib2.0-0 \
        libgomp1 \
        libvips-tools \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app

COPY requirements.txt .
# Install CPU torch wheels first, then the rest (ram pulls torch as a dep
# but we pin the CPU build from the pytorch index).
RUN pip install --no-cache-dir \
        torch==2.4.1 torchvision==0.19.1 \
        --index-url https://download.pytorch.org/whl/cpu \
    && pip install --no-cache-dir -r requirements.txt

COPY . .
COPY docker-entrypoint.sh /usr/local/bin/gallery-tags-entrypoint.sh
RUN chmod +x /usr/local/bin/gallery-tags-entrypoint.sh

ENTRYPOINT ["/usr/local/bin/gallery-tags-entrypoint.sh"]
CMD ["python", "main.py"]
