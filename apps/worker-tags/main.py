"""Tag suggestion worker entrypoint.

BRPOP `gallery:tags` -> RAM++ open-vocabulary tagging on the photo's
**original** file -> get-or-create Tag by slug -> attach via photo_tag -> set
the photo's tags_status to done/failed.
"""

from __future__ import annotations

import json
import logging
import os
from pathlib import Path
from typing import Optional

import redis

import db
from tagger import select_tags, slugify_label, tag_image

logging.basicConfig(level=logging.INFO, format="%(asctime)s %(levelname)s %(message)s")
log = logging.getLogger("worker-tags")

QUEUE_KEY = "gallery:tags"
BRPOP_TIMEOUT_SECONDS = 5


class Config:
    def __init__(self) -> None:
        self.database_url = os.environ["DATABASE_URL"]
        self.redis_url = os.environ.get("REDIS_URL", "redis://redis:6379")
        self.media_root = Path(os.environ.get("MEDIA_ROOT", "/var/gallery/media"))
        self.tag_score_threshold = float(os.environ.get("TAG_SCORE_THRESHOLD", "0.0"))
        self.tag_max_count = int(os.environ.get("TAG_MAX_COUNT", "10"))


def resolve_image_path(media_root: Path, avif_path: Optional[str], original_path: str) -> Path:
    """Prefer the archived original (JPEG/PNG/WebP); AVIF is a fallback only."""
    if original_path:
        return media_root / original_path
    if avif_path:
        return media_root / avif_path
    raise RuntimeError("photo has neither original_path nor avif_path")


def process_photo(conn, cfg: Config, photo_id: str) -> int:
    """Tag one photo. Returns number of tags attached (including already-linked)."""
    avif_path, original_path = db.get_photo_image_paths(conn, photo_id)
    image_path = resolve_image_path(cfg.media_root, avif_path, original_path)

    if not image_path.is_file():
        raise RuntimeError(f"could not read image at {image_path}")

    scored = tag_image(image_path)
    labels = select_tags(scored, cfg.tag_score_threshold, cfg.tag_max_count)

    for label in labels:
        slug = slugify_label(label)
        if not slug:
            continue
        tag_id = db.get_or_create_tag(conn, name=label, slug=slug)
        db.attach_tag(conn, photo_id, tag_id)

    return len(labels)


def handle_message(conn, cfg: Config, payload: bytes) -> None:
    try:
        data = json.loads(payload)
        photo_id = data["photo_id"]
    except (json.JSONDecodeError, KeyError, TypeError) as e:
        log.error("skipping malformed message on %s: %s (%r)", QUEUE_KEY, e, payload)
        return

    try:
        count = process_photo(conn, cfg, photo_id)
        db.set_tags_status(conn, photo_id, "done")
        log.info("photo %s: applied %d tag(s), tags_status=done", photo_id, count)
    except Exception as e:  # noqa: BLE001 - worker must survive a single bad photo
        log.exception("suggest_tags failed for photo %s", photo_id)
        try:
            db.set_tags_status(conn, photo_id, "failed", error=str(e))
        except Exception:
            log.exception("also failed to record tags failure for photo %s", photo_id)


def main() -> None:
    cfg = Config()
    conn = db.connect(cfg.database_url)
    r = redis.Redis.from_url(cfg.redis_url)

    log.info("worker-tags started; BRPOP %s", QUEUE_KEY)

    while True:
        item = r.brpop(QUEUE_KEY, timeout=BRPOP_TIMEOUT_SECONDS)
        if item is None:
            continue

        _, payload = item
        handle_message(conn, cfg, payload)


if __name__ == "__main__":
    main()
