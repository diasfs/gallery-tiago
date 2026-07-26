import os
import sys

sys.path.insert(0, os.path.join(os.path.dirname(__file__), ".."))

from tagger import select_tags, slugify_label


def test_slugify_label_ascii_lowercase_hyphens():
    assert slugify_label("Golden Retriever") == "golden-retriever"
    assert slugify_label("  Dog!  ") == "dog"
    assert slugify_label("café") == "cafe"


def test_select_tags_applies_threshold_and_max():
    scored = [
        ("dog", 0.9),
        ("cat", 0.4),
        ("beach", 0.7),
        ("sky", 0.2),
    ]
    assert select_tags(scored, threshold=0.5, max_tags=2) == ["dog", "beach"]


def test_select_tags_dedupes_case_insensitive_keeping_higher_score():
    scored = [
        ("Dog", 0.6),
        ("dog", 0.9),
        ("DOG", 0.3),
    ]
    assert select_tags(scored, threshold=0.0, max_tags=5) == ["dog"]


def test_select_tags_skips_blank_and_respects_zero_max():
    assert select_tags([("  ", 1.0), ("ok", 1.0)], threshold=0.0, max_tags=5) == ["ok"]
    assert select_tags([("ok", 1.0)], threshold=0.0, max_tags=0) == []
