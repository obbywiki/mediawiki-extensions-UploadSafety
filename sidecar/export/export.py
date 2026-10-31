#!/usr/bin/env python3
"""Converts the models listed in models.json to ONNX for serve.py. Runs in the Docker build stage only.

    export.py models.json OUT_DIR

models.json maps each check to a model. Classifiers come from Hugging Face, pinned to a commit and to the SHA-256 of their weights:

    "nsfw": {
      "repo": "<org>/<model>",
      "revision": "<40-character commit hash>",
      "loader": "transformers",            # or "timm"
      "sha256": { "model.safetensors": "<hash>" },
      "positive_label": "nsfw"             # label whose probability is the check's score (case-insensitive)
    }

Detectors are Ultralytics YOLOv8 ONNX exports, downloaded from a URL and pinned to their SHA-256:

    "nudity_regions": {
      "loader": "yolov8",
      "url": "https://.../model.onnx",
      "sha256": "<hash>",
      "model": "<name for /v1/info>",
      "revision": "<release tag>",
      "labels": { "<model label>": "<contract label>", ... }   # model labels left out are ignored
    }

Only config files, model.safetensors and ONNX files are downloaded. Pickle-based weights (.bin, .pt) are never fetched, and no repository code is run.
OUT_DIR receives <check>.onnx for every check, plus manifest.json describing labels and preprocessing for serve.py.
"""

from __future__ import annotations

import ast
import hashlib
import json
import math
import re
import sys
import urllib.request
from pathlib import Path
from typing import Any

import numpy as np
import onnxruntime
import torch
from huggingface_hub import hf_hub_download

WEIGHTS = "model.safetensors"
# region labels defined by the sidecar contract (docs/sidecar-api.md, nudity_regions)
REGION_LABELS = {
    "genitalia_exposed",
    "breast_exposed",
    "buttocks_exposed",
    "anus_exposed",
    "genitalia_covered",
    "breast_covered",
    "buttocks_covered",
    "anus_covered",
}
# PIL resampling filters by number, as used in transformers' preprocessor_config.json
PIL_RESAMPLE = {0: "nearest", 1: "lanczos", 2: "bilinear", 3: "bicubic", 4: "box", 5: "hamming"}


class Logits(torch.nn.Module):
    """Wraps a model so the ONNX graph takes pixel values and returns plain logits."""

    def __init__(self, model: torch.nn.Module, transformers_output: bool) -> None:
        super().__init__()
        self.model = model
        self.transformers_output = transformers_output

    def forward(self, pixel_values: torch.Tensor) -> torch.Tensor:
        if self.transformers_output:
            return self.model(pixel_values=pixel_values).logits
        return self.model(pixel_values)


def fetch(spec: dict[str, Any], filename: str) -> Path:
    path = Path(hf_hub_download(spec["repo"], filename, revision=spec["revision"]))
    expected = spec["sha256"].get(filename)
    if expected is not None:
        actual = hashlib.sha256(path.read_bytes()).hexdigest()
        if actual != expected.lower():
            raise SystemExit(f"{spec['repo']}/{filename}: sha256 {actual}, expected {expected}")
    return path


def load_transformers(spec: dict[str, Any]) -> tuple[torch.nn.Module, list[str], dict[str, Any]]:
    from transformers import AutoConfig, AutoModelForImageClassification

    config_path = fetch(spec, "config.json")
    processor = json.loads(fetch(spec, "preprocessor_config.json").read_text())
    fetch(spec, WEIGHTS)
    if processor.get("image_processor_type") != "ViTImageProcessor":
        raise SystemExit(f"{spec['repo']}: unsupported preprocessing {processor.get('image_processor_type')!r}")
    if not (processor.get("do_resize", True) and processor.get("do_rescale", True) and processor.get("do_normalize", True)):
        raise SystemExit(f"{spec['repo']}: unsupported preprocessing flags")

    config = AutoConfig.from_pretrained(config_path.parent)
    model = AutoModelForImageClassification.from_pretrained(config_path.parent, config=config, use_safetensors=True)
    labels = [config.id2label[index] for index in range(config.num_labels)]
    preprocess = {
        "size": [processor["size"]["height"], processor["size"]["width"]],
        "fit": "squash", # resized to exactly `size`, ignoring aspect ratio, like the model was trained
        "resample": PIL_RESAMPLE[processor.get("resample", 2)],
        "rescale": processor.get("rescale_factor", 1 / 255),
        "mean": processor["image_mean"],
        "std": processor["image_std"],
    }
    return Logits(model, True), labels, preprocess


def load_timm(spec: dict[str, Any]) -> tuple[torch.nn.Module, list[str], dict[str, Any]]:
    import timm
    from safetensors.torch import load_file

    config = json.loads(fetch(spec, "config.json").read_text())
    weights = fetch(spec, WEIGHTS)
    model = timm.create_model(config["architecture"], pretrained=False, num_classes=config["num_classes"])
    model.load_state_dict(load_file(weights))

    pretrained = config["pretrained_cfg"]
    _, height, width = pretrained["input_size"]
    if height != width:
        raise SystemExit(f"{spec['repo']}: only square inputs are supported")
    preprocess = {
        "size": [height, width],
        # these models are trained on center crops. cropping would let content at the edges of a wide image through, so serve.py covers the whole image with square tiles instead and keeps the worst one
        "fit": "tiles",
        "resample": pretrained.get("interpolation", "bicubic"),
        "rescale": 1 / 255,
        "mean": pretrained["mean"],
        "std": pretrained["std"],
    }
    return Logits(model, False), config["label_names"], preprocess


LOADERS = {"transformers": load_transformers, "timm": load_timm}


