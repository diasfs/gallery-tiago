"""Load the Recognize Anything (RAM++) English tag vocabulary."""

from __future__ import annotations

from functools import lru_cache
from pathlib import Path


def _candidate_paths() -> list[Path]:
    paths: list[Path] = [Path(__file__).resolve().parent / "data" / "ram_tag_list.txt"]
    try:
        import ram  # type: ignore

        paths.append(Path(ram.__file__).resolve().parent / "data" / "ram_tag_list.txt")
    except Exception:
        pass
    return paths


@lru_cache(maxsize=1)
def load_ram_tag_list() -> tuple[str, ...]:
    """Return the RAM++ English tag list (immutable tuple for hashing/caching)."""
    for path in _candidate_paths():
        if path.is_file():
            labels = [
                line.strip()
                for line in path.read_text(encoding="utf-8").splitlines()
                if line.strip()
            ]
            if labels:
                return tuple(labels)
    raise FileNotFoundError(
        "ram_tag_list.txt not found. Place it under apps/worker-tags/data/ "
        "or install the ram package."
    )
