"""Database access for the tag suggestion worker.

Raw SQL via psycopg3 (same approach as apps/worker-faces/db.py).
Column/table names must stay in sync with the Symfony migrations.
"""

from __future__ import annotations

import re
from typing import Optional
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


def get_photo_image_paths(conn: psycopg.Connection, photo_id: str) -> tuple[Optional[str], Optional[str]]:
    """Returns (avif_path, original_path) for the photo."""
    with conn.cursor() as cur:
        cur.execute("SELECT avif_path, original_path FROM photo WHERE id = %s", (photo_id,))
        row = cur.fetchone()
        if row is None:
            raise LookupError(f"photo {photo_id} not found")
        return row[0], row[1]


def get_or_create_tag(conn: psycopg.Connection, name: str, slug: str) -> str:
    """Insert tag by slug if missing; return its id.

    When the slug already exists (e.g. admin translated `name`), the existing
    row is reused without overwriting the display name.
    """
    with conn.cursor() as cur:
        cur.execute(
            """
            INSERT INTO tag (id, name, slug)
            VALUES (gen_random_uuid(), %s, %s)
            ON CONFLICT (slug) DO NOTHING
            RETURNING id::text
            """,
            (name, slug),
        )
        row = cur.fetchone()
        if row is not None:
            return row[0]

        cur.execute("SELECT id::text FROM tag WHERE slug = %s", (slug,))
        existing = cur.fetchone()
        if existing is None:
            raise RuntimeError(f"tag slug {slug!r} missing after conflict")
        return existing[0]


def attach_tag(conn: psycopg.Connection, photo_id: str, tag_id: str) -> None:
    """Attach tag to photo; no-op if already linked."""
    with conn.cursor() as cur:
        cur.execute(
            """
            INSERT INTO photo_tag (photo_id, tag_id)
            VALUES (%s, %s)
            ON CONFLICT DO NOTHING
            """,
            (photo_id, tag_id),
        )


def set_tags_status(
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

            if status == "done":
                new_error = clear_stage_error(current, "tags")
            else:
                new_error = set_stage_error(current, "tags", error or "unknown error")

            cur.execute(
                "UPDATE photo SET tags_status = %s, processing_error = %s WHERE id = %s",
                (status, new_error, photo_id),
            )
