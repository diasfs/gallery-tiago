"""Tag suggestion worker entrypoint.

XREADGROUP `gallery:tags:stream` (consumer group `tags-workers`) ->
check processing_settings / claim detecting -> rasterize AVIF master
to a temp JPEG thumbnail -> selected detector (RAM++ / MobileCLIP) ->
get-or-create Tag by slug -> attach via photo_tag -> set tags_status ->
XACK only after a terminal status is persisted.
"""

from __future__ import annotations

import logging
import os
import socket
from pathlib import Path

import redis

import db
import stream_queue
from rasterize import materialize_jpeg
from tagger import (
    DETECTOR_MOBILECLIP_S0,
    DETECTOR_MOBILECLIP_S1,
    DETECTOR_RAM_PLUS,
    SUPPORTED_DETECTORS,
    select_tags,
    slugify_label,
    tag_image,
    threshold_for,
)

logging.basicConfig(level=logging.INFO, format="%(asctime)s %(levelname)s %(message)s")
log = logging.getLogger("worker-tags")

STREAM_KEY = "gallery:tags:stream"
GROUP_NAME = "tags-workers"
TERMINAL_STATUSES = frozenset({"done", "failed", "disabled"})

_DETECTOR_MAX_EDGE = {
    DETECTOR_RAM_PLUS: 384,
    DETECTOR_MOBILECLIP_S0: 256,
    DETECTOR_MOBILECLIP_S1: 256,
}


class Config:
    def __init__(self) -> None:
        self.database_url = os.environ["DATABASE_URL"]
        self.redis_url = os.environ.get("REDIS_URL", "redis://redis:6379")
        self.media_root = Path(os.environ.get("MEDIA_ROOT", "/var/gallery/media"))
        self.tag_score_threshold = float(os.environ.get("TAG_SCORE_THRESHOLD", "0.0"))
        self.tag_max_count = int(os.environ.get("TAG_MAX_COUNT", "10"))
        self.consumer_name = os.environ.get(
            "TAGS_CONSUMER_NAME",
            f"{socket.gethostname()}-{os.getpid()}",
        )
        self.min_idle_ms = int(os.environ.get("TAGS_CLAIM_MIN_IDLE_MS", "60000"))


def process_photo(conn, cfg: Config, photo_id: str, detector: str) -> int:
    """Tag one photo. Returns number of tags attached (including already-linked)."""
    avif_path, _original_path = db.get_photo_image_paths(conn, photo_id)
    if not avif_path:
        raise RuntimeError("photo has no avif_path")

    max_edge = _DETECTOR_MAX_EDGE.get(detector, 384)
    image_path = materialize_jpeg(cfg.media_root, avif_path, max_edge=max_edge)
    try:
        scored = tag_image(image_path, detector=detector)
        threshold = threshold_for(detector, cfg.tag_score_threshold)
        labels = select_tags(scored, threshold, cfg.tag_max_count)

        for label in labels:
            slug = slugify_label(label)
            if not slug:
                continue
            tag_id = db.get_or_create_tag(conn, name=label, slug=slug)
            db.attach_tag(conn, photo_id, tag_id)

        return len(labels)
    finally:
        image_path.unlink(missing_ok=True)


def handle_photo(conn, cfg: Config, photo_id: str) -> bool:
    """Process one photo_id. Returns True when the stream message may be ACKed."""
    status = db.get_tags_status(conn, photo_id)
    if status is None:
        log.warning("photo %s not found; acking stream message", photo_id)
        return True
    if status in TERMINAL_STATUSES:
        log.info("photo %s: tags_status=%s (terminal); skipping duplicate", photo_id, status)
        return True

    settings = db.get_processing_settings(conn)
    if not settings["tags_enabled"]:
        try:
            db.set_tags_status(conn, photo_id, "disabled")
        except Exception:
            log.exception("failed to record tags disabled for photo %s", photo_id)
            return False
        log.info("photo %s: tags disabled globally, tags_status=disabled", photo_id)
        return True

    if not db.claim_tags_detecting(conn, photo_id):
        # Race: another consumer finished or status changed under us.
        status = db.get_tags_status(conn, photo_id)
        if status in TERMINAL_STATUSES:
            return True
        log.warning("photo %s: could not claim detecting (status=%s); leave unacked", photo_id, status)
        return False

    detector = settings["tag_detector"]
    if detector not in SUPPORTED_DETECTORS:
        log.warning("unknown tag_detector %r; falling back to %s", detector, DETECTOR_RAM_PLUS)
        detector = DETECTOR_RAM_PLUS

    try:
        count = process_photo(conn, cfg, photo_id, detector)
        db.set_tags_status(conn, photo_id, "done")
        log.info(
            "photo %s: applied %d tag(s) via %s, tags_status=done",
            photo_id,
            count,
            detector,
        )
        return True
    except Exception as e:  # noqa: BLE001 - worker must survive a single bad photo
        log.exception("suggest_tags failed for photo %s", photo_id)
        try:
            db.set_tags_status(conn, photo_id, "failed", error=str(e))
            return True
        except Exception:
            log.exception("also failed to record tags failure for photo %s", photo_id)
            return False


def main() -> None:
    cfg = Config()
    conn = db.connect(cfg.database_url)
    r = redis.Redis.from_url(cfg.redis_url)

    stream_queue.ensure_consumer_group(r, STREAM_KEY, GROUP_NAME)
    log.info(
        "worker-tags started; stream=%s group=%s consumer=%s",
        STREAM_KEY,
        GROUP_NAME,
        cfg.consumer_name,
    )

    while True:
        stream_queue.consume_once(
            r,
            STREAM_KEY,
            GROUP_NAME,
            cfg.consumer_name,
            lambda photo_id: handle_photo(conn, cfg, photo_id),
            min_idle_ms=cfg.min_idle_ms,
        )


if __name__ == "__main__":
    main()
