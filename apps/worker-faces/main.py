"""Face worker entrypoint.

XREADGROUP `gallery:faces:stream` (consumer group `faces-workers`) ->
check processing_settings / claim detecting -> rasterize AVIF master to a
temp JPEG (OpenCV does not reliably decode AVIF) -> InsightFace CPU
detection -> pgvector nearest-neighbor match via matcher.assign_person ->
persist Face/Person rows -> set faces_status -> XACK only after a terminal
status is persisted.

InsightFace/onnxruntime/cv2 are imported lazily inside get_face_app() /
process_photo() so this module -- and matcher.py in particular -- can be
imported and unit tested without those (heavy, CPU-only-by-default)
dependencies installed.
"""

from __future__ import annotations

import logging
import os
import socket
import uuid
from pathlib import Path

import redis

import db
import scan as face_scan
import stream_queue
from matcher import ASSIGN_CLUSTER, ASSIGN_NAMED, assign_person, overlaps_existing
from rasterize import materialize_jpeg

logging.basicConfig(level=logging.INFO, format="%(asctime)s %(levelname)s %(message)s")
log = logging.getLogger("worker-faces")

STREAM_KEY = "gallery:faces:stream"
SCAN_STREAM_KEY = "gallery:face-scan:stream"
GROUP_NAME = "faces-workers"
SCAN_GROUP_NAME = "face-scan-workers"
TERMINAL_STATUSES = frozenset({"done", "failed", "disabled"})


class Config:
    def __init__(self) -> None:
        self.database_url = os.environ["DATABASE_URL"]
        self.redis_url = os.environ.get("REDIS_URL", "redis://redis:6379")
        self.media_root = Path(os.environ.get("MEDIA_ROOT", "/var/gallery/media"))
        self.match_threshold = float(os.environ.get("FACE_MATCH_THRESHOLD", "0.35"))
        self.cluster_threshold = float(os.environ.get("FACE_CLUSTER_THRESHOLD", "0.40"))
        self.embedding_dim = int(os.environ.get("FACE_EMBEDDING_DIM", "512"))
        self.consumer_name = os.environ.get(
            "FACES_CONSUMER_NAME",
            f"{socket.gethostname()}-{os.getpid()}",
        )
        self.min_idle_ms = int(os.environ.get("FACES_CLAIM_MIN_IDLE_MS", "60000"))


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

        # Keep existing faces (and person links). Only insert detections that
        # do not overlap a face already on this photo (reprocess / re-delivery).
        existing_bboxes = db.list_face_bboxes(conn, photo_id)
        detected = get_face_app().get(image)
        added = 0

        for face in detected:
            embedding = face.normed_embedding.tolist()
            x1, y1, x2, y2 = (float(v) for v in face.bbox.tolist())
            bbox = (x1, y1, x2 - x1, y2 - y1)
            if overlaps_existing(bbox, existing_bboxes):
                continue

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
                bbox=bbox,
                confidence=confidence,
                embedding=embedding,
                crop_path=crop_relative,
            )
            existing_bboxes.append(bbox)
            added += 1

        return added
    finally:
        image_path.unlink(missing_ok=True)


