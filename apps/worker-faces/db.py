"""Database access for the face worker.

Raw SQL via psycopg3, not an ORM: this is a small, isolated consumer and
staying dependency-light matters more here than mirroring the Symfony app's
Doctrine mappings. Column/table names must stay in sync with
apps/api/migrations/Version20260719173837.php.
"""

from __future__ import annotations

from typing import Optional, Sequence
from urllib.parse import parse_qsl, urlencode, urlparse, urlunparse

import psycopg

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


def delete_auto_detected_faces(conn: psycopg.Connection, photo_id: str, media_root: Optional[str] = None) -> None:
    """Drop previously auto-detected faces (has_embedding = true) for a photo.

    Called before (re)running detection so re-delivered/reprocessed messages
    stay idempotent. Manually added faces (has_embedding = false) are left
    untouched, per design spec §9.

    When media_root is provided, on-disk crop files for the deleted faces are
    removed as well.
    """
    from pathlib import Path

    with conn.cursor() as cur:
        if media_root:
            cur.execute(
                "SELECT crop_path FROM face WHERE photo_id = %s AND has_embedding = true",
                (photo_id,),
            )
            for (crop_path,) in cur.fetchall():
                if crop_path:
                    path = Path(media_root) / crop_path
                    if path.is_file():
                        path.unlink(missing_ok=True)

        cur.execute("DELETE FROM face WHERE photo_id = %s AND has_embedding = true", (photo_id,))


def get_photo_image_paths(conn: psycopg.Connection, photo_id: str) -> tuple[Optional[str], str]:
    """Returns (avif_path, original_path) for the photo."""
    with conn.cursor() as cur:
        cur.execute("SELECT avif_path, original_path FROM photo WHERE id = %s", (photo_id,))
        row = cur.fetchone()
        if row is None:
            raise LookupError(f"photo {photo_id} not found")
        return row[0], row[1]


def set_photo_status(
    conn: psycopg.Connection,
    photo_id: str,
    status: str,
    error: Optional[str] = None,
) -> None:
    with conn.cursor() as cur:
        cur.execute(
            "UPDATE photo SET processing_status = %s, processing_error = %s WHERE id = %s",
            (status, error, photo_id),
        )
