# UploadSafety

EXPERIMENTAL. MOSTLY A PoC. ALL FEATURES MENTIONED HERE MAY NOT BE FULLY IMPLEMENTED YET.

Formerly NSFWImageModeration. Screens user uploads against classifier models, deep-learning OCR models, abuse filters, and more checks and verifications.

Models run in a separate sidecar service that implements the [sidecar API](docs/sidecar-api.md) ([OpenAPI](docs/sidecar-api.openapi.yaml)). The sidecar only reports signals (scores, frames), as thresholds and policy stay in the wiki's configuration.

With this, animated images are checked frame by frame (which is expensive), so you may want to 

## Screening

UploadSafety screens automatically by default:

* **Core uploads** (Special:Upload, the upload API, UploadWizard and anything else going through `UploadVerifyFile`).
* **IntegratedProfiles avatars and banners**, through its `IntegratedProfilesVerifyImageUpload` hook (unreleased, needs IntegratedProfiles from `main`). Nothing extra to install; the handler is ignored when IntegratedProfiles is not loaded.

Formats the checks cannot screen (SVG, PDF, video, ...) are refused by default, since anything let through untouched is a way around screening. SVGs are the obvious case: MediaWiki lets them embed raster images, so any image could be wrapped in one to skip screening, effectively bypassing the entire point of this extension. Uploading unscreened formats therefore needs the `uploadsafety-upload-unscreened` right (sysops by default), for core uploads and IntegratedProfiles images alike. Everyone else gets an error listing the formats anyone can upload. Files already on the wiki are not affected. If regular users of your wiki heavily utilize SVGs, please open an issue on GitHub.

To open a format to everyone anyway, list its MIME type in `$wgUploadSafetyUnscreenedAllowed` (e.g. `[ 'audio/*' ]`), or set `$wgUploadSafetyBlockUnscreened = false` to allow every unscreened format. A file whose type is screenable but whose content cannot be decoded as an image follows `$wgUploadSafetyFailClosed` instead of passing unscreened.

Other extensions (e.g. a moderation queue) can screen on their own terms through the `UploadSafety.UploadScreener` service, and turn off automatic screening for a surface with `$wgUploadSafetyAutoScreen`.

Ensure `$wgUploadSafetyDebug` is disabled in production as it provides exact classification values that may help with circumvention or bypassing.

`$wgUploadSafetyFailClosed` defaults to `true` (reject when a check is down or returns a bad response). Set it to `false` to fail open.

### Formats

| Format | Handling |
| --- | --- |
| PNG, JPEG, GIF, WebP, AVIF, BMP, TIFF | Screened. Animated and multi-page images have their frames sampled (which can be expensive if done frequently). |
| SVG | Intentionally not screened. Instead, uploads are gated behind the `uploadsafety-upload-unscreened` right (see above). |
| Anything else (HEIC, JPEG XL, PDF, DjVu, video, ...) | Not screened; gated behind the same right unless listed in `$wgUploadSafetyUnscreenedAllowed`. |

Adding a format to `$wgFileExtensions` doesn't open it to everyone: until it's screened or listed in `$wgUploadSafetyUnscreenedAllowed`, only users with `uploadsafety-upload-unscreened` can upload it. AVIF is screened when uploaded as its own file. MediaWiki also allows AVIF (and other raster images) embedded inside SVGs, which the restriction covers. Video is deliberately left to manual moderation: it is rare on these wikis and expensive to screen.

## Rate limits and the rejection log

Automatic screening adds a few things on top of the checks:

* **Rejection log.** Every rejected upload is recorded in Special:Log/uploadsafety with the uploader, target, surface, the check that rejected it, its score, and the file's SHA-1. Rejected images themselves are never kept. The log needs the `uploadsafety-log` right (sysops by default); grant it to your own moderation groups with `$wgGroupPermissions`.
* **Rejection limits.** Only rejections count, so normal bursts of clean uploads are unaffected. Once reached, image uploads are refused without screening until the limit resets, and one `limited` entry is logged.
* **Screening limit.** A generous cap on images sent to the sidecar, so one account cannot tie it up.
* **Verdict cache.** Each verdict is cached for a day by SHA-1 and checks configuration. Re-uploading the same file is answered from the cache, logged as a repeat, and not counted again. Changing `$wgUploadSafetyChecks` or `$wgUploadSafetyMaxFrames` starts a fresh cache.

Defaults (`$wgRateLimits`, `[ count, seconds ]`):

| Action | `newbie` | `user` |
| --- | --- | --- |
| `uploadsafety-reject` | `[ 6, 900 ]` | `[ 12, 900 ]` |
| `uploadsafety-reject-daily` | `[ 15, 86400 ]` | `[ 30, 86400 ]` |
| `uploadsafety-screen` | `[ 100, 3600 ]` | `[ 100, 3600 ]` |

Users with `noratelimit` (sysops, bots) are exempt. There are deliberately no `ip`, `subnet` or `anon` limits: MediaWiki applies `ip` and `subnet` to newbies by address, so a school sharing one IP would share a limit, and `anon` is a single limit shared by all anonymous users. If your wiki allows anonymous uploads, add `ip` limits yourself.

Calling the `UploadSafety.UploadScreener` service directly (e.g. from a moderation queue) skips all of the above; use `UploadSafety.RejectionLog` if you want the same log.

## Configuration

| Setting | Default | Description |
| --- | --- | --- |
| `$wgUploadSafetySidecarUrl` | `http://nsfw_detector:8000` | Sidecar base URL. |
| `$wgUploadSafetySidecarApiKey` | `''` | Optional Bearer token for the sidecar. |
| `$wgUploadSafetyAutoScreen` | `[ 'upload' => true, 'avatar' => true, 'banner' => true ]` | Surfaces screened automatically. `upload` is core file uploads, `avatar` and `banner` are [IntegratedProfiles](https://github.com/obbywiki/mediawiki-extensions-IntegratedProfiles) images. Surfaces not listed are screened. |
| `$wgUploadSafetyChecks` | `[ 'nsfw' => [ 'threshold' => 0.5 ], 'nudity_regions' => [ 'thresholds' => [ 'genitalia_exposed' => 0.3, 'breast_exposed' => 0.3, 'buttocks_exposed' => 0.3, 'anus_exposed' => 0.3 ] ] ]` | Checks to run, as name => parameters. Setting it replaces the default entirely. A check the sidecar does not support is unavailable, which rejects every upload while `$wgUploadSafetyFailClosed` is on. |
| `$wgUploadSafetyMaxFrames` | `64` | Frames of an animated image to analyse (sampled evenly beyond this). |
| `$wgUploadSafetyTimeout` | `30` | Sidecar timeout in seconds. |
| `$wgUploadSafetyFailClosed` | `true` | Reject when the sidecar or a check is unavailable. |
| `$wgUploadSafetyBlockUnscreened` | `true` | Refuse formats the checks cannot screen unless the uploader has `uploadsafety-upload-unscreened`. |
| `$wgUploadSafetyUnscreenedAllowed` | `[]` | MIME types anyone may upload unscreened; `type/*` matches a whole type. |
| `$wgUploadSafetyAlwaysReject` | `false` | Reject every image upload without checking. |
| `$wgUploadSafetyDebug` | `false` | Show scores and failure details in the UI and logs. |
| `$wgUploadSafetyShowSidecarVersion` | `true` | List the sidecar and runtime versions on Special:Version (never service or model names). |

The log group is `UploadSafety`, and the API upload debug key is `uploadsafety`.

SUPPORT WILL NOT BE PROVIDED.

Current issues:

* All flagged images are rejected with no penalty and instant feedback. No override or review/approval is possible.