import os
import sys

import pytest

sys.path.insert(0, os.path.join(os.path.dirname(__file__), ".."))

import main
from db import clear_stage_error, set_stage_error, set_tags_status
from tagger import threshold_for
from vocabulary import load_ram_tag_list


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

    def execute(self, query, params=None):
        self.connection.executions.append((" ".join(query.split()), params))
        q = " ".join(query.split())
        if "FROM processing_settings" in q:
            self.connection.row = self.connection.settings_row
        elif "SELECT processing_error" in q:
            self.connection.row = (
                None
                if self.connection.processing_error is FakeConnection.MISSING
                else (self.connection.processing_error,)
            )

    def fetchone(self):
        return self.connection.row


class FakeConnection:
    MISSING = object()

    def __init__(self, processing_error, settings_row=(True, True, "ram_plus")):
        self.processing_error = processing_error
        self.settings_row = settings_row
        self.row = None
        self.executions = []
        self.transaction_entered = False
        self.transaction_exited = False

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


def test_set_tags_status_disabled_clears_tags_error():
    conn = FakeConnection("media: disk\ntags: timeout")

    set_tags_status(conn, "photo-1", "disabled")

    assert conn.executions[-1] == (
        "UPDATE photo SET tags_status = %s, processing_error = %s WHERE id = %s",
        ("disabled", "media: disk", "photo-1"),
    )


def test_handle_photo_marks_tags_done(monkeypatch):
    conn = object()
    statuses = []
    seen = []

    def fake_process(_conn, _cfg, photo_id, detector):
        seen.append((photo_id, detector))
        return 3

    monkeypatch.setattr(main, "process_photo", fake_process)
    monkeypatch.setattr(main.db, "get_tags_status", lambda _conn, _pid: "queued")
    monkeypatch.setattr(main.db, "claim_tags_detecting", lambda _conn, _pid: True)
    monkeypatch.setattr(
        main.db,
        "get_processing_settings",
        lambda _conn: {
            "faces_enabled": True,
            "tags_enabled": True,
            "tag_detector": "mobileclip_s0",
        },
    )
    monkeypatch.setattr(
        main.db,
        "set_tags_status",
        lambda _conn, photo_id, status, error=None: statuses.append(
            (photo_id, status, error)
        ),
    )

    assert main.handle_photo(conn, object(), "photo-1") is True

    assert seen == [("photo-1", "mobileclip_s0")]
    assert statuses == [("photo-1", "done", None)]


def test_handle_photo_marks_disabled_when_tags_off(monkeypatch):
    conn = object()
    statuses = []
    called = []

    monkeypatch.setattr(
        main,
        "process_photo",
        lambda *_args, **_kwargs: called.append(True) or 0,
    )
    monkeypatch.setattr(main.db, "get_tags_status", lambda _conn, _pid: "queued")
    monkeypatch.setattr(
        main.db,
        "get_processing_settings",
        lambda _conn: {
            "faces_enabled": True,
            "tags_enabled": False,
            "tag_detector": "ram_plus",
        },
    )
    monkeypatch.setattr(
        main.db,
        "set_tags_status",
        lambda _conn, photo_id, status, error=None: statuses.append(
            (photo_id, status, error)
        ),
    )

    assert main.handle_photo(conn, object(), "photo-1") is True

    assert called == []
    assert statuses == [("photo-1", "disabled", None)]


def test_handle_photo_marks_tags_failed(monkeypatch):
    conn = object()
    statuses = []

    def fail_processing(_conn, _cfg, _photo_id, _detector):
        raise RuntimeError("tagger unavailable")

    monkeypatch.setattr(main, "process_photo", fail_processing)
    monkeypatch.setattr(main.db, "get_tags_status", lambda _conn, _pid: "queued")
    monkeypatch.setattr(main.db, "claim_tags_detecting", lambda _conn, _pid: True)
    monkeypatch.setattr(
        main.db,
        "get_processing_settings",
        lambda _conn: {
            "faces_enabled": True,
            "tags_enabled": True,
            "tag_detector": "ram_plus",
        },
    )
    monkeypatch.setattr(
        main.db,
        "set_tags_status",
        lambda _conn, photo_id, status, error=None: statuses.append(
            (photo_id, status, error)
        ),
    )

    assert main.handle_photo(conn, object(), "photo-1") is True

    assert statuses == [("photo-1", "failed", "tagger unavailable")]


def test_handle_photo_skips_terminal_duplicate(monkeypatch):
    called = []
    monkeypatch.setattr(main.db, "get_tags_status", lambda _conn, _pid: "done")
    monkeypatch.setattr(
        main,
        "process_photo",
        lambda *_a, **_k: called.append(True) or 0,
    )

    assert main.handle_photo(object(), object(), "photo-1") is True
    assert called == []


def test_handle_photo_leaves_unacked_when_failure_status_write_fails(monkeypatch):
    def fail_processing(_conn, _cfg, _photo_id, _detector):
        raise RuntimeError("tagger unavailable")

    def fail_status(_conn, _photo_id, status, error=None):
        if status == "failed":
            raise RuntimeError("db down")

    monkeypatch.setattr(main, "process_photo", fail_processing)
    monkeypatch.setattr(main.db, "get_tags_status", lambda _conn, _pid: "queued")
    monkeypatch.setattr(main.db, "claim_tags_detecting", lambda _conn, _pid: True)
    monkeypatch.setattr(
        main.db,
        "get_processing_settings",
        lambda _conn: {
            "faces_enabled": True,
            "tags_enabled": True,
            "tag_detector": "ram_plus",
        },
    )
    monkeypatch.setattr(main.db, "set_tags_status", fail_status)

    assert main.handle_photo(object(), object(), "photo-1") is False


def test_load_ram_tag_list_has_thousands_of_labels():
    labels = load_ram_tag_list()
    assert len(labels) > 4000
    assert "dog" in {label.lower() for label in labels} or any(
        "dog" in label.lower() for label in labels
    )


def test_threshold_for_mobileclip_uses_default_when_zero():
    assert threshold_for("mobileclip_s0", 0.0) == pytest.approx(0.20)
    assert threshold_for("mobileclip_s1", 0.35) == pytest.approx(0.35)
    assert threshold_for("ram_plus", 0.0) == pytest.approx(0.0)
