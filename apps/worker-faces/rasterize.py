"""Rasterize AVIF masters to temporary JPEGs for OpenCV / PIL consumers."""

from __future__ import annotations

import os
import subprocess
import tempfile
from pathlib import Path


def materialize_jpeg(
    media_root: Path,
    avif_relative: str,
    *,
    vips_binary: str = "vips",
) -> Path:
    """Decode an AVIF under media_root to a temp JPEG via libvips.

    Caller must unlink the returned path when finished (prefer try/finally).
    """
    if not avif_relative:
        raise RuntimeError("photo has no avif_path")

    source = media_root / avif_relative
    if not source.is_file():
        raise RuntimeError(f"AVIF master missing at {source}")

    fd, name = tempfile.mkstemp(suffix=".jpg")
    os.close(fd)
    dest = Path(name)

    try:
        subprocess.run(
            [vips_binary, "copy", str(source), f"{dest}[Q=90]"],
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
