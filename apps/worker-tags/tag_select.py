"""Shared helpers for tag label selection and slugging."""

from __future__ import annotations

import re
import unicodedata
from typing import Sequence


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
