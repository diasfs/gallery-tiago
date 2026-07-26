"""Unit tests for db helpers that do not need a live Postgres.

get_or_create_tag / attach_tag SQL shapes are covered lightly via a fake
connection cursor so CI does not require the database for worker-tags tests.
"""

from __future__ import annotations

import os
import sys
from unittest.mock import MagicMock

sys.path.insert(0, os.path.join(os.path.dirname(__file__), ".."))

import db


def test_sanitize_database_url_drops_doctrine_params():
    url = "postgresql://gallery:gallery@postgres:5432/gallery?serverVersion=16&charset=utf8"
    cleaned = db.sanitize_database_url(url)
    assert "serverVersion" not in cleaned
    assert "charset" not in cleaned
    assert "gallery" in cleaned


def test_get_or_create_tag_returns_inserted_id():
    cur = MagicMock()
    cur.fetchone.return_value = ("tag-new-id",)
    cur.__enter__ = MagicMock(return_value=cur)
    cur.__exit__ = MagicMock(return_value=False)

    conn = MagicMock()
    conn.cursor.return_value = cur

    tag_id = db.get_or_create_tag(conn, name="dog", slug="dog")
    assert tag_id == "tag-new-id"
    assert cur.execute.call_count == 1


def test_get_or_create_tag_reuses_existing_when_conflict():
    cur = MagicMock()
    # First fetchone: INSERT ... RETURNING yields None (conflict).
    # Second fetchone: SELECT existing id.
    cur.fetchone.side_effect = [None, ("tag-existing-id",)]
    cur.__enter__ = MagicMock(return_value=cur)
    cur.__exit__ = MagicMock(return_value=False)

    conn = MagicMock()
    conn.cursor.return_value = cur

    tag_id = db.get_or_create_tag(conn, name="dog", slug="dog")
    assert tag_id == "tag-existing-id"
    assert cur.execute.call_count == 2


def test_attach_tag_inserts_with_on_conflict():
    cur = MagicMock()
    cur.__enter__ = MagicMock(return_value=cur)
    cur.__exit__ = MagicMock(return_value=False)

    conn = MagicMock()
    conn.cursor.return_value = cur

    db.attach_tag(conn, "photo-1", "tag-1")
    sql = cur.execute.call_args[0][0]
    assert "ON CONFLICT DO NOTHING" in sql
    assert cur.execute.call_args[0][1] == ("photo-1", "tag-1")
