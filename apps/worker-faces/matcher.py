"""Pure decision logic for assigning a detected face to a Person.

Deliberately free of I/O and ML dependencies so it can be unit tested
without InsightFace/onnxruntime installed (see tests/test_matcher.py).
"""

from __future__ import annotations

from typing import Optional, Sequence

# (person_id, is_named, distance) -- distance from a pgvector `<=>` (cosine)
# nearest-neighbor query; lower is closer.
Neighbor = tuple[str, bool, float]
# x, y, width, height in image pixels
BBox = tuple[float, float, float, float]

ASSIGN_NAMED = "assign_named"
ASSIGN_CLUSTER = "assign_cluster"
CREATE_CLUSTER = "create_cluster"

# Same face location on reprocess / re-delivery → skip insert (keep person link).
OVERLAP_IOU_THRESHOLD = 0.5


def bbox_iou(a: BBox, b: BBox) -> float:
    ax1, ay1, aw, ah = a
    bx1, by1, bw, bh = b
    ax2, ay2 = ax1 + aw, ay1 + ah
    bx2, by2 = bx1 + bw, by1 + bh
    ix1, iy1 = max(ax1, bx1), max(ay1, by1)
    ix2, iy2 = min(ax2, bx2), min(ay2, by2)
    inter = max(0.0, ix2 - ix1) * max(0.0, iy2 - iy1)
    if inter <= 0.0:
        return 0.0
    union = aw * ah + bw * bh - inter
    return inter / union if union > 0.0 else 0.0


def overlaps_existing(
    bbox: BBox,
    existing: Sequence[BBox],
    threshold: float = OVERLAP_IOU_THRESHOLD,
) -> bool:
    return any(bbox_iou(bbox, other) >= threshold for other in existing)


def assign_person(
    embedding: list[float],
    neighbors: list[Neighbor],
    match_threshold: float,
    cluster_threshold: float,
) -> tuple[Optional[str], str]:
    """Decide which Person a detected face embedding belongs to.

    - Close enough to a **named** person (<= match_threshold) -> identify them.
    - Else close enough to an **unnamed** cluster (<= cluster_threshold) -> join it.
    - Else -> create_cluster (caller creates a new unnamed Person).

    match_threshold is intentionally stricter than cluster_threshold: naming
    someone wrongly is worse than temporarily grouping two people's unnamed
    clusters together, which an admin can split later.
    """
    del embedding  # decision only needs the neighbor distances, not the vector itself

    named = [n for n in neighbors if n[1]]
    if named:
        closest_named = min(named, key=lambda n: n[2])
        if closest_named[2] <= match_threshold:
            return closest_named[0], ASSIGN_NAMED

    unnamed = [n for n in neighbors if not n[1]]
    if unnamed:
        closest_unnamed = min(unnamed, key=lambda n: n[2])
        if closest_unnamed[2] <= cluster_threshold:
            return closest_unnamed[0], ASSIGN_CLUSTER

    return None, CREATE_CLUSTER


def resolve_person_for_detection(
    active_neighbors: list[Neighbor],
    trashed_neighbors: list[Neighbor],
    match_threshold: float,
    cluster_threshold: float,
) -> tuple[Optional[str], str, bool]:
    """Pick an active person, else a trashed person (soft-delete marking).

    Returns (person_id, action, soft_delete). soft_delete is True when the
    match is a person in the trash — caller should insert Face with deleted_at
    set so admin can restore from the photo's excluded-faces UI.

    Priority: active named > trashed named > active unnamed > trashed unnamed
    > create. Trashed named beats an active unnamed cluster so a trashed
    identity is not lost to a new/random cluster on reprocess.
    """
    active_id, active_action = assign_person([], active_neighbors, match_threshold, cluster_threshold)
    trashed_id, trashed_action = assign_person([], trashed_neighbors, match_threshold, cluster_threshold)

    if active_action == ASSIGN_NAMED:
        return active_id, active_action, False
    if trashed_action == ASSIGN_NAMED:
        return trashed_id, trashed_action, True
    if active_action == ASSIGN_CLUSTER:
        return active_id, active_action, False
    if trashed_action == ASSIGN_CLUSTER:
        return trashed_id, trashed_action, True
    return None, CREATE_CLUSTER, False
