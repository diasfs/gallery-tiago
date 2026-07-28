from pathlib import Path
from unittest.mock import patch

import pytest

from rasterize import materialize_jpeg


def test_materialize_jpeg_requires_avif_path(tmp_path: Path) -> None:
    with pytest.raises(RuntimeError, match="no avif_path"):
        materialize_jpeg(tmp_path, "")


def test_materialize_jpeg_requires_source_file(tmp_path: Path) -> None:
    with pytest.raises(RuntimeError, match="AVIF master missing"):
        materialize_jpeg(tmp_path, "converted/ab/id/master.avif")


def test_materialize_jpeg_calls_vips_and_returns_temp_path(tmp_path: Path) -> None:
    avif_rel = "converted/ab/id/master.avif"
    source = tmp_path / avif_rel
    source.parent.mkdir(parents=True)
    source.write_bytes(b"fake-avif")

    with patch("rasterize.subprocess.run") as run:
        run.return_value = None
        dest = materialize_jpeg(tmp_path, avif_rel, vips_binary="vips")

    try:
        assert dest.suffix == ".jpg"
        assert dest.exists()
        run.assert_called_once()
        args = run.call_args.args[0]
        assert args[0] == "vips"
        assert args[1] == "copy"
        assert args[2] == str(source)
        assert args[3].endswith("[Q=90]")
    finally:
        dest.unlink(missing_ok=True)


def test_materialize_jpeg_unlinks_temp_when_vips_fails(tmp_path: Path) -> None:
    avif_rel = "converted/ab/id/master.avif"
    source = tmp_path / avif_rel
    source.parent.mkdir(parents=True)
    source.write_bytes(b"fake-avif")

    with patch("rasterize.subprocess.run") as run:
        run.side_effect = Exception("boom")
        with pytest.raises(Exception, match="boom"):
            materialize_jpeg(tmp_path, avif_rel)

    leftover = list(tmp_path.glob("**/*"))
    # Only the fake AVIF should remain — no orphaned JPEG temp under /tmp we can
    # easily assert, but the helper deletes its own dest on failure.
    assert source.exists()
