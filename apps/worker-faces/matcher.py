"""Pure decision logic for assigning a detected face to a Person.

Deliberately free of I/O and ML dependencies so it can be unit tested
without InsightFace/onnxruntime installed (see tests/test_matcher.py).
"""

from __future__ import annotations

from typing import Optional

# (person_id, is_named, distance) -- distance from a pgvector `<=>` (cosine)
# nearest-neighbor query; lower is closer.
Neighbor = tuple[str, bool, float]

ASSIGN_NAMED = "assign_named"
ASSIGN_CLUSTER = "assign_cluster"
CREATE_CLUSTER = "create_cluster"


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
