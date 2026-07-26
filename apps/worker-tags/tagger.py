"""RAM-based open-vocabulary tagging helpers.

Heavy torch / recognize-anything imports stay inside get_ram_model() /
tag_image() so unit tests can import select_tags / slugify_label without
those dependencies installed.
"""

from __future__ import annotations

import logging
import re
import unicodedata
from pathlib import Path
from typing import Optional, Sequence

log = logging.getLogger("worker-tags")

_model = None
_transform = None
_device = "cpu"


def slugify_label(label: str) -> str:
    """ASCII lowercase hyphenated slug, matching Symfony AsciiSlugger behaviour."""
    normalized = unicodedata.normalize("NFKD", label)
    ascii_only = normalized.encode("ascii", "ignore").decode("ascii")
    lowered = ascii_only.lower()
    slug = re.sub(r"[^a-z0-9]+", "-", lowered).strip("-")
    return slug


def select_tags(
    scored: Sequence[tuple[str, float]],
    threshold: float,
    max_tags: int,
) -> list[str]:
    """Pick tag labels by score threshold, highest first, capped at max_tags.

    Duplicate labels (case-insensitive) are collapsed, keeping the higher score.
    Empty / blank labels are skipped. Returns labels in score-descending order.
    """
    if max_tags <= 0:
        return []

    best: dict[str, tuple[str, float]] = {}
    for label, score in scored:
        if not isinstance(label, str):
            continue
        cleaned = label.strip()
        if not cleaned or score < threshold:
            continue
        key = cleaned.lower()
        prev = best.get(key)
        if prev is None or score > prev[1]:
            best[key] = (cleaned, float(score))

    ordered = sorted(best.values(), key=lambda item: item[1], reverse=True)
    return [label for label, _ in ordered[:max_tags]]


def get_ram_model(pretrained: Optional[str] = None):
    """Lazily construct the RAM Swin-Large model on CPU.

    Weights (~3GB for ram_plus_swin_large_14m) download on first call into
    ~/.cache/recognize-anything. Mount a volume over that path in
    docker-compose if rebuilds should not re-download.
    """
    global _model, _transform, _device

    if _model is not None:
        return _model, _transform

    import torch
    from ram.models import ram_plus
    from ram import get_transform

    _device = "cuda" if torch.cuda.is_available() else "cpu"
    checkpoint = pretrained or _ensure_checkpoint()
    log.info("Loading RAM++ model from %s on %s", checkpoint, _device)

    model = ram_plus(pretrained=checkpoint, image_size=384, vit="swin_l")
    model.eval()
    model = model.to(_device)

    _model = model
    _transform = get_transform(image_size=384)
    return _model, _transform


_CHECKPOINT_REPO = "xinyu1205/recognize-anything-plus-model"
_CHECKPOINT_FILE = "ram_plus_swin_large_14m.pth"


def _ensure_checkpoint() -> str:
    """Return a local path to the RAM++ weights, downloading once if needed.

    Uses huggingface_hub (resumable, retrying, checksum-verified) rather than a
    plain urlretrieve, which fails on the ~3GB file over flaky connections.
    """
    import os

    env_path = os.environ.get("RAM_CHECKPOINT")
    if env_path:
        return env_path

    cache_dir = Path.home() / ".cache" / "recognize-anything"
    cache_dir.mkdir(parents=True, exist_ok=True)
    cache = cache_dir / _CHECKPOINT_FILE
    if cache.is_file():
        return str(cache)

    from huggingface_hub import hf_hub_download

    log.info("Downloading RAM++ checkpoint %s from %s", _CHECKPOINT_FILE, _CHECKPOINT_REPO)
    downloaded = hf_hub_download(repo_id=_CHECKPOINT_REPO, filename=_CHECKPOINT_FILE)
    # Symlink/copy into the stable cache path so future runs skip the download.
    try:
        os.symlink(downloaded, cache)
    except OSError:
        import shutil

        shutil.copyfile(downloaded, cache)
    return str(cache)


def tag_image(image_path: Path) -> list[tuple[str, float]]:
    """Run RAM++ on an image; return (label, score) pairs.

    The recognize-anything inference helpers return comma-separated tag strings
    without per-tag scores. We assign a uniform score of 1.0 so select_tags
    still applies max_tags / empty filtering. When the model exposes logits,
    prefer those; this keeps the pipeline working with the stock API.
    """
    from PIL import Image
    import torch
    from ram import inference_ram as inference

    model, transform = get_ram_model()
    image = transform(Image.open(image_path).convert("RGB")).unsqueeze(0).to(_device)

    with torch.no_grad():
        tags_english, _tags_chinese = inference(image, model)

    labels = [part.strip() for part in tags_english.split("|") if part.strip()]
    if not labels:
        # Some versions use comma separators.
        labels = [part.strip() for part in tags_english.split(",") if part.strip()]

    return [(label, 1.0) for label in labels]
