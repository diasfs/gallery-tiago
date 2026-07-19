import os
import sys

sys.path.insert(0, os.path.join(os.path.dirname(__file__), ".."))

from matcher import ASSIGN_CLUSTER, ASSIGN_NAMED, CREATE_CLUSTER, assign_person

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
