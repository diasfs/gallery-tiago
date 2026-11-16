import os
import sys

sys.path.insert(0, os.path.join(os.path.dirname(__file__), ".."))

from matcher import (
    ASSIGN_CLUSTER,
    ASSIGN_NAMED,
    CREATE_CLUSTER,
    assign_person,
    bbox_iou,
    overlaps_existing,
    resolve_person_for_detection,
)

MATCH_THRESHOLD = 0.35
CLUSTER_THRESHOLD = 0.40


def test_assigns_to_named_person_when_within_match_threshold():
    neighbors = [
        ("person-unnamed", False, 0.10),
        ("person-named", True, 0.20),
    ]

    person_id, action = assign_person([0.0], neighbors, MATCH_THRESHOLD, CLUSTER_THRESHOLD)

    assert (person_id, action) == ("person-named", ASSIGN_NAMED)


def test_assigns_to_unnamed_cluster_when_no_named_match_but_close_unnamed():
    neighbors = [
        ("person-named-far", True, 0.60),  # too far to identify confidently
        ("person-unnamed", False, 0.38),  # within cluster threshold
    ]

    person_id, action = assign_person([0.0], neighbors, MATCH_THRESHOLD, CLUSTER_THRESHOLD)

    assert (person_id, action) == ("person-unnamed", ASSIGN_CLUSTER)


def test_creates_new_cluster_when_no_neighbor_within_thresholds():
    neighbors = [
        ("person-named-far", True, 0.90),
        ("person-unnamed-far", False, 0.85),
    ]

    person_id, action = assign_person([0.0], neighbors, MATCH_THRESHOLD, CLUSTER_THRESHOLD)

    assert person_id is None
    assert action == CREATE_CLUSTER


def test_creates_new_cluster_when_no_neighbors_at_all():
    person_id, action = assign_person([0.0], [], MATCH_THRESHOLD, CLUSTER_THRESHOLD)

    assert person_id is None
    assert action == CREATE_CLUSTER


def test_named_person_outside_match_threshold_does_not_fall_back_to_cluster():
    # A named person too far away to identify confidently must not be treated
    # as a cluster target -- only unnamed clusters get the looser
    # cluster_threshold, so this must fall through to create_cluster.
    neighbors = [("person-named", True, 0.38)]

    person_id, action = assign_person([0.0], neighbors, MATCH_THRESHOLD, CLUSTER_THRESHOLD)

    assert person_id is None
    assert action == CREATE_CLUSTER


def test_neighbor_order_in_input_does_not_matter():
    neighbors = [
        ("far", True, 0.99),
        ("close-named", True, 0.05),
        ("mid", False, 0.5),
    ]

    person_id, action = assign_person([0.0], neighbors, MATCH_THRESHOLD, CLUSTER_THRESHOLD)

    assert (person_id, action) == ("close-named", ASSIGN_NAMED)


def test_bbox_iou_identical_is_one():
    box = (10.0, 20.0, 40.0, 50.0)
    assert bbox_iou(box, box) == 1.0


def test_bbox_iou_disjoint_is_zero():
    assert bbox_iou((0.0, 0.0, 10.0, 10.0), (20.0, 20.0, 10.0, 10.0)) == 0.0


def test_overlaps_existing_skips_near_duplicate_bbox():
    existing = [(100.0, 100.0, 50.0, 60.0)]
    almost_same = (102.0, 101.0, 48.0, 58.0)
    far_away = (300.0, 300.0, 40.0, 40.0)

    assert overlaps_existing(almost_same, existing) is True
    assert overlaps_existing(far_away, existing) is False


def test_resolve_prefers_active_person_over_trashed():
    active = [("active-named", True, 0.10)]
    trashed = [("trashed-named", True, 0.05)]

    person_id, action, soft_delete = resolve_person_for_detection(
        active, trashed, MATCH_THRESHOLD, CLUSTER_THRESHOLD
    )

    assert (person_id, action, soft_delete) == ("active-named", ASSIGN_NAMED, False)


def test_resolve_prefers_trashed_named_over_active_unnamed_cluster():
    active = [("active-unnamed", False, 0.20)]
    trashed = [("trashed-named", True, 0.15)]

    person_id, action, soft_delete = resolve_person_for_detection(
        active, trashed, MATCH_THRESHOLD, CLUSTER_THRESHOLD
    )

    assert (person_id, action, soft_delete) == ("trashed-named", ASSIGN_NAMED, True)


def test_resolve_falls_back_to_trashed_person_as_soft_delete():
    active = [("active-far", True, 0.90)]
    trashed = [("trashed-named", True, 0.12)]

    person_id, action, soft_delete = resolve_person_for_detection(
        active, trashed, MATCH_THRESHOLD, CLUSTER_THRESHOLD
    )

    assert (person_id, action, soft_delete) == ("trashed-named", ASSIGN_NAMED, True)


def test_resolve_trashed_unnamed_cluster_within_cluster_threshold():
    active: list = []
    trashed = [("trashed-unnamed", False, 0.38)]

    person_id, action, soft_delete = resolve_person_for_detection(
        active, trashed, MATCH_THRESHOLD, CLUSTER_THRESHOLD
    )

    assert (person_id, action, soft_delete) == ("trashed-unnamed", ASSIGN_CLUSTER, True)


def test_resolve_creates_cluster_when_neither_active_nor_trashed_match():
    person_id, action, soft_delete = resolve_person_for_detection(
        [("far", True, 0.9)],
        [("far-trash", True, 0.85)],
        MATCH_THRESHOLD,
        CLUSTER_THRESHOLD,
    )

    assert person_id is None
    assert action == CREATE_CLUSTER
    assert soft_delete is False
