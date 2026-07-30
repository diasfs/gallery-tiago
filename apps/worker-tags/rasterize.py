"""Rasterize AVIF masters to temporary JPEGs sized for model input."""

from __future__ import annotations

import os
import subprocess
import tempfile
from pathlib import Path


def materialize_jpeg(
    media_root: Path,
    avif_relative: str,
    *,
    max_edge: int = 384,
    vips_binary: str = "vips",
) -> Path:
    """Decode an AVIF under media_root to a temp JPEG via libvips thumbnail.

    Fits the longest edge to ``max_edge`` (default 384 for RAM++ / MobileCLIP),
    preserving aspect ratio. Caller must unlink the returned path when finished.
    """
    if not avif_relative:
        raise RuntimeError("photo has no avif_path")

    source = media_root / avif_relative
    if not source.is_file():
        raise RuntimeError(f"AVIF master missing at {source}")

    if max_edge < 1:
        raise ValueError("max_edge must be >= 1")

    fd, name = tempfile.mkstemp(suffix=".jpg")
    os.close(fd)
    dest = Path(name)

    try:
        subprocess.run(
            [
                vips_binary,
                "thumbnail",
                str(source),
                f"{dest}[Q=90]",
                str(max_edge),
            ],
            check=True,
            capture_output=True,
            text=True,
        )
    except subprocess.CalledProcessError as e:
        dest.unlink(missing_ok=True)
        detail = (e.stderr or e.stdout or str(e)).strip()
        raise RuntimeError(f"vips failed to rasterize {source}: {detail}") from e
    except Exception:
        dest.unlink(missing_ok=True)
        raise

    return dest
