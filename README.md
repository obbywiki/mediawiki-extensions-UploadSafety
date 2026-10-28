# UploadSafety

EXPERIMENTAL. MOSTLY A PoC. ALL FEATURES MENTIONED HERE MAY NOT BE FULLY IMPLEMENTED YET.

Formerly NSFWImageModeration. Screens user uploads against classifier models, deep-learning OCR models, abuse filters, and more checks and verifications.

Automated and local image moderation using the [HF image-classification specification](https://huggingface.co/docs/inference-providers/main/tasks/image-classification). Designed for `Falconsai/nsfw_image_detection`.

Ensure `$wgUploadSafetyDebug` is disabled in production as it provides exact classification values that may help with circumvention or bypassing.

`$wgUploadSafetyFailClosed` defaults to `true` (reject when a check is down or returns a bad response). Set it to `false` to fail open.

## Configuration

| Setting | Formerly | Default |
| --- | --- | --- |
| `$wgUploadSafetyAlwaysReject` | `$wgNSFWImageModerationAlwaysReject` | `false` |
| `$wgUploadSafetyFailClosed` | `$wgNSFWImageModerationFailClosed` | `true` |
| `$wgUploadSafetyDebug` | `$wgNSFWImageModerationDebug` | `false` |
| `$wgUploadSafetyTimeout` | `$wgNSFWImageModerationTimeout` | `30` |
| `$wgUploadSafetyNsfwClassifierUrl` | `$wgNSFWImageModerationClassifierUrl` | `http://nsfw_detector:8000/v1/classify` |
| `$wgUploadSafetyNsfwClassifierApiKey` | `$wgNSFWImageModerationApiKey` | `''` |
| `$wgUploadSafetyNsfwThreshold` | `$wgNSFWImageModerationNsfwThreshold` | `0.5` |

The log group is now `UploadSafety`, and the API upload debug key is now `uploadsafety`.

SUPPORT WILL NOT BE PROVIDED.

Current issues:

* All flagged images are rejected with no penalty and instant feedback. No override or review/approval is possible.