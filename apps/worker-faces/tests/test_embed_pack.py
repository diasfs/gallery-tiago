import os
import sys

import numpy as np

sys.path.insert(0, os.path.join(os.path.dirname(__file__), ".."))

from embed_server import pack_detected_face


class _Arr:
    def __init__(self, vals):
        self._vals = vals

    def tolist(self):
        return self._vals


class _Face:
    def __init__(self):
        self.bbox = _Arr([10.0, 20.0, 40.0, 60.0])
        self.normed_embedding = _Arr([1.0, 0.0, 0.0])


def test_pack_detected_face_returns_bbox_and_jpeg():
    image = np.zeros((80, 80, 3), dtype=np.uint8)
    image[20:60, 10:40] = 200

    packed = pack_detected_face(image, _Face())

    assert packed["x"] == 10.0
    assert packed["y"] == 20.0
    assert packed["width"] == 30.0
    assert packed["height"] == 40.0
    assert packed["embedding"] == [1.0, 0.0, 0.0]
    raw = __import__("base64").b64decode(packed["cropJpeg"])
    assert raw[:2] == b"\xff\xd8"
