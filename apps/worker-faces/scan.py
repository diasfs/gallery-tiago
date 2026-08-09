"""Gallery-wide face scan consumer (read-only on photo face graph)."""

from __future__ import annotations

import logging
import uuid
from typing import Optional

import db
from rasterize import materialize_jpeg

log = logging.getLogger("worker-faces.scan")


def cosine_distance(reference: list[float], candidate: list[float]) -> float:
    """Cosine distance between L2-normalized embeddings (matches pgvector <=>)."""
    dot = 0.0
    for left, right in zip(reference, candidate, strict=True):
        dot += float(left) * float(right)
    return 1.0 - dot


def scan_crop_path(scan_id: str, photo_id: str) -> str:
    return f"face-scans/{scan_id[:2]}/{scan_id}/{photo_id}.jpg"


def process_scan_photo(conn, cfg, scan_id: str, photo_id: str) -> bool:
    """Match faces in one photo against a scan reference. Returns True to ACK."""
    import cv2

    scan = db.get_face_scan(conn, scan_id)
    if scan is None:
        log.warning("scan %s not found; acking", scan_id)
        return True
    if scan["status"] in ("done", "failed", "cancelled"):
        log.info("scan %s status=%s (terminal); acking duplicate", scan_id, scan["status"])
        return True

    avif_path, _original_path = db.get_photo_image_paths(conn, photo_id)
    if not avif_path:
        db.increment_face_scan_processed(conn, scan_id, matched=False)
        return True

    reference = scan["reference_embedding"]
    threshold = scan["threshold"]

    image_path = materialize_jpeg(cfg.media_root, avif_path)
    try:
        image = cv2.imread(str(image_path))
        if image is None:
            raise RuntimeError(f"could not read rasterized image at {image_path}")

        from main import get_face_app

        detected = get_face_app().get(image)
        best: Optional[tuple[float, tuple[float, float, float, float], list[float]]] = None

        for face in detected:
            embedding = face.normed_embedding.tolist()
            distance = cosine_distance(reference, embedding)
            if distance > threshold:
                continue
            x1, y1, x2, y2 = (float(v) for v in face.bbox.tolist())
            bbox = (x1, y1, x2 - x1, y2 - y1)
            if best is None or distance < best[0]:
                best = (distance, bbox, embedding)

        matched = False
        if best is not None:
            distance, (x, y, width, height), embedding = best
            crop_relative = scan_crop_path(scan_id, photo_id)
            crop_absolute = cfg.media_root / crop_relative
            ix1, iy1 = max(0, int(x)), max(0, int(y))
            ix2, iy2 = max(0, int(x + width)), max(0, int(y + height))
            crop = image[iy1:iy2, ix1:ix2]
            if crop.size > 0:
                crop_absolute.parent.mkdir(parents=True, exist_ok=True)
                cv2.imwrite(str(crop_absolute), crop)
            else:
                crop_relative = None

            db.upsert_face_scan_match(
                conn,
                scan_id=scan_id,
                photo_id=photo_id,
                distance=distance,
                bbox=(x, y, width, height),
                embedding=embedding,
                crop_path=crop_relative,
            )
            matched = True

        db.increment_face_scan_processed(conn, scan_id, matched=matched)
        return True
    except Exception as e:  # noqa: BLE001
        log.exception("face scan failed for scan %s photo %s", scan_id, photo_id)
        db.mark_face_scan_failed(conn, scan_id, str(e))
        return True
    finally:
        image_path.unlink(missing_ok=True)


def handle_scan_job(conn, cfg, scan_id: str, photo_id: str) -> bool:
    return process_scan_photo(conn, cfg, scan_id, photo_id)
