# UploadSafety sidecar

Reference implementation of the [sidecar API](../docs/sidecar-api.md). It runs image classifiers and detectors with ONNX Runtime; PyTorch is only used while building the image, to convert the models.

## Models

Which models back which checks is private to each wiki (model names make crafting adversarial images easier), so they are not in this repository. Create `models.json` next to the Dockerfile (it is gitignored):

```json
{
	"nsfw": {
		"repo": "<org>/<model>",
		"revision": "<40-character commit hash>",
		"loader": "transformers",
		"sha256": { "model.safetensors": "<sha256 of the weights>" },
		"positive_label": "nsfw"
	},
	"nudity_regions": {
		"loader": "yolov8",
		"url": "https://api.github.com/repos/<owner>/<repo>/releases/assets/<id>",
		"sha256": "<sha256 of the ONNX file>",
		"model": "<name shown in /v1/info>",
		"revision": "<release tag>",
		"labels": { "<MODEL_LABEL>": "breast_exposed" }
	}
}
```

Classifiers (`nsfw`) come from Hugging Face:

| Field | Description |
| --- | --- |
| `repo`, `revision` | Hugging Face repository, pinned to a full commit hash. |
| `loader` | `transformers` (a ViT image classifier with `preprocessor_config.json`) or `timm` (a timm classifier whose `config.json` has `architecture`, `label_names` and `pretrained_cfg`). |
| `sha256` | Hash of `model.safetensors`, required. The build fails if the download differs. |
| `positive_label` | Label whose probability is the check's `score` (case-insensitive). |

The build downloads only the config files and `model.safetensors` at that revision. It never fetches pickle weights (`.bin`, `.pt`) and never runs code from the repository.

Detectors (`nudity_regions`) are Ultralytics YOLOv8 ONNX exports, downloaded as-is:

| Field | Description |
| --- | --- |
| `url` | HTTPS download URL. For GitHub release assets, use the API URL above (`/releases/download/...` redirects to a sign-in page without a session). |
| `sha256` | Hash of the ONNX file, required. |
| `model`, `revision` | Shown in `/v1/info` and check responses. |
| `labels` | Model label → contract label (see the [contract](../docs/sidecar-api.md#nudity_regions-stable)). Model labels left out are ignored. |

Labels and input size are read from the ONNX file's metadata. Images are padded to a square at the bottom right and resized to the model input, and for each label the sidecar reports its strongest region across all frames, down to `MIN_REGION_SCORE`.

When picking a model, check the publisher and the repository's commit history. Don't pin weights that changed in the last few days: a compromised upload needs time to be noticed.

`timm` models are trained on centre crops. The sidecar does not crop, because content at the edges of a wide or tall image would never be checked. Instead it covers the whole image with up to `MAX_TILES` square tiles and reports the worst one.

## Building and running

```sh
docker build -t upload-safety-sidecar .
docker build --build-arg MODELS=models.other.json -t upload-safety-sidecar:other .   # another model list
docker run --rm -p 127.0.0.1:8000:8000 upload-safety-sidecar
```

The build verifies each ONNX model against the original on a test batch and fails if their outputs differ. The runtime image needs no network access, and runs as `nobody`.

| Environment variable | Default | Description |
| --- | --- | --- |
| `API_KEY` | (none) | Require `Authorization: Bearer <key>`. |
| `MAX_UPLOAD_BYTES` | 10 MiB | Larger images are refused (`413`). |
| `MAX_PIXELS` | 50,000,000 | Larger dimensions are refused (`422 too_many_pixels`). |
| `MAX_FRAMES` | 64 | Distinct frames analysed per animation. |
| `MAX_DECODE_FRAMES` | 1000 | Animations with more frames are refused (`422 too_many_frames`). |
| `MAX_TILES` | 4 | Tiles per frame for crop-trained models. |
| `MIN_REGION_SCORE` | 0.2 | Weakest detector region reported (`min_score` in the response). |
| `BATCH_SIZE` | 8 | Images per inference call. Peak memory grows with it. |
| `THREADS` | 0 (automatic) | ONNX Runtime threads per inference. |
| `PREPACK` | 0 | `1` keeps a second, reordered copy of the weights: a few percent faster, but close to twice the memory. |
| `MEMORY_ARENA` | 0 | `1` enables ONNX Runtime's memory arena, which keeps the peak of the largest batch allocated. |

Before switching models or upgrading the runtime, compare the results with `eval/evaluate.py` (see CONTRIBUTING.md).
