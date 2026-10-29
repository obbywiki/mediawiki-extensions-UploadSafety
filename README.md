# UploadSafety

EXPERIMENTAL. MOSTLY A PoC. ALL FEATURES MENTIONED HERE MAY NOT BE FULLY IMPLEMENTED YET.

Formerly NSFWImageModeration. Screens user uploads against classifier models, deep-learning OCR models, abuse filters, and more checks and verifications.

Models run in a separate **sidecar** service that implements the [sidecar API](docs/sidecar-api.md) ([OpenAPI](docs/sidecar-api.openapi.yaml)). The sidecar only reports signals (scores, frames); thresholds and policy stay in wiki configuration. Animated images are checked frame by frame.

Currently implemented checks: `nsfw` (designed for `Falconsai/nsfw_image_detection`).

Ensure `$wgUploadSafetyDebug` is disabled in production as it provides exact classification values that may help with circumvention or bypassing.

`$wgUploadSafetyFailClosed` defaults to `true` (reject when a check is down or returns a bad response). Set it to `false` to fail open.

## Configuration

| Setting | Default | Description |
| --- | --- | --- |
| `$wgUploadSafetySidecarUrl` | `http://nsfw_detector:8000` | Sidecar base URL. |
| `$wgUploadSafetySidecarApiKey` | `''` | Optional Bearer token for the sidecar. |
| `$wgUploadSafetyChecks` | `[ 'nsfw' => [ 'threshold' => 0.5 ] ]` | Checks to run, as name => parameters. Setting it replaces the default entirely. |
| `$wgUploadSafetyMaxFrames` | `64` | Frames of an animated image to analyse (sampled evenly beyond this). |
| `$wgUploadSafetyTimeout` | `30` | Sidecar timeout in seconds. |
| `$wgUploadSafetyFailClosed` | `true` | Reject when the sidecar or a check is unavailable. |
| `$wgUploadSafetyAlwaysReject` | `false` | Reject every image upload without checking. |
| `$wgUploadSafetyDebug` | `false` | Show scores and failure details in the UI and logs. |
| `$wgUploadSafetyShowSidecarVersion` | `true` | List sidecar service and runtime versions on Special:Version (never models). |

The log group is `UploadSafety`, and the API upload debug key is `uploadsafety`.

SUPPORT WILL NOT BE PROVIDED.

Current issues:

* All flagged images are rejected with no penalty and instant feedback. No override or review/approval is possible.