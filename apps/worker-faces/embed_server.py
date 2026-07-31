"""Minimal HTTP API for face embeddings (Symfony admin search-by-face)."""

from __future__ import annotations

import cgi
import json
import logging
import os
import threading
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

log = logging.getLogger("embed-server")


def embed_largest_face(image_bytes: bytes) -> list[float]:
    import cv2
    import numpy as np

    from main import get_face_app

    arr = np.frombuffer(image_bytes, dtype=np.uint8)
    image = cv2.imdecode(arr, cv2.IMREAD_COLOR)
    if image is None:
        raise ValueError("could not decode image")

    faces = get_face_app().get(image)
    if not faces:
        raise ValueError("no face detected")

    largest = max(
        faces,
        key=lambda face: (face.bbox[2] - face.bbox[0]) * (face.bbox[3] - face.bbox[1]),
    )
    return largest.normed_embedding.tolist()


class EmbedHandler(BaseHTTPRequestHandler):
    def log_message(self, format: str, *args) -> None:  # noqa: A003
        log.info("%s - %s", self.address_string(), format % args)

    def do_POST(self) -> None:
        if self.path.rstrip("/") != "/embed":
            self.send_error(404)
            return

        content_type = self.headers.get("Content-Type", "")
        if "multipart/form-data" not in content_type:
            self.send_error(400, "expected multipart file upload")
            return

        form = cgi.FieldStorage(
            fp=self.rfile,
            headers=self.headers,
            environ={"REQUEST_METHOD": "POST", "CONTENT_TYPE": content_type},
        )
        item = form["file"] if "file" in form else None
        if item is None or not getattr(item, "file", None):
            self.send_error(400, "missing file field")
            return

        try:
            embedding = embed_largest_face(item.file.read())
        except ValueError as exc:
            body = json.dumps({"error": str(exc)}).encode()
            self.send_response(400)
            self.send_header("Content-Type", "application/json")
            self.send_header("Content-Length", str(len(body)))
            self.end_headers()
            self.wfile.write(body)
            return

        body = json.dumps({"embedding": embedding}).encode()
        self.send_response(200)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        self.wfile.write(body)


def start_background_server(host: str = "0.0.0.0", port: int = 8090) -> ThreadingHTTPServer:
    server = ThreadingHTTPServer((host, port), EmbedHandler)
    thread = threading.Thread(target=server.serve_forever, daemon=True, name="embed-http")
    thread.start()
    log.info("embed HTTP server listening on %s:%s", host, port)
    return server


if __name__ == "__main__":
    logging.basicConfig(level=logging.INFO, format="%(asctime)s %(levelname)s %(message)s")
    start_background_server(port=int(os.environ.get("FACES_EMBED_PORT", "8090")))
    threading.Event().wait()
