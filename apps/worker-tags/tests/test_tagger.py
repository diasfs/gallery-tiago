import os
import sys
from pathlib import Path

import pytest
import numpy as np

sys.path.insert(0, os.path.join(os.path.dirname(__file__), ".."))

from backends.mobileclip import (
    MODEL_SPECS,
    load_cached_text_embeddings,
    save_cached_text_embeddings,
)
from tagger import select_tags, slugify_label, tag_image


def test_slugify_label_ascii_lowercase_hyphens():
    assert slugify_label("Golden Retriever") == "golden-retriever"
    assert slugify_label("  Dog!  ") == "dog"
    assert slugify_label("café") == "cafe"


def test_select_tags_applies_threshold_and_max():
    scored = [
        ("dog", 0.9),
        ("cat", 0.4),
        ("beach", 0.7),
        ("sky", 0.2),
    ]
    assert select_tags(scored, threshold=0.5, max_tags=2) == ["dog", "beach"]


def test_select_tags_dedupes_case_insensitive_keeping_higher_score():
    scored = [
        ("Dog", 0.6),
        ("dog", 0.9),
        ("DOG", 0.3),
    ]
    assert select_tags(scored, threshold=0.0, max_tags=5) == ["dog"]


def test_select_tags_skips_blank_and_respects_zero_max():
    assert select_tags([("  ", 1.0), ("ok", 1.0)], threshold=0.0, max_tags=5) == ["ok"]
    assert select_tags([("ok", 1.0)], threshold=0.0, max_tags=0) == []


def test_tag_image_dispatches_to_mobileclip(monkeypatch, tmp_path: Path):
    calls = []

    monkeypatch.setattr(
        "backends.mobileclip.tag_image",
        lambda path, variant="mobileclip_s0": calls.append((str(path), variant))
        or [("a", 1.0)],
    )
    monkeypatch.setattr("backends.ram_plus.unload", lambda: None)
    monkeypatch.setattr("backends.mobileclip.unload", lambda: None)

    import tagger as tagger_mod

    tagger_mod._active_detector = None

    result = tag_image(tmp_path / "photo.jpg", detector="mobileclip_s1")
    assert result == [("a", 1.0)]
    assert calls == [(str(tmp_path / "photo.jpg"), "mobileclip_s1")]


def test_tag_image_dispatches_to_ram(monkeypatch, tmp_path: Path):
    calls = []

    monkeypatch.setattr(
        "backends.ram_plus.tag_image",
        lambda path: calls.append(str(path)) or [("beach", 1.0)],
    )
    monkeypatch.setattr("backends.ram_plus.unload", lambda: None)
    monkeypatch.setattr("backends.mobileclip.unload", lambda: None)

    import tagger as tagger_mod

    tagger_mod._active_detector = None

    result = tag_image(tmp_path / "photo.jpg", detector="ram_plus")
    assert result == [("beach", 1.0)]
    assert calls == [str(tmp_path / "photo.jpg")]


@pytest.mark.parametrize("model_name,pretrained", MODEL_SPECS.values())
def test_configured_mobileclip_models_exist_in_openclip(model_name, pretrained):
    open_clip = pytest.importorskip("open_clip")

    assert model_name in open_clip.list_models()
    assert open_clip.get_pretrained_cfg(model_name, pretrained)


def test_mobileclip_text_embeddings_round_trip_disk_cache(tmp_path):
    matrix = np.arange(12, dtype=np.float32).reshape(3, 4)
    path = tmp_path / "embeddings.npy"

    save_cached_text_embeddings(path, matrix)

    loaded = load_cached_text_embeddings(path, expected_rows=3)
    assert loaded is not None
    np.testing.assert_array_equal(loaded, matrix)


def test_mobileclip_text_cache_rejects_wrong_row_count(tmp_path):
    path = tmp_path / "embeddings.npy"
    save_cached_text_embeddings(path, np.zeros((2, 4), dtype=np.float32))

    assert load_cached_text_embeddings(path, expected_rows=3) is None
