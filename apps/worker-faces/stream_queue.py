"""Redis Streams consumer helpers shared by face/tag workers.

Delivery contract:
- Producers XADD `{photo_id}` to a stream.
- Workers read via a consumer group (XREADGROUP) and reclaim abandoned
  deliveries with XAUTOCLAIM.
- XACK (and optional XDEL) only after a terminal DB status is persisted.
"""

from __future__ import annotations

import logging
from typing import Any, Callable, Iterable, Optional

log = logging.getLogger("stream_queue")

# Idle time before an undelivered PEL entry can be claimed by another consumer.
DEFAULT_MIN_IDLE_MS = 60_000
DEFAULT_BLOCK_MS = 5_000
DEFAULT_BATCH = 1


def ensure_consumer_group(redis_client: Any, stream: str, group: str) -> None:
    """Create consumer group + stream if missing. Ignores BUSYGROUP."""
    try:
        redis_client.xgroup_create(stream, group, id="0", mkstream=True)
        log.info("created consumer group %s on %s", group, stream)
    except Exception as e:  # noqa: BLE001 - redis-py raises ResponseError subclasses
        if "BUSYGROUP" not in str(e):
            raise


def _normalize_entries(entries: Iterable) -> list[tuple[str, dict]]:
    """Normalize redis-py entry shapes to (msg_id, {field: value})."""
    out: list[tuple[str, dict]] = []
    for entry in entries or []:
        if not entry or len(entry) < 2:
            continue
        msg_id, fields = entry[0], entry[1]
        if isinstance(msg_id, bytes):
            msg_id = msg_id.decode()
        normalized: dict = {}
        if isinstance(fields, dict):
            for key, value in fields.items():
                k = key.decode() if isinstance(key, bytes) else key
                v = value.decode() if isinstance(value, bytes) else value
                normalized[k] = v
        out.append((str(msg_id), normalized))
    return out


def claim_stale(
    redis_client: Any,
    stream: str,
    group: str,
    consumer: str,
    min_idle_ms: int = DEFAULT_MIN_IDLE_MS,
    count: int = DEFAULT_BATCH,
) -> list[tuple[str, dict]]:
    """XAUTOCLAIM abandoned deliveries. Returns [(msg_id, fields), ...]."""
    # redis-py: xautoclaim(name, groupname, consumername, min_idle_time, start_id='0-0', count=1)
    result = redis_client.xautoclaim(
        stream,
        group,
        consumer,
        min_idle_ms,
        start_id="0-0",
        count=count,
    )
    # Return shape varies: (next_id, [entries], [deleted]) or [entries]
    if isinstance(result, (list, tuple)) and len(result) >= 2 and isinstance(result[1], list):
        return _normalize_entries(result[1])
    if isinstance(result, list):
        return _normalize_entries(result)
    return []


def read_new(
    redis_client: Any,
    stream: str,
    group: str,
    consumer: str,
    block_ms: int = DEFAULT_BLOCK_MS,
    count: int = DEFAULT_BATCH,
) -> list[tuple[str, dict]]:
    """XREADGROUP for never-delivered messages ('>')."""
    result = redis_client.xreadgroup(
        group,
        consumer,
        {stream: ">"},
        count=count,
        block=block_ms,
    )
    if not result:
        return []
    # [[stream_name, [(id, fields), ...]]]
    entries: list[tuple[str, dict]] = []
    for _stream_name, messages in result:
        entries.extend(_normalize_entries(messages))
    return entries


def ack(redis_client: Any, stream: str, group: str, msg_id: str, *, delete: bool = True) -> None:
    redis_client.xack(stream, group, msg_id)
    if delete:
        redis_client.xdel(stream, msg_id)


def photo_id_from_fields(fields: dict) -> Optional[str]:
    value = fields.get("photo_id")
    if value is None or value == "":
        return None
    return str(value)


def consume_once(
    redis_client: Any,
    stream: str,
    group: str,
    consumer: str,
    handle: Callable[[str], bool],
    *,
    min_idle_ms: int = DEFAULT_MIN_IDLE_MS,
    block_ms: int = DEFAULT_BLOCK_MS,
) -> bool:
    """Process up to one message (stale first, then new).

    `handle(photo_id)` must return True when the message may be ACKed
    (terminal status persisted, duplicate terminal, or malformed skip).
    Return False to leave the message pending for reclaim.
    """
    batch = claim_stale(redis_client, stream, group, consumer, min_idle_ms=min_idle_ms)
    if not batch:
        batch = read_new(redis_client, stream, group, consumer, block_ms=block_ms)
    if not batch:
        return False

    msg_id, fields = batch[0]
    photo_id = photo_id_from_fields(fields)
    if photo_id is None:
        log.error("skipping malformed stream message %s on %s: %r", msg_id, stream, fields)
        ack(redis_client, stream, group, msg_id)
        return True

    try:
        ok = handle(photo_id)
    except Exception:
        log.exception("handler crashed for photo %s (msg %s); leaving unacked", photo_id, msg_id)
        return True

    if ok:
        ack(redis_client, stream, group, msg_id)
    else:
        log.warning("leaving message %s unacked for photo %s", msg_id, photo_id)
    return True
