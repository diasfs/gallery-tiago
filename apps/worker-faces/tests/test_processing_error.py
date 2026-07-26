import os
import sys

import pytest

sys.path.insert(0, os.path.join(os.path.dirname(__file__), ".."))

from db import clear_stage_error, set_faces_status, set_stage_error


def test_set_stage_error_adds_line():
    assert set_stage_error(None, "faces", "boom") == "faces: boom"


def test_set_replaces_and_keeps_other_stages():
    current = "media: disk\nfaces: old"

    assert set_stage_error(current, "faces", "new") == "media: disk\nfaces: new"


def test_clear_stage_error():
    assert clear_stage_error("media: disk\nfaces: boom", "faces") == "media: disk"
    assert clear_stage_error("faces: boom", "faces") is None


def test_set_stage_error_normalizes_internal_newlines_and_whitespace():
    assert set_stage_error(None, "faces", " first\r\n second \t value ") == (
        "faces: first second value"
    )


def test_stage_errors_preserve_legacy_lines():
    current = "legacy failure\nfaces: old"

    assert set_stage_error(current, "faces", "new") == "faces: new\nlegacy failure"
    assert clear_stage_error(current, "faces") == "legacy failure"


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

    MISSING = object()

    def cursor(self):
        return FakeCursor(self)


def test_set_faces_status_sets_prefixed_failure_and_preserves_other_errors():
    conn = FakeConnection("media: disk")

    set_faces_status(conn, "photo-1", "failed", error="bad\nface")

    assert conn.executions == [
        (
            "SELECT processing_error FROM photo WHERE id = %s",
            ("photo-1",),
        ),
        (
            "UPDATE photo SET faces_status = %s, processing_error = %s WHERE id = %s",
            ("failed", "media: disk\nfaces: bad face", "photo-1"),
        ),
    ]


def test_set_faces_status_done_clears_only_faces_error():
    conn = FakeConnection("media: disk\nfaces: bad face\ntags: timeout")

    set_faces_status(conn, "photo-1", "done")

    assert conn.executions[-1] == (
        "UPDATE photo SET faces_status = %s, processing_error = %s WHERE id = %s",
        ("done", "media: disk\ntags: timeout", "photo-1"),
    )
