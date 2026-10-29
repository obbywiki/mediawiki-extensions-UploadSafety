# Sidecar API (v1)

UploadSafety does not run models itself. It sends each upload to a sidecar, an HTTP service that runs the checks and returns its own signals (scores, detected regions, text, etc.). The extension turns those signals into a verdict based on the wiki's configuration.

Any runtime can implement this contract. The machine-readable version is [`sidecar-api.openapi.yaml`](sidecar-api.openapi.yaml). This document takes precedence.

## Conventions

- Base URL is `$wgUploadSafetySidecarUrl` (e.g. `http://nsfw_detector:8000`). All paths below are relative to it.
- If `$wgUploadSafetySidecarApiKey` is set, every request carries `Authorization: Bearer <key>`. Sidecars with a key configured must answer `401` without it.
- JSON is UTF-8. Clients **must ignore unknown fields**, so adding fields is not a breaking change. Removing or changing the meaning of a field requires `/v2`.
- The sidecar decodes untrusted images. It should run without outbound network access, with memory limits, and must not trust the client-supplied MIME type.

## `POST /v1/check`

Screens one image.

### Request

`multipart/form-data` with two parts:

| Part | Content type | Description |
| --- | --- | --- |
| `image` | `application/octet-stream` (any) | Raw image bytes. |
| `request` | `application/json` | Request object, below. |

```json
{
  "checks": [ "nsfw" ],
  "mime": "image/gif",
  "surface": "upload",
  "max_frames": 64
}
```

| Field | Required | Description |
| --- | --- | --- |
| `checks` | yes | Non-empty list of unique check names to run (see [Checks](#checks)). |
| `mime` | no | MIME type MediaWiki detected. Informational only. |
| `surface` | no | Where the image came from: `upload`, `avatar`, `banner`, ... Informational only (logging, metrics). |
| `max_frames` | no | Upper bound on frames to analyse for animated images. The sidecar may clamp it to its own limit (reported in `/v1/info`). |

### Frames

Animated images (GIF, APNG, animated WebP) are a common bypass if only the first frame is checked, so sidecars must:

1. Decode every frame (up to their own hard decode limit, otherwise answer `422 too_many_frames`).
2. Ignore frame delays: a 0 ms frame counts like any other.
3. Drop exact-duplicate frames.
4. If more distinct frames remain than `max_frames`, sample them evenly, always including the first and last.
5. Run each check on every sampled frame and report the **worst** frame (as defined per check).

Still images are a single frame.

### Response `200`

```json
{
  "image": {
    "format": "gif",
    "width": 320,
    "height": 240,
    "frames": { "total": 120, "distinct": 40, "analyzed": 40 }
  },
  "checks": {
    "nsfw": {
      "status": "ok",
      "score": 0.93,
      "frame": 7,
      "model": "Falconsai/nsfw_image_detection",
      "labels": [ { "label": "nsfw", "score": 0.93 }, { "label": "normal", "score": 0.07 } ]
    },
    "ocr": {
      "status": "unsupported"
    }
  }
}
```

`image` describes what the sidecar decoded (its own sniffing, not `mime`). `frames.analyzed < frames.distinct` means the image was sampled rather than fully covered.

`checks` has **exactly one entry per requested check**, each with a `status`:

| `status` | Meaning | Extra fields |
| --- | --- | --- |
| `ok` | The check ran. | Check-specific signal fields. |
| `unsupported` | The sidecar does not implement this check. | none |
| `error` | The check failed for this image. | `error`: `{ "code": string, "message": string }` |

The extension treats `unsupported`, `error`, a missing entry or a malformed signal as **unavailable**, and `$wgUploadSafetyFailClosed` decides the outcome.

### Errors

Any non-`200` response means the whole request failed, and every requested check is unavailable. Bodies should look like:

```json
{ "error": { "code": "undecodable_image", "message": "cannot identify image file" } }
```

| Status | `code` | When |
| --- | --- | --- |
| `400` | `invalid_request` | Missing parts, malformed `request` JSON, bad `checks`. |
| `401` | `unauthorized` | Missing or wrong bearer token. |
| `413` | `image_too_large` | Byte size over the sidecar limit. |
| `422` | `undecodable_image` | Not an image the sidecar can decode. |
| `422` | `too_many_pixels` | Dimensions over the sidecar limit (decompression bombs). |
| `422` | `too_many_frames` | More frames than the sidecar will decode. |
| `5xx` | `internal` | Anything else. |

## `GET /v1/info`

Describes the sidecar. UploadSafety caches it briefly and shows the service version (labelled "UploadSafety sidecar", not `service.name`) and `runtime` on Special:Version. Service and model names stay off that page: model names make crafting adversarial images easier, and either can reveal what the wiki screens for.

```json
{
  "service": { "name": "upload-safety-sidecar", "version": "0.2.0", "url": "https://github.com/obbywiki/mediawiki-extensions-UploadSafety" },
  "runtime": [
    { "name": "Python", "version": "3.12.7", "url": "https://www.python.org/" },
    { "name": "PyTorch", "version": "2.5.1", "url": "https://pytorch.org/" }
  ],
  "checks": {
    "nsfw": { "model": "Falconsai/nsfw_image_detection", "revision": "abc123" }
  },
  "limits": { "max_bytes": 10485760, "max_frames": 64, "max_decode_frames": 1000, "max_pixels": 50000000 }
}
```

`checks` lists the checks this sidecar supports. `url` fields and `revision` are optional.

## `GET /health`

Liveness only, for container health checks: `200 {"status": "ok"}` once models are loaded. The extension does not call it.

## Checks

Each check has a fixed signal shape, so the model behind it can change without touching the extension.

### `nsfw` (stable)

Whole-image sexual-content classifier.

| Field | Type | Description |
| --- | --- | --- |
| `score` | number, 0 to 1 | Probability the image is sexual content. For animations, the highest score over analysed frames. |
| `frame` | integer | Index (in the original frame sequence) of the frame `score` came from. |
| `model` | string, optional | Model identifier. |
| `labels` | list of `{label, score}`, optional | Raw model output for that frame, for debugging. |

Extension config: `$wgUploadSafetyChecks['nsfw'] = [ 'threshold' => 0.5 ]` rejects when `score >= threshold`.

### Draft checks

Reserved names. Shapes may still change until they are implemented on both sides.

- **`nudity_regions`**: object detector for small regions a whole-image classifier misses. `regions`: list of `{ label, score, frame, box: [x, y, width, height] }` in source pixels.
- **`ocr`**: text in the image. `text` (all lines joined by `\n`), `lines`: list of `{ text, score, frame, box }`.
- **`qr`**: decoded QR and barcodes. `codes`: list of `{ format, text, frame, box }`.