def handle_photo(conn, cfg: Config, photo_id: str) -> bool:
    """Process one photo_id. Returns True when the stream message may be ACKed."""
    status = db.get_faces_status(conn, photo_id)
    if status is None:
        log.warning("photo %s not found; acking stream message", photo_id)
        return True
    if status in TERMINAL_STATUSES:
        log.info("photo %s: faces_status=%s (terminal); skipping duplicate", photo_id, status)
        return True

    settings = db.get_processing_settings(conn)
    if not settings["faces_enabled"]:
        try:
            db.set_faces_status(conn, photo_id, "disabled")
        except Exception:
            log.exception("failed to record faces disabled for photo %s", photo_id)
            return False
        log.info("photo %s: faces disabled globally, faces_status=disabled", photo_id)
        return True

    if not db.claim_faces_detecting(conn, photo_id):
        status = db.get_faces_status(conn, photo_id)
        if status in TERMINAL_STATUSES:
            return True
        log.warning("photo %s: could not claim detecting (status=%s); leave unacked", photo_id, status)
        return False

    try:
        face_count = process_photo(conn, cfg, photo_id)
        db.set_faces_status(conn, photo_id, "done")
        log.info("photo %s: detected %d face(s), status=done", photo_id, face_count)
        return True
    except Exception as e:  # noqa: BLE001 - worker must survive a single bad photo
        log.exception("detect_faces failed for photo %s", photo_id)
        try:
            db.set_faces_status(conn, photo_id, "failed", error=str(e))
            return True
        except Exception:
            log.exception("also failed to record failure status for photo %s", photo_id)
            return False


def handle_scan_message(conn, cfg: Config, fields: dict) -> bool:
    job = stream_queue.scan_job_from_fields(fields)
    if job is None:
        return True
    scan_id, photo_id = job
    return face_scan.handle_scan_job(conn, cfg, scan_id, photo_id)


def consume_scan_once(r, conn, cfg: Config) -> bool:
    batch = stream_queue.claim_stale(r, SCAN_STREAM_KEY, SCAN_GROUP_NAME, cfg.consumer_name, min_idle_ms=cfg.min_idle_ms)
    if not batch:
        batch = stream_queue.read_pending(r, SCAN_STREAM_KEY, SCAN_GROUP_NAME, cfg.consumer_name)
    if not batch:
        batch = stream_queue.read_new(r, SCAN_STREAM_KEY, SCAN_GROUP_NAME, cfg.consumer_name, block_ms=0)
    if not batch:
        return False

    msg_id, fields = batch[0]
    try:
        ok = handle_scan_message(conn, cfg, fields)
    except Exception:
        log.exception("scan handler crashed for message %s; leaving unacked", msg_id)
        return True

    if ok:
        stream_queue.ack(r, SCAN_STREAM_KEY, SCAN_GROUP_NAME, msg_id)
    return True


def redis_client(redis_url: str) -> redis.Redis:
    # socket_timeout must stay above XREAD BLOCK (1000ms). Redis BLOCK 0 means
    # wait forever — never pass 0; omit BLOCK for non-blocking polls.
    return redis.Redis.from_url(
        redis_url,
        socket_connect_timeout=5,
        socket_timeout=10,
        health_check_interval=30,
        retry_on_timeout=True,
    )


def main() -> None:
    cfg = Config()
    embed_port = os.environ.get("FACES_EMBED_PORT")
    if embed_port:
        from embed_server import start_background_server

        start_background_server(port=int(embed_port))
    conn = db.connect(cfg.database_url)
    r = redis_client(cfg.redis_url)

    stream_queue.ensure_consumer_group(r, STREAM_KEY, GROUP_NAME)
    stream_queue.ensure_consumer_group(r, SCAN_STREAM_KEY, SCAN_GROUP_NAME)
    log.info(
        "worker-faces started; stream=%s group=%s consumer=%s; scan_stream=%s",
        STREAM_KEY,
        GROUP_NAME,
        cfg.consumer_name,
        SCAN_STREAM_KEY,
    )

    while True:
        try:
            scan_consumed = consume_scan_once(r, conn, cfg)
            if scan_consumed:
                continue
            stream_queue.consume_once(
                r,
                STREAM_KEY,
                GROUP_NAME,
                cfg.consumer_name,
                lambda photo_id: handle_photo(conn, cfg, photo_id),
                min_idle_ms=cfg.min_idle_ms,
                block_ms=1000,
            )
        except (redis.ConnectionError, redis.TimeoutError) as e:
            log.warning("redis connection lost (%s); reconnecting", e)
            r = redis_client(cfg.redis_url)


if __name__ == "__main__":
    main()
