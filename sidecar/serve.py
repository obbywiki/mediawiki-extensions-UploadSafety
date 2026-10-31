#!/usr/bin/env python3
"""UploadSafety sidecar (API v1), running ONNX models.

Contract: https://github.com/obbywiki/mediawiki-extensions-UploadSafety/blob/main/docs/sidecar-api.md
Models come from $MODELS_DIR/manifest.json, written by export/export.py at build time.
"""

from __future__ import annotations

import hashlib
import io
import json
import math
import os
import sys
import warnings
from pathlib import Path
from typing import Any

import numpy as np
import onnxruntime
import uvicorn
from fastapi import FastAPI, Request
from fastapi.concurrency import run_in_threadpool
from fastapi.responses import JSONResponse
from PIL import Image, ImageSequence, UnidentifiedImageError

SERVICE_NAME = "upload-safety-sidecar"
SERVICE_VERSION = "0.3.0"
SERVICE_URL = "https://github.com/obbywiki/mediawiki-extensions-UploadSafety"

MODELS_DIR = Path(os.environ.get("MODELS_DIR", "/models"))
HOST = os.environ.get("HOST", "0.0.0.0")
PORT = int(os.environ.get("PORT", "8000"))
API_KEY = os.environ.get("API_KEY", "")
MAX_UPLOAD_BYTES = int(os.environ.get("MAX_UPLOAD_BYTES", str(10 * 1024 * 1024)))
MAX_PIXELS = int(os.environ.get("MAX_PIXELS", str(50_000_000)))
MAX_FRAMES = int(os.environ.get("MAX_FRAMES", "64"))
MAX_DECODE_FRAMES = int(os.environ.get("MAX_DECODE_FRAMES", "1000"))
MAX_TILES = int(os.environ.get("MAX_TILES", "4"))
# detector regions below this score are not reported (part of the nudity_regions signal as min_score)
MIN_REGION_SCORE = float(os.environ.get("MIN_REGION_SCORE", "0.2"))
BATCH_SIZE = int(os.environ.get("BATCH_SIZE", "8"))  # peak memory grows with it
THREADS = int(os.environ.get("THREADS", "0"))  # 0 lets ONNX Runtime pick
# ONNX Runtime's memory arena keeps the peak of the largest batch forever. without it, memory goes back down between requests
MEMORY_ARENA = os.environ.get("MEMORY_ARENA", "0") == "1"
# prepacking keeps a second, reordered copy of the weights (a few % faster, but nearly double the memory)
PREPACK = os.environ.get("PREPACK", "0") == "1"

# PIL raises DecompressionBombError past 2x this; we also check dimensions ourselves
Image.MAX_IMAGE_PIXELS = MAX_PIXELS
warnings.simplefilter("error", Image.DecompressionBombWarning)


RESAMPLE = {
    "nearest": Image.Resampling.NEAREST,
    "bilinear": Image.Resampling.BILINEAR,
    "bicubic": Image.Resampling.BICUBIC,
    "lanczos": Image.Resampling.LANCZOS,
    "box": Image.Resampling.BOX,
    "hamming": Image.Resampling.HAMMING,
}


def open_session(entry: dict[str, Any]) -> onnxruntime.InferenceSession:
    path = MODELS_DIR / entry["file"]
    if hashlib.sha256(path.read_bytes()).hexdigest() != entry["sha256"]:
        raise SystemExit(f"{path} does not match the manifest")
    options = onnxruntime.SessionOptions()
    options.intra_op_num_threads = THREADS
    options.inter_op_num_threads = 1
    options.enable_cpu_mem_arena = MEMORY_ARENA
    if not PREPACK:
        options.add_session_config_entry("session.disable_prepacking", "1")
    return onnxruntime.InferenceSession(str(path), options, providers=["CPUExecutionProvider"])


