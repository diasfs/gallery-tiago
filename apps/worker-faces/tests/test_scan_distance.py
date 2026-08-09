import os
import sys

sys.path.insert(0, os.path.join(os.path.dirname(__file__), ".."))

from scan import cosine_distance


def test_cosine_distance_zero_for_identical_vectors():
    vector = [1.0, 0.0, 0.0]
    assert cosine_distance(vector, vector) == 0.0


def test_cosine_distance_rejects_above_threshold():
    reference = [1.0, 0.0]
    close = [0.99, 0.14]  # normalized-ish pair with low distance
    far = [0.0, 1.0]
    threshold = 0.35

    assert cosine_distance(reference, close) <= threshold
    assert cosine_distance(reference, far) > threshold
