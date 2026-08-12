"""Database access for the face worker.

Raw SQL via psycopg3, not an ORM: this is a small, isolated consumer and
staying dependency-light matters more here than mirroring the Symfony app's
Doctrine mappings. Column/table names must stay in sync with
apps/api/migrations/Version20260719173837.php.
"""

from __future__ import annotations

import re
from typing import Optional, Sequence
from urllib.parse import parse_qsl, urlencode, urlparse, urlunparse

import psycopg

STAGES = ("media", "faces", "tags")


def _assert_stage(stage: str) -> None:
    if stage not in STAGES:
        raise ValueError(f'Unknown processing stage "{stage}".')


def _error_lines(current: Optional[str]) -> dict[str, str]:
    lines: dict[str, str] = {}
    if current is None or not current.strip():
        return lines

    for raw_line in current.splitlines():
        line = raw_line.strip()
        if not line:
            continue
        matched_stage = next(
            (stage for stage in STAGES if line.startswith(f"{stage}:")),
            None,
        )
        if matched_stage is not None:
            lines[matched_stage] = line
        else:
            lines[f"_{line}"] = line

    return lines


def _join_error_lines(lines: dict[str, str]) -> str:
    ordered = [lines[stage] for stage in STAGES if stage in lines]
    ordered.extend(line for key, line in lines.items() if key not in STAGES)
    return "\n".join(ordered)


def set_stage_error(current: Optional[str], stage: str, message: str) -> str:
    _assert_stage(stage)
    normalized_message = re.sub(r"\s+", " ", message).strip()
    lines = _error_lines(current)
    lines[stage] = f"{stage}: {normalized_message}"
    return _join_error_lines(lines)


def clear_stage_error(current: Optional[str], stage: str) -> Optional[str]:
    _assert_stage(stage)
    lines = _error_lines(current)
    lines.pop(stage, None)
    joined = _join_error_lines(lines)
    return joined or None


# Query params understood by libpq connection URIs (kept when sanitizing).
_LIBPQ_QUERY_PARAMS = frozenset(
    {
        "host",
        "hostaddr",
        "port",
        "dbname",
        "user",
        "password",
        "channel_binding",
        "connect_timeout",
        "client_encoding",
        "options",
        "application_name",
        "fallback_application_name",
        "keepalives",
        "keepalives_idle",
        "keepalives_interval",
        "keepalives_count",
        "tcp_user_timeout",
        "replication",
        "gssencmode",
        "sslmode",
        "sslcert",
        "sslkey",
        "sslrootcert",
        "sslcrl",
        "sslcrldir",
        "sslpassword",
        "requiressl",
        "sslnegotiation",
        "target_session_attrs",
    }
)


def sanitize_database_url(database_url: str) -> str:
    """Return a libpq-compatible URI, dropping Doctrine-only query params."""
    parsed = urlparse(database_url)
    if not parsed.query:
        return database_url

    filtered = [
        (key, value)
        for key, value in parse_qsl(parsed.query, keep_blank_values=True)
        if key in _LIBPQ_QUERY_PARAMS
    ]
    return urlunparse(parsed._replace(query=urlencode(filtered)))


def connect(database_url: str) -> psycopg.Connection:
    return psycopg.connect(sanitize_database_url(database_url), autocommit=True)


def _vector_literal(embedding: Sequence[float]) -> str:
    return "[" + ",".join(repr(float(v)) for v in embedding) + "]"


def nearest_neighbors(
    conn: psycopg.Connection,
    embedding: Sequence[float],
    limit: int = 5,
) -> list[tuple[str, bool, float]]:
    """Nearest Face embeddings by cosine distance, joined to their Person.

    Returns (person_id, is_named, distance) tuples, closest first. Manually
    added faces (has_embedding = false) are excluded since they carry no
    vector to compare against.
    """
    with conn.cursor() as cur:
        cur.execute(
            """
            SELECT person.id::text, person.is_named, face.embedding <=> %s::vector AS dist
            FROM face
            JOIN person ON person.id = face.person_id
            WHERE face.has_embedding = true
              AND person.deleted_at IS NULL
            ORDER BY dist
            LIMIT %s
            """,
            (_vector_literal(embedding), limit),
        )
        return [(row[0], row[1], float(row[2])) for row in cur.fetchall()]


