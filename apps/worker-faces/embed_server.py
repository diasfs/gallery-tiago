"""Minimal HTTP API for face embeddings (Symfony admin search-by-face / detect)."""

from __future__ import annotations

import base64
import cgi
import json
import logging
import os
import threading
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from typing import Any

log = logging.getLogger("embed-server")


def _decode_image(image_bytes: bytes):
    import cv2
    import numpy as np

    arr = np.frombuffer(image_bytes, dtype=np.uint8)
    image = cv2.imdecode(arr, cv2.IMREAD_COLOR)
    if image is None:
        raise ValueError("could not decode image")
    return image


def pack_detected_face(image: Any, face: Any) -> dict[str, Any]:
    """Crop + encode one InsightFace detection (bbox is x1,y1,x2,y2)."""
    import cv2

    x1, y1, x2, y2 = (float(v) for v in face.bbox.tolist())
    ix1, iy1 = max(0, int(x1)), max(0, int(y1))
    ix2, iy2 = max(0, int(x2)), max(0, int(y2))
    crop = image[iy1:iy2, ix1:ix2]
    if crop.size == 0:
        raise ValueError("empty crop")
    ok, buf = cv2.imencode(".jpg", crop)
    if not ok:
        raise ValueError("could not encode crop")

    return {
        "embedding": face.normed_embedding.tolist(),
        "x": x1,
        "y": y1,
        "width": x2 - x1,
        "height": y2 - y1,
        "cropJpeg": base64.b64encode(buf.tobytes()).decode("ascii"),
    }


def embed_largest_face(image_bytes: bytes) -> list[float]:
    from main import get_face_app

    image = _decode_image(image_bytes)
    faces = get_face_app().get(image)
    if not faces:
        raise ValueError("no face detected")

    largest = max(
        faces,
        key=lambda face: (face.bbox[2] - face.bbox[0]) * (face.bbox[3] - face.bbox[1]),
    )
    return largest.normed_embedding.tolist()


def detect_all_faces(image_bytes: bytes) -> list[dict[str, Any]]:
    from main import get_face_app

    image = _decode_image(image_bytes)
    faces = get_face_app().get(image)
    packed: list[dict[str, Any]] = []
    for face in faces:
        try:
            packed.append(pack_detected_face(image, face))
        except ValueError:
            continue
    if not packed:
        raise ValueError("no face detected")
    return packed


def _read_upload(handler: BaseHTTPRequestHandler) -> bytes:
    content_type = handler.headers.get("Content-Type", "")
    if "multipart/form-data" not in content_type:
        raise ValueError("expected multipart file upload")

    form = cgi.FieldStorage(
        fp=handler.rfile,
        headers=handler.headers,
        environ={"REQUEST_METHOD": "POST", "CONTENT_TYPE": content_type},
    )
    item = form["file"] if "file" in form else None
    if item is None or not getattr(item, "file", None):
        raise ValueError("missing file field")
    return item.file.read()


def _json_response(handler: BaseHTTPRequestHandler, status: int, payload: dict[str, Any]) -> None:
    body = json.dumps(payload).encode()
    handler.send_response(status)
    handler.send_header("Content-Type", "application/json")
    handler.send_header("Content-Length", str(len(body)))
    handler.end_headers()
    handler.wfile.write(body)


class EmbedHandler(BaseHTTPRequestHandler):
    def log_message(self, format: str, *args) -> None:  # noqa: A003
        log.info("%s - %s", self.address_string(), format % args)

    def do_POST(self) -> None:
        path = self.path.split("?", 1)[0].rstrip("/")
        if path not in ("/embed", "/detect"):
            self.send_error(404)
            return

        try:
            image_bytes = _read_upload(self)
        except ValueError as exc:
            _json_response(self, 400, {"error": str(exc)})
            return

        try:
            if path == "/embed":
                payload: dict[str, Any] = {"embedding": embed_largest_face(image_bytes)}
            else:
                payload = {"faces": detect_all_faces(image_bytes)}
        except ValueError as exc:
            _json_response(self, 400, {"error": str(exc)})
            return

        _json_response(self, 200, payload)


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
