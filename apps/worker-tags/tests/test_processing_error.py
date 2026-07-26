import os
import sys

import pytest

sys.path.insert(0, os.path.join(os.path.dirname(__file__), ".."))

import main
from db import clear_stage_error, set_stage_error, set_tags_status


def test_set_stage_error_adds_line():
    assert set_stage_error(None, "tags", "boom") == "tags: boom"


def test_set_replaces_and_keeps_other_stages():
    current = "media: disk\ntags: old"

    assert set_stage_error(current, "tags", "new") == "media: disk\ntags: new"


def test_clear_stage_error():
    assert clear_stage_error("media: disk\ntags: boom", "tags") == "media: disk"
    assert clear_stage_error("tags: boom", "tags") is None


def test_set_stage_error_normalizes_internal_newlines_and_whitespace():
    assert set_stage_error(None, "tags", " first\r\n second \t value ") == (
        "tags: first second value"
    )


def test_stage_errors_preserve_legacy_lines():
    current = "legacy failure\ntags: old"

    assert set_stage_error(current, "tags", "new") == "tags: new\nlegacy failure"
    assert clear_stage_error(current, "tags") == "legacy failure"


def test_unknown_stage_is_rejected():
    with pytest.raises(ValueError, match="Unknown processing stage"):
        set_stage_error(None, "other", "boom")


class FakeCursor:
    def __init__(self, connection):
        self.connection = connection

    def __enter__(self):
        return self

    def __exit__(self, exc_type, exc_value, traceback):
        return False

    def execute(self, query, params):
        self.connection.executions.append((" ".join(query.split()), params))

    def fetchone(self):
        return self.connection.row


class FakeConnection:
    def __init__(self, processing_error):
        self.row = (
            None if processing_error is FakeConnection.MISSING else (processing_error,)
        )
        self.executions = []
        self.transaction_entered = False
        self.transaction_exited = False

    MISSING = object()

    def cursor(self):
        return FakeCursor(self)

    def transaction(self):
        connection = self

        class FakeTransaction:
            def __enter__(self):
                connection.transaction_entered = True

            def __exit__(self, exc_type, exc_value, traceback):
                connection.transaction_exited = True
                return False

        return FakeTransaction()


def test_set_tags_status_sets_prefixed_failure_and_preserves_other_errors():
    conn = FakeConnection("media: disk")

    set_tags_status(conn, "photo-1", "failed", error="bad\ntag")

    assert conn.transaction_entered
    assert conn.transaction_exited
    assert conn.executions == [
        (
            "SELECT processing_error FROM photo WHERE id = %s FOR UPDATE",
            ("photo-1",),
        ),
        (
            "UPDATE photo SET tags_status = %s, processing_error = %s WHERE id = %s",
            ("failed", "media: disk\ntags: bad tag", "photo-1"),
        ),
    ]


def test_set_tags_status_done_clears_only_tags_error():
    conn = FakeConnection("media: disk\nfaces: bad face\ntags: timeout")

    set_tags_status(conn, "photo-1", "done")

    assert conn.executions[-1] == (
        "UPDATE photo SET tags_status = %s, processing_error = %s WHERE id = %s",
        ("done", "media: disk\nfaces: bad face", "photo-1"),
    )


def test_handle_message_marks_tags_done_and_commits(monkeypatch):
    conn = type("FakeConnection", (), {"commit": lambda self: None})()
    statuses = []
    commits = []
    monkeypatch.setattr(main, "process_photo", lambda _conn, _cfg, _photo_id: 3)
    monkeypatch.setattr(
        main.db,
        "set_tags_status",
        lambda _conn, photo_id, status, error=None: statuses.append(
            (photo_id, status, error)
        ),
    )
    monkeypatch.setattr(conn, "commit", lambda: commits.append(True))

    main.handle_message(conn, object(), b'{"photo_id":"photo-1"}')

    assert statuses == [("photo-1", "done", None)]
    assert commits == [True]


def test_handle_message_marks_tags_failed_and_commits(monkeypatch):
    conn = type("FakeConnection", (), {"commit": lambda self: None})()
    statuses = []
    commits = []

    def fail_processing(_conn, _cfg, _photo_id):
        raise RuntimeError("tagger unavailable")

    monkeypatch.setattr(main, "process_photo", fail_processing)
    monkeypatch.setattr(
        main.db,
        "set_tags_status",
        lambda _conn, photo_id, status, error=None: statuses.append(
            (photo_id, status, error)
        ),
    )
    monkeypatch.setattr(conn, "commit", lambda: commits.append(True))

    main.handle_message(conn, object(), b'{"photo_id":"photo-1"}')

    assert statuses == [("photo-1", "failed", "tagger unavailable")]
    assert commits == [True]