class Classifier:
    """One ONNX image classifier from the manifest: pixels in, label probabilities out."""

    def __init__(self, name: str, entry: dict[str, Any]) -> None:
        self.name = name
        self.model = entry["model"]
        self.revision = entry.get("revision", "")
        self.labels: list[str] = entry["labels"]
        self.positive: int = entry["positive"]

        preprocess = entry["preprocess"]
        self.height, self.width = preprocess["size"]
        self.fit: str = preprocess["fit"]
        self.resample = RESAMPLE[preprocess["resample"]]
        self.rescale = float(preprocess["rescale"])
        self.mean = np.array(preprocess["mean"], dtype=np.float32)
        self.std = np.array(preprocess["std"], dtype=np.float32)
        if self.fit not in ("squash", "tiles") or (self.fit == "tiles" and self.height != self.width):
            raise SystemExit(f"{name}: unsupported fit {self.fit!r}")

        self.session = open_session(entry)

    def views(self, frame: Image.Image) -> list[Image.Image]:
        """The model inputs for one frame: the whole frame squashed, or square tiles that together cover all of it."""
        if self.fit == "squash":
            return [frame.resize((self.width, self.height), self.resample)]

        size = self.width
        scale = size / min(frame.width, frame.height)
        resized = frame.resize(
            (max(size, round(frame.width * scale)), max(size, round(frame.height * scale))), self.resample
        )
        long_side = max(resized.width, resized.height)
        count = min(math.ceil(long_side / size), MAX_TILES)
        offsets = [(long_side - size) // 2] if count == 1 else [round(i * (long_side - size) / (count - 1)) for i in range(count)]
        if resized.width >= resized.height:
            return [resized.crop((offset, 0, offset + size, size)) for offset in offsets]
        return [resized.crop((0, offset, size, offset + size)) for offset in offsets]

    def pixels(self, view: Image.Image) -> np.ndarray:
        array = np.asarray(view, dtype=np.float32) * self.rescale
        return ((array - self.mean) / self.std).transpose(2, 0, 1)

    def probabilities(self, batch: list[np.ndarray]) -> np.ndarray:
        logits = self.session.run(["logits"], {"pixel_values": np.stack(batch)})[0].astype(np.float64)
        logits -= logits.max(axis=1, keepdims=True)
        exp = np.exp(logits)
        return exp / exp.sum(axis=1, keepdims=True)

    def __call__(self, frames: list[Image.Image], indices: list[int]) -> dict[str, Any]:
        """Scores every view of every frame and reports the worst one."""
        inputs = [(index, self.pixels(view)) for frame, index in zip(frames, indices) for view in self.views(frame)]
        best: tuple[float, int, list[float]] | None = None
        for start in range(0, len(inputs), BATCH_SIZE):
            chunk = inputs[start : start + BATCH_SIZE]
            for (index, _), row in zip(chunk, self.probabilities([pixels for _, pixels in chunk])):
                if best is None or row[self.positive] > best[0]:
                    best = (float(row[self.positive]), index, row.tolist())

        assert best is not None
        score, frame, row = best
        labels = sorted(
            ({"label": label, "score": value} for label, value in zip(self.labels, row)),
            key=lambda entry: entry["score"],
            reverse=True,
        )
        return {"status": "ok", "score": score, "frame": frame, "model": self.model, "labels": labels}


class Detector:
    """One YOLOv8 ONNX detector from the manifest: reports the strongest region for each contract label."""

    def __init__(self, name: str, entry: dict[str, Any]) -> None:
        self.name = name
        self.model = entry["model"]
        self.revision = entry.get("revision", "")
        self.size: int = entry["size"]
        self.input: str = entry["input"]
        # contract label per model class, None for classes this check ignores
        self.labels: list[str | None] = entry["labels"]
        self.session = open_session(entry)

    def pixels(self, frame: Image.Image) -> tuple[np.ndarray, float]:
        """Pads the frame to a square at the bottom right (as the model was trained), then resizes it to the model input."""
        side = max(frame.width, frame.height)
        canvas = Image.new("RGB", (side, side), (0, 0, 0))
        canvas.paste(frame, (0, 0))
        resized = canvas.resize((self.size, self.size), Image.Resampling.BILINEAR)
        return (np.asarray(resized, dtype=np.float32) / 255).transpose(2, 0, 1), side / self.size

    def __call__(self, frames: list[Image.Image], indices: list[int]) -> dict[str, Any]:
        best: dict[str, dict[str, Any]] = {}
        for start in range(0, len(frames), BATCH_SIZE):
            chunk = list(zip(frames[start : start + BATCH_SIZE], indices[start : start + BATCH_SIZE]))
            prepared = [self.pixels(frame) for frame, _ in chunk]
            # (batch, 4 box values + one score per class, candidate boxes)
            output = self.session.run(None, {self.input: np.stack([pixels for pixels, _ in prepared])})[0]

            for (frame, index), (_, scale), candidates in zip(chunk, prepared, output):
                for model_class, label in enumerate(self.labels):
                    if label is None:
                        continue
                    # each label's best candidate, even where another label scores higher there
                    scores = candidates[4 + model_class]
                    candidate = int(scores.argmax())
                    score = float(scores[candidate])
                    if score < MIN_REGION_SCORE or (label in best and best[label]["score"] >= score):
                        continue

                    centre_x, centre_y, width, height = (candidates[:4, candidate] * scale).tolist()
                    left, top = max(0.0, centre_x - width / 2), max(0.0, centre_y - height / 2)
                    right = min(float(frame.width), centre_x + width / 2)
                    bottom = min(float(frame.height), centre_y + height / 2)
                    best[label] = {
                        "label": label,
                        "score": score,
                        "frame": index,
                        "box": [round(left), round(top), max(0, round(right - left)), max(0, round(bottom - top))],
                    }

        regions = sorted(best.values(), key=lambda region: region["score"], reverse=True)
        return {"status": "ok", "min_score": MIN_REGION_SCORE, "regions": regions, "model": self.model}


KINDS = {"classifier": Classifier, "detector": Detector}


def load_checks() -> dict[str, Classifier | Detector]:
    onnxruntime.disable_telemetry_events()
    manifest = json.loads((MODELS_DIR / "manifest.json").read_text())
    checks = {}
    for name, entry in manifest["checks"].items():
        print(f"[{SERVICE_NAME}] loading check {name}", flush=True)
        checks[name] = KINDS[entry.get("kind", "classifier")](name, entry)
    print(f"[{SERVICE_NAME}] ready", flush=True)
    return checks


CHECKS = load_checks()

app = FastAPI(title=SERVICE_NAME, version=SERVICE_VERSION)


class ApiError(Exception):
    def __init__(self, status: int, code: str, message: str) -> None:
        super().__init__(message)
        self.status = status
        self.code = code
        self.message = message


def error_response(status: int, code: str, message: str) -> JSONResponse:
    return JSONResponse(status_code=status, content={"error": {"code": code, "message": message}})


@app.exception_handler(ApiError)
async def api_error_handler(_: Request, exc: ApiError) -> JSONResponse:
    return error_response(exc.status, exc.code, exc.message)


@app.exception_handler(Exception)
async def internal_error_handler(_: Request, exc: Exception) -> JSONResponse:
    print(f"[{SERVICE_NAME}] internal error: {exc!r}", file=sys.stderr, flush=True)
    return error_response(500, "internal", "internal error")


def require_auth(request: Request) -> None:
    if API_KEY and request.headers.get("authorization", "") != f"Bearer {API_KEY}":
        raise ApiError(401, "unauthorized", "missing or invalid bearer token")


# --- REQUEST PARSING


async def read_request(request: Request) -> tuple[bytes, dict[str, Any]]:
    if "multipart/form-data" not in (request.headers.get("content-type") or "").lower():
        raise ApiError(400, "invalid_request", "expected multipart/form-data with image and request parts")

    form = await request.form()
    image = form.get("image")
    raw = form.get("request")
    if image is None or not hasattr(image, "read"):
        raise ApiError(400, "invalid_request", "multipart part image is required")
    if raw is None:
        raise ApiError(400, "invalid_request", "multipart part request is required")

    if hasattr(raw, "read"):
        raw = (await raw.read()).decode("utf-8", "replace")
    try:
        body = json.loads(raw)
    except ValueError as exc:
        raise ApiError(400, "invalid_request", "request is not valid JSON") from exc
    if not isinstance(body, dict):
        raise ApiError(400, "invalid_request", "request must be a JSON object")

    checks = body.get("checks")
    if (
        not isinstance(checks, list)
        or not checks
        or not all(isinstance(name, str) and name for name in checks)
        or len(set(checks)) != len(checks)
    ):
        raise ApiError(400, "invalid_request", "checks must be a non-empty list of unique names")

    max_frames = body.get("max_frames", MAX_FRAMES)
    if not isinstance(max_frames, int) or isinstance(max_frames, bool) or max_frames < 1:
        raise ApiError(400, "invalid_request", "max_frames must be a positive integer")
    body["max_frames"] = min(max_frames, MAX_FRAMES)

    data = await image.read(MAX_UPLOAD_BYTES + 1)
    if not data:
        raise ApiError(400, "invalid_request", "image is empty")
    if len(data) > MAX_UPLOAD_BYTES:
        raise ApiError(413, "image_too_large", "image exceeds size limit")

    return data, body


# --- FRAMES


def open_image(data: bytes) -> Image.Image:
    try:
        image = Image.open(io.BytesIO(data))
    except (Image.DecompressionBombError, Image.DecompressionBombWarning) as exc:
        raise ApiError(422, "too_many_pixels", "image dimensions exceed limit") from exc
    except UnidentifiedImageError as exc:
        raise ApiError(422, "undecodable_image", "file is not a readable image") from exc
    except OSError as exc:
        raise ApiError(422, "undecodable_image", f"failed to decode image: {exc}") from exc

    if image.width * image.height > MAX_PIXELS:
        raise ApiError(422, "too_many_pixels", "image dimensions exceed limit")
    return image


def rgb(frame: Image.Image) -> Image.Image:
    if frame.mode in ("RGBA", "LA", "PA") or (frame.mode == "P" and "transparency" in frame.info):
        # flatten on white so transparent pixels do not turn into arbitrary colours
        frame = frame.convert("RGBA")
        canvas = Image.new("RGB", frame.size, (255, 255, 255))
        canvas.paste(frame, mask=frame.getchannel("A"))
        return canvas
    return frame.convert("RGB")


def distinct_frame_indices(image: Image.Image) -> tuple[int, list[int]]:
    """Decode every frame, returning (total frames, indices of the first occurrence of each distinct frame)."""
    seen: set[bytes] = set()
    distinct: list[int] = []
    total = 0
    try:
        for index, frame in enumerate(ImageSequence.Iterator(image)):
            if index >= MAX_DECODE_FRAMES:
                raise ApiError(422, "too_many_frames", "image has more frames than the decode limit")
            total += 1
            digest = hashlib.blake2b(rgb(frame).tobytes(), digest_size=16).digest()
            if digest not in seen:
                seen.add(digest)
                distinct.append(index)
    except ApiError:
        raise
    except (OSError, ValueError, EOFError) as exc:
        raise ApiError(422, "undecodable_image", f"failed to decode frame: {exc}") from exc

    if total == 0:
        raise ApiError(422, "undecodable_image", "image has no frames")
    return total, distinct


def sample_evenly(indices: list[int], limit: int) -> list[int]:
    """Up to `limit` indices spread evenly, always keeping the first and last."""
    if len(indices) <= limit:
        return indices
    if limit == 1:
        return [indices[0]]
    step = (len(indices) - 1) / (limit - 1)
    return sorted({indices[round(i * step)] for i in range(limit)})


def load_frames(image: Image.Image, indices: list[int]) -> list[Image.Image]:
    frames = []
    for index in indices: # ascending (so GIF seeking only moves forward)
        image.seek(index)
        frames.append(rgb(image))
    return frames


# --- ANALYSIS


def analyze(data: bytes, body: dict[str, Any]) -> dict[str, Any]:
    image = open_image(data)
    total, distinct = distinct_frame_indices(image)
    indices = sample_evenly(distinct, body["max_frames"])
    frames = load_frames(image, indices)

    results: dict[str, Any] = {}
    for name in body["checks"]:
        run = CHECKS.get(name)
        if run is None:
            results[name] = {"status": "unsupported"}
            continue
        try:
            results[name] = run(frames, indices)
        except Exception as exc:  # one failing check must not hide the others
            print(f"[{SERVICE_NAME}] check {name} failed: {exc!r}", file=sys.stderr, flush=True)
            results[name] = {"status": "error", "error": {"code": "inference_failed", "message": str(exc)[:300]}}

    return {
        "image": {
            "format": (image.format or "unknown").lower(),
            "width": image.width,
            "height": image.height,
            "frames": {"total": total, "distinct": len(distinct), "analyzed": len(indices)},
        },
        "checks": results,
    }


# --- ROUTES


@app.get("/health")
def health() -> dict[str, str]:
    return {"status": "ok"}


@app.get("/v1/info")
def info(request: Request) -> dict[str, Any]:
    require_auth(request)
    return {
        "service": {"name": SERVICE_NAME, "version": SERVICE_VERSION, "url": SERVICE_URL},
        "runtime": [
            {"name": "Python", "version": "{}.{}.{}".format(*sys.version_info[:3]), "url": "https://www.python.org/"},
            {"name": "ONNX Runtime", "version": onnxruntime.__version__, "url": "https://onnxruntime.ai/"},
        ],
        "checks": {name: {"model": check.model, "revision": check.revision} for name, check in CHECKS.items()},
        "limits": {
            "max_bytes": MAX_UPLOAD_BYTES,
            "max_frames": MAX_FRAMES,
            "max_decode_frames": MAX_DECODE_FRAMES,
            "max_pixels": MAX_PIXELS,
        },
    }


@app.post("/v1/check")
async def check(request: Request) -> dict[str, Any]:
    require_auth(request)
    data, body = await read_request(request)
    return await run_in_threadpool(analyze, data, body)


if __name__ == "__main__":
    uvicorn.run(app, host=HOST, port=PORT, log_level="info")
