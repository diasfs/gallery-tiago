"""Face worker entrypoint.

BRPOP `gallery:faces` -> rasterize AVIF master to a temp JPEG (OpenCV does
not reliably decode AVIF) -> InsightFace CPU detection -> pgvector nearest-
neighbor match via matcher.assign_person -> persist Face/Person rows -> set
the photo's faces_status to done/failed.

InsightFace/onnxruntime/cv2 are imported lazily inside get_face_app() /
process_photo() so this module -- and matcher.py in particular -- can be
imported and unit tested without those (heavy, CPU-only-by-default)
dependencies installed.
"""

from __future__ import annotations

import json
import logging
import os
import uuid
from pathlib import Path

import redis

import db
from matcher import ASSIGN_CLUSTER, ASSIGN_NAMED, assign_person
from rasterize import materialize_jpeg

logging.basicConfig(level=logging.INFO, format="%(asctime)s %(levelname)s %(message)s")
log = logging.getLogger("worker-faces")

QUEUE_KEY = "gallery:faces"
BRPOP_TIMEOUT_SECONDS = 5


class Config:
    def __init__(self) -> None:
        self.database_url = os.environ["DATABASE_URL"]
        self.redis_url = os.environ.get("REDIS_URL", "redis://redis:6379")
        self.media_root = Path(os.environ.get("MEDIA_ROOT", "/var/gallery/media"))
        self.match_threshold = float(os.environ.get("FACE_MATCH_THRESHOLD", "0.35"))
        self.cluster_threshold = float(os.environ.get("FACE_CLUSTER_THRESHOLD", "0.40"))
        self.embedding_dim = int(os.environ.get("FACE_EMBEDDING_DIM", "512"))


_face_app = None


def get_face_app():
    """Lazily construct the InsightFace analysis app (CPU execution provider).

    Model weights (buffalo_l, ~350MB) are downloaded to ~/.insightface on
    first call and cached there for subsequent runs -- see Dockerfile.
    """
    global _face_app
    if _face_app is None:
        from insightface.app import FaceAnalysis

        _face_app = FaceAnalysis(name="buffalo_l", providers=["CPUExecutionProvider"])
        _face_app.prepare(ctx_id=-1, det_size=(640, 640))
    return _face_app


def crop_path_for(face_id: str) -> str:
    """Store crops by face id so they outlive the source photo."""
    return f"faces/{face_id[:2]}/{face_id}.jpg"


def process_photo(conn, cfg: Config, photo_id: str) -> int:
    """Detect, match, and persist all faces for one photo. Returns face count."""
    import cv2

    avif_path, _original_path = db.get_photo_image_paths(conn, photo_id)
    if not avif_path:
        raise RuntimeError("photo has no avif_path")

    image_path = materialize_jpeg(cfg.media_root, avif_path)
    try:
        image = cv2.imread(str(image_path))
        if image is None:
            raise RuntimeError(f"could not read rasterized image at {image_path}")

        # Safe to call unconditionally: re-detects are idempotent because prior
        # auto-detected faces are cleared first (manual, no-embedding faces are
        # untouched -- see db.delete_auto_detected_faces).
        db.delete_auto_detected_faces(conn, photo_id, media_root=str(cfg.media_root))

        detected = get_face_app().get(image)

        for face in detected:
            embedding = face.normed_embedding.tolist()
            x1, y1, x2, y2 = (float(v) for v in face.bbox.tolist())
            confidence = float(face.det_score)

            neighbors = db.nearest_neighbors(conn, embedding, limit=5)
            person_id, action = assign_person(embedding, neighbors, cfg.match_threshold, cfg.cluster_threshold)

            if action not in (ASSIGN_NAMED, ASSIGN_CLUSTER):
                person_id = db.create_person(conn)

            face_id = str(uuid.uuid4())
            crop_relative = crop_path_for(face_id)
            crop_absolute = cfg.media_root / crop_relative

            ix1, iy1, ix2, iy2 = max(0, int(x1)), max(0, int(y1)), max(0, int(x2)), max(0, int(y2))
            crop = image[iy1:iy2, ix1:ix2]
            if crop.size > 0:
                crop_absolute.parent.mkdir(parents=True, exist_ok=True)
                cv2.imwrite(str(crop_absolute), crop)
            else:
                crop_relative = None

            db.insert_face(
                conn,
                face_id=face_id,
                photo_id=photo_id,
                person_id=person_id,
                bbox=(x1, y1, x2 - x1, y2 - y1),
                confidence=confidence,
                embedding=embedding,
                crop_path=crop_relative,
            )

        return len(detected)
    finally:
        image_path.unlink(missing_ok=True)


def handle_message(conn, cfg: Config, payload: bytes) -> None:
    try:
        data = json.loads(payload)
        photo_id = data["photo_id"]
    except (json.JSONDecodeError, KeyError, TypeError) as e:
        log.error("skipping malformed message on %s: %s (%r)", QUEUE_KEY, e, payload)
        return

    try:
        face_count = process_photo(conn, cfg, photo_id)
        db.set_faces_status(conn, photo_id, "done")
        log.info("photo %s: detected %d face(s), status=done", photo_id, face_count)
    except Exception as e:  # noqa: BLE001 - worker must survive a single bad photo
        log.exception("detect_faces failed for photo %s", photo_id)
        try:
            db.set_faces_status(conn, photo_id, "failed", error=str(e))
        except Exception:
            log.exception("also failed to record failure status for photo %s", photo_id)


def main() -> None:
    cfg = Config()
    conn = db.connect(cfg.database_url)
    r = redis.Redis.from_url(cfg.redis_url)

    log.info("worker-faces started; BRPOP %s", QUEUE_KEY)

    while True:
        item = r.brpop(QUEUE_KEY, timeout=BRPOP_TIMEOUT_SECONDS)
        if item is None:
            continue

        _, payload = item
        handle_message(conn, cfg, payload)


if __name__ == "__main__":
    main()
