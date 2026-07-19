import os
import sys

sys.path.insert(0, os.path.join(os.path.dirname(__file__), ".."))

from db import sanitize_database_url


def test_sanitize_database_url_strips_doctrine_params():
    url = (
        "postgresql://gallery:gallery@postgres:5432/gallery"
        "?serverVersion=16&charset=utf8"
    )

    sanitized = sanitize_database_url(url)

    assert sanitized == "postgresql://gallery:gallery@postgres:5432/gallery"
    assert "serverVersion" not in sanitized
    assert "charset" not in sanitized


def test_sanitize_database_url_keeps_libpq_params():
    url = (
        "postgresql://gallery:gallery@postgres:5432/gallery"
        "?serverVersion=16&charset=utf8&sslmode=require"
    )

    sanitized = sanitize_database_url(url)

    assert "serverVersion" not in sanitized
    assert "charset" not in sanitized
    assert sanitized.endswith("sslmode=require")


def test_sanitize_database_url_unchanged_without_query():
    url = "postgresql://gallery:gallery@postgres:5432/gallery"

    assert sanitize_database_url(url) == url