def create_person(conn: psycopg.Connection) -> str:
    """Create a new unnamed cluster Person and return its id."""
    with conn.cursor() as cur:
        cur.execute(
            "INSERT INTO person (id, name, is_named) VALUES (gen_random_uuid(), NULL, false) RETURNING id::text"
        )
        row = cur.fetchone()
        assert row is not None
        return row[0]


def insert_face(
    conn: psycopg.Connection,
    face_id: str,
    photo_id: str,
    person_id: Optional[str],
    bbox: tuple[float, float, float, float],
    confidence: float,
    embedding: Sequence[float],
    crop_path: Optional[str],
) -> None:
    x, y, width, height = bbox
    with conn.cursor() as cur:
        cur.execute(
            """
            INSERT INTO face (
                id, photo_id, person_id, x, y, width, height,
                crop_path, confidence, embedding, has_embedding
            )
            VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s::vector, true)
            """,
            (
                face_id,
                photo_id,
                person_id,
                x,
                y,
                width,
                height,
                crop_path,
                confidence,
                _vector_literal(embedding),
            ),
        )


def list_face_bboxes(conn: psycopg.Connection, photo_id: str) -> list[tuple[float, float, float, float]]:
    """Existing face boxes on a photo (x, y, width, height). Null boxes skipped."""
    with conn.cursor() as cur:
        cur.execute(
            """
            SELECT x, y, width, height
            FROM face
            WHERE photo_id = %s
              AND x IS NOT NULL AND y IS NOT NULL
              AND width IS NOT NULL AND height IS NOT NULL
            """,
            (photo_id,),
        )
        return [(float(r[0]), float(r[1]), float(r[2]), float(r[3])) for r in cur.fetchall()]


def get_photo_image_paths(conn: psycopg.Connection, photo_id: str) -> tuple[Optional[str], Optional[str]]:
    """Returns (avif_path, original_path) for the photo."""
    with conn.cursor() as cur:
        cur.execute("SELECT avif_path, original_path FROM photo WHERE id = %s", (photo_id,))
        row = cur.fetchone()
        if row is None:
            raise LookupError(f"photo {photo_id} not found")
        return row[0], row[1]


def get_processing_error(conn: psycopg.Connection, photo_id: str) -> Optional[str]:
    with conn.cursor() as cur:
        cur.execute("SELECT processing_error FROM photo WHERE id = %s", (photo_id,))
        row = cur.fetchone()
        return None if row is None else row[0]


def get_faces_status(conn: psycopg.Connection, photo_id: str) -> Optional[str]:
    with conn.cursor() as cur:
        cur.execute("SELECT faces_status FROM photo WHERE id = %s", (photo_id,))
        row = cur.fetchone()
        return None if row is None else row[0]


def claim_faces_detecting(conn: psycopg.Connection, photo_id: str) -> bool:
    """Mark faces_status=detecting when currently queued/detecting. Returns False if skipped."""
    with conn.cursor() as cur:
        cur.execute(
            """
            UPDATE photo
            SET faces_status = 'detecting'
            WHERE id = %s AND faces_status IN ('pending', 'queued', 'detecting')
            """,
            (photo_id,),
        )
        return cur.rowcount > 0


def set_faces_status(
    conn: psycopg.Connection,
    photo_id: str,
    status: str,
    error: Optional[str] = None,
) -> None:
    with conn.transaction():
        with conn.cursor() as cur:
            cur.execute(
                "SELECT processing_error FROM photo WHERE id = %s FOR UPDATE",
                (photo_id,),
            )
            row = cur.fetchone()
            current = None if row is None else row[0]

            if status in ("done", "disabled"):
                new_error = clear_stage_error(current, "faces")
            else:
                new_error = set_stage_error(current, "faces", error or "unknown error")

            cur.execute(
                "UPDATE photo SET faces_status = %s, processing_error = %s WHERE id = %s",
                (status, new_error, photo_id),
            )


