from pathlib import Path

import pytest

from main import resolve_image_path


def test_prefers_original_over_avif() -> None:
    root = Path("/media")
    path = resolve_image_path(root, "converted/ab/id/master.avif", "originals/ab/id.jpg")
    assert path == root / "originals/ab/id.jpg"


def test_falls_back_to_avif_when_original_empty() -> None:
    root = Path("/media")
    path = resolve_image_path(root, "converted/ab/id/master.avif", "")
    assert path == root / "converted/ab/id/master.avif"


def test_raises_when_both_missing() -> None:
    with pytest.raises(RuntimeError, match="neither"):
        resolve_image_path(Path("/media"), None, "")
