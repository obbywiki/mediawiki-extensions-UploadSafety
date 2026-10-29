# UploadSafety

EXPERIMENTAL. MOSTLY A PoC. ALL FEATURES MENTIONED HERE MAY NOT BE FULLY IMPLEMENTED YET.

Formerly NSFWImageModeration. Screens user uploads against classifier models, deep-learning OCR models, abuse filters, and more checks and verifications.

Models run in a separate sidecar service that implements the [sidecar API](docs/sidecar-api.md) ([OpenAPI](docs/sidecar-api.openapi.yaml)). The sidecar only reports signals (scores, frames), as thresholds and policy stay in the wiki's configuration.

With this, animated images are checked frame by frame (which is expensive), so you may want to 

Currently implemented checks: `nsfw` (designed for `Falconsai/nsfw_image_detection`).

## Screening

UploadSafety screens automatically by default:

* **Core uploads** (Special:Upload, the upload API, UploadWizard and anything else going through `UploadVerifyFile`).
* **IntegratedProfiles avatars and banners**, through its `IntegratedProfilesVerifyImageUpload` hook (unreleased, needs IntegratedProfiles from `main`). Nothing extra to install; the handler is ignored when IntegratedProfiles is not loaded.

Other extensions (e.g. a moderation queue) can screen on their own terms through the `UploadSafety.UploadScreener` service, and turn off automatic screening for a surface with `$wgUploadSafetyAutoScreen`.

Ensure `$wgUploadSafetyDebug` is disabled in production as it provides exact classification values that may help with circumvention or bypassing.

`$wgUploadSafetyFailClosed` defaults to `true` (reject when a check is down or returns a bad response). Set it to `false` to fail open.

## Configuration

| Setting | Default | Description |
| --- | --- | --- |
| `$wgUploadSafetySidecarUrl` | `http://nsfw_detector:8000` | Sidecar base URL. |
| `$wgUploadSafetySidecarApiKey` | `''` | Optional Bearer token for the sidecar. |
| `$wgUploadSafetyAutoScreen` | `[ 'upload' => true, 'avatar' => true, 'banner' => true ]` | Surfaces screened automatically. `upload` is core file uploads, `avatar` and `banner` are [IntegratedProfiles](https://github.com/obbywiki/mediawiki-extensions-IntegratedProfiles) images. Surfaces not listed are screened. |
| `$wgUploadSafetyChecks` | `[ 'nsfw' => [ 'threshold' => 0.5 ] ]` | Checks to run, as name => parameters. Setting it replaces the default entirely. |
| `$wgUploadSafetyMaxFrames` | `64` | Frames of an animated image to analyse (sampled evenly beyond this). |
| `$wgUploadSafetyTimeout` | `30` | Sidecar timeout in seconds. |
| `$wgUploadSafetyFailClosed` | `true` | Reject when the sidecar or a check is unavailable. |
| `$wgUploadSafetyAlwaysReject` | `false` | Reject every image upload without checking. |
| `$wgUploadSafetyDebug` | `false` | Show scores and failure details in the UI and logs. |
| `$wgUploadSafetyShowSidecarVersion` | `true` | List the sidecar and runtime versions on Special:Version (never service or model names). |

The log group is `UploadSafety`, and the API upload debug key is `uploadsafety`.

SUPPORT WILL NOT BE PROVIDED.

Current issues:

* All flagged images are rejected with no penalty and instant feedback. No override or review/approval is possible.