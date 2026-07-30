"""MobileCLIP fixed-vocabulary tagging backends (S0 / S1 via open_clip)."""

from __future__ import annotations

import hashlib
import logging
import os
from pathlib import Path
from typing import Optional

import numpy as np

from vocabulary import load_ram_tag_list

log = logging.getLogger("worker-tags")

MODEL_SPECS = {
    # OpenCLIP never shipped the original MobileCLIP-S0 architecture/config.
    # MobileCLIP2-S0 is Apple's supported S0 successor and is available with
    # bundled OpenCLIP config + weights under the dfndr2b pretrained tag.
    "mobileclip_s0": ("MobileCLIP2-S0", "dfndr2b"),
    "mobileclip_s1": ("MobileCLIP-S1", "datacompdr"),
}

_DEFAULT_THRESHOLD = 0.20

_active_variant: Optional[str] = None
_model = None
_preprocess = None
_tokenizer = None
_device = "cpu"
_text_embeddings: Optional[np.ndarray] = None
_text_labels: Optional[tuple[str, ...]] = None
_text_cache_key: Optional[str] = None


def unload() -> None:
    """Release the loaded MobileCLIP model and text embeddings."""
    global _active_variant, _model, _preprocess, _tokenizer, _device
    global _text_embeddings, _text_labels, _text_cache_key
    _active_variant = None
    _model = None
    _preprocess = None
    _tokenizer = None
    _device = "cpu"
    _text_embeddings = None
    _text_labels = None
    _text_cache_key = None
    try:
        import torch
        import gc

        gc.collect()
        if torch.cuda.is_available():
            torch.cuda.empty_cache()
    except Exception:
        pass


def _prompt_for(label: str) -> str:
    return f"a photo of {label}"


def _vocab_hash(labels: tuple[str, ...]) -> str:
    digest = hashlib.sha256()
    for label in labels:
        digest.update(label.encode("utf-8"))
        digest.update(b"\0")
    return digest.hexdigest()[:16]


def _embedding_cache_path(variant: str, labels: tuple[str, ...]) -> Path:
    cache_root = Path(
        os.environ.get(
            "MOBILECLIP_EMBEDDING_CACHE",
            str(Path.home() / ".cache" / "gallery-tags" / "mobileclip"),
        )
    )
    model_name, pretrained = MODEL_SPECS[variant]
    safe_model = model_name.lower().replace("/", "-")
    filename = f"{safe_model}-{pretrained}-{_vocab_hash(labels)}-prompt-v1.npy"
    return cache_root / filename


def load_cached_text_embeddings(
    path: Path,
    *,
    expected_rows: int,
) -> Optional[np.ndarray]:
    """Load a valid float32 embedding matrix, or return None on stale/corrupt cache."""
    if not path.is_file():
        return None
    try:
        matrix = np.load(path, mmap_mode="r")
    except (OSError, ValueError):
        return None
    if matrix.ndim != 2 or matrix.shape[0] != expected_rows:
        return None
    if matrix.dtype != np.float32:
        return None
    return matrix


def save_cached_text_embeddings(path: Path, matrix: np.ndarray) -> None:
    """Atomically persist text embeddings into the mounted model cache."""
    path.parent.mkdir(parents=True, exist_ok=True)
    temporary = path.with_suffix(path.suffix + ".tmp")
    with temporary.open("wb") as handle:
        np.save(handle, np.asarray(matrix, dtype=np.float32))
    temporary.replace(path)


def _ensure_model(variant: str):
    global _active_variant, _model, _preprocess, _tokenizer, _device

    if _active_variant == variant and _model is not None:
        return

    if variant not in MODEL_SPECS:
        raise ValueError(f"Unsupported MobileCLIP variant: {variant}")

    unload()

    import torch
    import open_clip

    model_name, pretrained = MODEL_SPECS[variant]
    _device = "cuda" if torch.cuda.is_available() else "cpu"
    log.info("Loading %s (%s:%s) on %s", variant, model_name, pretrained, _device)

    model, _, preprocess = open_clip.create_model_and_transforms(
        model_name,
        pretrained=pretrained,
        device=_device,
    )
    model.eval()
    tokenizer = open_clip.get_tokenizer(model_name)

    _active_variant = variant
    _model = model
    _preprocess = preprocess
    _tokenizer = tokenizer


def _ensure_text_embeddings(variant: str) -> tuple[tuple[str, ...], np.ndarray]:
    global _text_embeddings, _text_labels, _text_cache_key

    labels = load_ram_tag_list()
    cache_key = f"{variant}:{_vocab_hash(labels)}"
    if _text_embeddings is not None and _text_cache_key == cache_key and _text_labels == labels:
        return _text_labels, _text_embeddings

    cache_path = _embedding_cache_path(variant, labels)
    cached = load_cached_text_embeddings(cache_path, expected_rows=len(labels))
    if cached is not None:
        _text_labels = labels
        _text_embeddings = cached
        _text_cache_key = cache_key
        log.info(
            "Loaded %d cached MobileCLIP text embeddings for %s from %s",
            len(labels),
            variant,
            cache_path,
        )
        return labels, cached

    import torch

    _ensure_model(variant)
    assert _model is not None and _tokenizer is not None

    prompts = [_prompt_for(label) for label in labels]
    batch_size = int(os.environ.get("MOBILECLIP_TEXT_BATCH", "256"))
    chunks: list[np.ndarray] = []

    with torch.no_grad():
        for start in range(0, len(prompts), batch_size):
            batch = prompts[start : start + batch_size]
            tokens = _tokenizer(batch).to(_device)
            feats = _model.encode_text(tokens)
            feats = feats / feats.norm(dim=-1, keepdim=True)
            chunks.append(feats.detach().cpu().numpy().astype(np.float32))

    matrix = np.vstack(chunks)
    save_cached_text_embeddings(cache_path, matrix)
    _text_labels = labels
    _text_embeddings = matrix
    _text_cache_key = cache_key
    log.info("Cached %d MobileCLIP text embeddings for %s", len(labels), variant)
    return labels, matrix


def tag_image(image_path: Path, variant: str = "mobileclip_s0") -> list[tuple[str, float]]:
    """Score the RAM++ vocabulary against one image using MobileCLIP."""
    from PIL import Image
    import torch

    _ensure_model(variant)
    labels, text_matrix = _ensure_text_embeddings(variant)
    assert _model is not None and _preprocess is not None

    image = _preprocess(Image.open(image_path).convert("RGB")).unsqueeze(0).to(_device)
    with torch.no_grad():
        image_feat = _model.encode_image(image)
        image_feat = image_feat / image_feat.norm(dim=-1, keepdim=True)
        image_vec = image_feat.detach().cpu().numpy().astype(np.float32)[0]

    scores = text_matrix @ image_vec
    return [(labels[i], float(scores[i])) for i in range(len(labels))]


def default_threshold() -> float:
    return float(os.environ.get("MOBILECLIP_SCORE_THRESHOLD", str(_DEFAULT_THRESHOLD)))
