"""RAM++ open-vocabulary tagging backend."""

from __future__ import annotations

import logging
from pathlib import Path
from typing import Optional

log = logging.getLogger("worker-tags")

_model = None
_transform = None
_device = "cpu"

_CHECKPOINT_REPO = "xinyu1205/recognize-anything-plus-model"
_CHECKPOINT_FILE = "ram_plus_swin_large_14m.pth"


def unload() -> None:
    """Release the loaded RAM++ model to free RAM."""
    global _model, _transform, _device
    _model = None
    _transform = None
    _device = "cpu"
    try:
        import torch
        import gc

        gc.collect()
        if torch.cuda.is_available():
            torch.cuda.empty_cache()
    except Exception:
        pass


def get_ram_model(pretrained: Optional[str] = None):
    """Lazily construct the RAM Swin-Large model on CPU/CUDA."""
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


def _ensure_checkpoint() -> str:
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
    try:
        os.symlink(downloaded, cache)
    except OSError:
        import shutil

        shutil.copyfile(downloaded, cache)
    return str(cache)


def tag_image(image_path: Path) -> list[tuple[str, float]]:
    """Run RAM++ on an image; return (label, score) pairs.

    Stock inference returns labels without scores; assign 1.0 so select_tags
    still applies max_tags filtering.
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
        labels = [part.strip() for part in tags_english.split(",") if part.strip()]

    return [(label, 1.0) for label in labels]
