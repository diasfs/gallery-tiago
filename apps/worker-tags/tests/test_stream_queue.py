import os
import sys

sys.path.insert(0, os.path.join(os.path.dirname(__file__), ".."))

import stream_queue


class FakeRedis:
    def __init__(self):
        self.acked = []
        self.deleted = []
        self.claimed = []
        self.read = []
        self.group_created = None
        self._claim_batch = []
        self._read_batch = []

    def xgroup_create(self, stream, group, id="0", mkstream=False):
        self.group_created = (stream, group, id, mkstream)

    def xautoclaim(self, stream, group, consumer, min_idle, start_id="0-0", count=1):
        self.claimed.append((stream, group, consumer, min_idle, start_id, count))
        return ["0-0", self._claim_batch, []]

    def xreadgroup(self, group, consumer, streams, count=1, block=None):
        self.read.append((group, consumer, streams, count, block))
        if not self._read_batch:
            return []
        stream = next(iter(streams))
        return [[stream, self._read_batch]]

    def xack(self, stream, group, msg_id):
        self.acked.append((stream, group, msg_id))

    def xdel(self, stream, msg_id):
        self.deleted.append((stream, msg_id))


def test_ensure_consumer_group_creates_with_mkstream():
    r = FakeRedis()
    stream_queue.ensure_consumer_group(r, "gallery:tags:stream", "tags-workers")
    assert r.group_created == ("gallery:tags:stream", "tags-workers", "0", True)


def test_ensure_consumer_group_ignores_busygroup():
    class BusyRedis(FakeRedis):
        def xgroup_create(self, *args, **kwargs):
            raise Exception("BUSYGROUP Consumer Group name already exists")

    stream_queue.ensure_consumer_group(BusyRedis(), "s", "g")


def test_consume_once_acks_after_successful_handle():
    r = FakeRedis()
    r._read_batch = [("1-0", {"photo_id": "photo-1"})]
    seen = []

    def handle(photo_id):
        seen.append(photo_id)
        return True

    assert stream_queue.consume_once(r, "s", "g", "c", handle, block_ms=10) is True
    assert seen == ["photo-1"]
    assert r.acked == [("s", "g", "1-0")]
    assert r.deleted == [("s", "1-0")]


def test_consume_once_leaves_unacked_when_handle_returns_false():
    r = FakeRedis()
    r._read_batch = [("2-0", {"photo_id": "photo-2"})]

    assert stream_queue.consume_once(r, "s", "g", "c", lambda _pid: False, block_ms=10)
    assert r.acked == []
    assert r.deleted == []


def test_consume_once_acks_malformed_without_photo_id():
    r = FakeRedis()
    r._read_batch = [("3-0", {"nope": "x"})]

    assert stream_queue.consume_once(r, "s", "g", "c", lambda _pid: False, block_ms=10)
    assert r.acked == [("s", "g", "3-0")]


def test_consume_once_prefers_stale_claim():
    r = FakeRedis()
    r._claim_batch = [("9-0", {"photo_id": "stale-1"})]
    r._read_batch = [("10-0", {"photo_id": "new-1"})]
    seen = []

    stream_queue.consume_once(r, "s", "g", "c", lambda pid: seen.append(pid) or True)
    assert seen == ["stale-1"]
    assert r.acked == [("s", "g", "9-0")]