def get_processing_settings(conn: psycopg.Connection) -> dict:
    """Return global AI processing flags. Defaults match ProcessingSettings entity."""
    with conn.cursor() as cur:
        cur.execute(
            """
            SELECT faces_enabled, tags_enabled, tag_detector
            FROM processing_settings
            WHERE id = 1
            """
        )
        row = cur.fetchone()
        if row is None:
            return {
                "faces_enabled": True,
                "tags_enabled": True,
                "tag_detector": "ram_plus",
            }
        return {
            "faces_enabled": bool(row[0]),
            "tags_enabled": bool(row[1]),
            "tag_detector": row[2] or "ram_plus",
        }


def get_face_scan(conn: psycopg.Connection, scan_id: str) -> Optional[dict]:
    with conn.cursor() as cur:
        cur.execute(
            """
            SELECT status, reference_embedding::text, threshold, total_photos, enqueued_photos, processed_photos
            FROM face_gallery_scan
            WHERE id = %s
            """,
            (scan_id,),
        )
        row = cur.fetchone()
        if row is None:
            return None
        embedding_text = row[1]
        assert embedding_text is not None
        embedding = [float(v) for v in embedding_text.strip("[]").split(",")]
        return {
            "status": row[0],
            "reference_embedding": embedding,
            "threshold": float(row[2]),
            "total_photos": int(row[3]),
            "enqueued_photos": int(row[4]),
            "processed_photos": int(row[5]),
        }


def upsert_face_scan_match(
    conn: psycopg.Connection,
    scan_id: str,
    photo_id: str,
    distance: float,
    bbox: tuple[float, float, float, float],
    embedding: Sequence[float],
    crop_path: Optional[str],
) -> bool:
    """Insert or improve a scan match. Returns True when this photo newly matched."""
    x, y, width, height = bbox
    with conn.cursor() as cur:
        cur.execute(
            """
            INSERT INTO face_gallery_scan_match (
                id, scan_id, photo_id, distance, x, y, width, height, embedding, crop_path, selected
            )
            VALUES (gen_random_uuid(), %s, %s, %s, %s, %s, %s, %s, %s::vector, %s, true)
            ON CONFLICT (scan_id, photo_id) DO UPDATE SET
                distance = EXCLUDED.distance,
                x = EXCLUDED.x,
                y = EXCLUDED.y,
                width = EXCLUDED.width,
                height = EXCLUDED.height,
                embedding = EXCLUDED.embedding,
                crop_path = EXCLUDED.crop_path
            WHERE face_gallery_scan_match.distance > EXCLUDED.distance
            RETURNING (xmax = 0) AS inserted
            """,
            (
                scan_id,
                photo_id,
                distance,
                x,
                y,
                width,
                height,
                _vector_literal(embedding),
                crop_path,
            ),
        )
        row = cur.fetchone()
        return bool(row[0]) if row is not None else False


def increment_face_scan_processed(conn: psycopg.Connection, scan_id: str, *, matched: bool) -> None:
    with conn.transaction():
        with conn.cursor() as cur:
            cur.execute(
                """
                UPDATE face_gallery_scan
                SET processed_photos = processed_photos + 1,
                    matched_photos = matched_photos + %s,
                    updated_at = NOW()
                WHERE id = %s
                RETURNING processed_photos, enqueued_photos, total_photos, status
                """,
                (1 if matched else 0, scan_id),
            )
            row = cur.fetchone()
            if row is None:
                return
            processed, enqueued, total, status = row
            if status in ("done", "failed", "cancelled"):
                return
            if processed >= total and enqueued >= total:
                cur.execute(
                    "UPDATE face_gallery_scan SET status = 'done', updated_at = NOW() WHERE id = %s",
                    (scan_id,),
                )


def mark_face_scan_failed(conn: psycopg.Connection, scan_id: str, error: str) -> None:
    with conn.cursor() as cur:
        cur.execute(
            "UPDATE face_gallery_scan SET status = 'failed', error = %s, updated_at = NOW() WHERE id = %s",
            (error[:2000], scan_id),
        )