def export_detector(check: str, spec: dict[str, Any], out_dir: Path) -> dict[str, Any]:
    if not re.fullmatch(r"[0-9a-f]{64}", str(spec.get("sha256", "")).lower()):
        raise SystemExit(f"{check}: sha256 of the ONNX file is required")
    if not spec.get("model") or not spec.get("url", "").startswith("https://"):
        raise SystemExit(f"{check}: model and an https url are required")
    unknown = set(spec.get("labels", {}).values()) - REGION_LABELS
    if not spec.get("labels") or unknown:
        raise SystemExit(f"{check}: labels must map model labels to {sorted(REGION_LABELS)}, not {sorted(unknown)}")

    print(f"[export] {check}: downloading", flush=True)
    # GitHub release assets need the API URL (api.github.com/repos/<owner>/<repo>/releases/assets/<id>) and this Accept header
    headers = {"User-Agent": "upload-safety-sidecar-build", "Accept": "application/octet-stream"}
    request = urllib.request.Request(spec["url"], headers=headers)
    with urllib.request.urlopen(request, timeout=300) as response:
        data = response.read()
    actual = hashlib.sha256(data).hexdigest()
    if actual != spec["sha256"].lower():
        raise SystemExit(f"{check}: sha256 {actual}, expected {spec['sha256']} ({len(data)} bytes downloaded)")
    path = out_dir / f"{check}.onnx"
    path.write_bytes(data)

    session = onnxruntime.InferenceSession(str(path), providers=["CPUExecutionProvider"])
    metadata = session.get_modelmeta().custom_metadata_map
    if metadata.get("task") != "detect":
        raise SystemExit(f"{check}: not a YOLOv8 detection model (task {metadata.get('task')!r})")
    names = ast.literal_eval(metadata["names"])  # "{0: 'LABEL', ...}", literals only
    size = ast.literal_eval(metadata["imgsz"])[0]
    missing = set(spec["labels"]) - set(names.values())
    if missing:
        raise SystemExit(f"{check}: the model has no labels {sorted(missing)}")

    input_name = session.get_inputs()[0].name
    output = session.run(None, {input_name: np.zeros((2, 3, size, size), dtype=np.float32)})[0]
    if output.shape[:2] != (2, 4 + len(names)):
        raise SystemExit(f"{check}: unexpected output shape {output.shape}")
    print(f"[export] {check}: {len(names)} labels, {size}px input, output {output.shape}", flush=True)

    return {
        "kind": "detector",
        "file": path.name,
        "sha256": actual,
        "model": spec["model"],
        "revision": spec.get("revision", ""),
        "input": input_name,
        "size": size,
        # contract label for each model class, null for classes the check ignores
        "labels": [spec["labels"].get(names[index]) for index in range(len(names))],
    }


def export(check: str, spec: dict[str, Any], out_dir: Path) -> dict[str, Any]:
    if spec.get("loader") == "yolov8":
        return export_detector(check, spec, out_dir)
    if not re.fullmatch(r"[0-9a-f]{40}", spec.get("revision", "")):
        raise SystemExit(f"{check}: revision must be a full commit hash")
    if WEIGHTS not in spec.get("sha256", {}):
        raise SystemExit(f"{check}: sha256 of {WEIGHTS} is required")
    loader = LOADERS.get(spec.get("loader", ""))
    if loader is None:
        raise SystemExit(f"{check}: loader must be one of {sorted([*LOADERS, 'yolov8'])}")

    print(f"[export] {check}: loading", flush=True)
    model, labels, preprocess = loader(spec)
    model.eval()
    positive = [index for index, label in enumerate(labels) if label.lower() == spec["positive_label"].lower()]
    if len(positive) != 1:
        raise SystemExit(f"{check}: positive_label {spec['positive_label']!r} not in {labels}")

    height, width = preprocess["size"]
    sample = torch.rand(2, 3, height, width) * 2 - 1
    path = out_dir / f"{check}.onnx"
    print(f"[export] {check}: exporting", flush=True)
    torch.onnx.export(
        model,
        (sample,),
        str(path),
        input_names=["pixel_values"],
        output_names=["logits"],
        dynamic_shapes=({0: torch.export.Dim("batch", min=1, max=1024)},),
        dynamo=True,
        external_data=False,
    )

    # the ONNX graph has to give the same answers as the original model, on a batch size it was not traced with.
    check_input = torch.rand(3, 3, height, width) * 2 - 1
    with torch.inference_mode():
        expected = model(check_input).numpy()
    session = onnxruntime.InferenceSession(str(path), providers=["CPUExecutionProvider"])
    actual = session.run(["logits"], {"pixel_values": check_input.numpy()})[0]
    difference = float(np.max(np.abs(actual - expected)))
    print(f"[export] {check}: max logit difference {difference:.2e}", flush=True)
    if not math.isfinite(difference) or difference > 1e-3:
        raise SystemExit(f"{check}: ONNX output differs from the model by {difference}")

    return {
        "kind": "classifier",
        "file": path.name,
        "sha256": hashlib.sha256(path.read_bytes()).hexdigest(),
        "model": spec["repo"],
        "revision": spec["revision"],
        "labels": labels,
        "positive": positive[0],
        "preprocess": preprocess,
    }


def main() -> None:
    if len(sys.argv) != 3:
        raise SystemExit(__doc__)
    specs = json.loads(Path(sys.argv[1]).read_text())
    out_dir = Path(sys.argv[2])
    out_dir.mkdir(parents=True, exist_ok=True)
    if not specs:
        raise SystemExit("models.json lists no checks")

    manifest = {"checks": {check: export(check, spec, out_dir) for check, spec in specs.items()}}
    (out_dir / "manifest.json").write_text(json.dumps(manifest, indent=1) + "\n")
    print(f"[export] wrote {out_dir / 'manifest.json'}", flush=True)


if __name__ == "__main__":
    main()
