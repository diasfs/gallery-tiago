"""Tagging facade: select backend (RAM++ / MobileCLIP) and shared helpers.

Heavy torch imports stay inside backend modules so unit tests can import
select_tags / slugify_label without those dependencies installed.
"""

from __future__ import annotations

import logging
from pathlib import Path
from typing import Optional

from tag_select import select_tags, slugify_label

log = logging.getLogger("worker-tags")

_active_detector: Optional[str] = None

DETECTOR_RAM_PLUS = "ram_plus"
DETECTOR_MOBILECLIP_S0 = "mobileclip_s0"
DETECTOR_MOBILECLIP_S1 = "mobileclip_s1"

SUPPORTED_DETECTORS = {
    DETECTOR_RAM_PLUS,
    DETECTOR_MOBILECLIP_S0,
    DETECTOR_MOBILECLIP_S1,
}


def unload_all() -> None:
    """Unload every backend so only one model stays resident."""
    global _active_detector
    from backends import ram_plus, mobileclip

    ram_plus.unload()
    mobileclip.unload()
    _active_detector = None


def _ensure_backend(detector: str) -> None:
    global _active_detector
    if detector not in SUPPORTED_DETECTORS:
        raise ValueError(f"Unsupported tag detector: {detector}")
    if _active_detector == detector:
        return

    log.info("Switching tag detector from %s to %s", _active_detector, detector)
    unload_all()
    _active_detector = detector


def tag_image(image_path: Path, detector: str = DETECTOR_RAM_PLUS) -> list[tuple[str, float]]:
    """Run the configured detector and return (label, score) pairs."""
    _ensure_backend(detector)

    if detector == DETECTOR_RAM_PLUS:
        from backends import ram_plus

        return ram_plus.tag_image(image_path)

    from backends import mobileclip

    return mobileclip.tag_image(image_path, variant=detector)


def threshold_for(detector: str, configured: float) -> float:
    """Return the effective score threshold for a detector.

    RAM++ assigns uniform 1.0 scores, so the configured TAG_SCORE_THRESHOLD
    still applies. MobileCLIP uses cosine similarity; when the env default
    (0.0) is left unchanged, fall back to the MobileCLIP-specific default.
    """
    if detector in (DETECTOR_MOBILECLIP_S0, DETECTOR_MOBILECLIP_S1):
        from backends import mobileclip

        if configured <= 0.0:
            return mobileclip.default_threshold()
    return configured


# Re-export for callers/tests that historically imported from tagger.
__all__ = [
    "DETECTOR_MOBILECLIP_S0",
    "DETECTOR_MOBILECLIP_S1",
    "DETECTOR_RAM_PLUS",
    "SUPPORTED_DETECTORS",
    "select_tags",
    "slugify_label",
    "tag_image",
    "threshold_for",
    "unload_all",
]
