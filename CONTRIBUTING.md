# UploadSafety

MediaWiki 1.46+ extension.

# Coding style

Apply these rules to code you write in this repo. Do not rename third-party or MediaWiki APIs to match them, obviously.

## Naming (`snake_case`)

Use `snake_case` for variables, parameters, and functions you define.

**With these exceptions:**

- PHP namespaces and classes: `MediaWiki\Extension\UploadSafety\...`
- MediaWiki hook methods and extension surface (`onUploadVerifyFile`, ...)
- Config registry names (`UploadSafetyFailClosed`)

As for everything else, see: <https://github.com/obbywiki/standards>

# Layout

- `docs/sidecar-api.md` is the contract with the sidecar service (`docs/sidecar-api.openapi.yaml` is the machine-readable version). Change the contract there first.
- `src/Sidecar/` is the HTTP client for that contract (`SidecarClient`), its response model, and the cached `/v1/info` used on Special:Version.
- `src/UploadScreener.php` sends an upload to the sidecar once for every check in `$wgUploadSafetyChecks`, then decides the outcome (fail open/closed, always-reject, debug detail).
- `src/Check/` turns signals into verdicts. Each check has a `CheckEvaluator` (e.g. `NsfwEvaluator`) that returns a `CheckResult` with a `CheckVerdict` (`Pass`, `Reject` or `Unavailable`). Register new evaluators in `src/ServiceWiring.php`.
- `src/AutoScreening.php` is automatic screening: rate limits, the verdict cache and `src/RejectionLog.php` around the screener. The screener itself stays free of side effects so other callers (e.g. a moderation queue) can use it on their own terms.
- `src/Hooks.php` wires automatic screening into core's upload hooks, and `src/IntegratedProfilesHooks.php` into IntegratedProfiles avatar and banner uploads. The latter is a separate handler so the IntegratedProfiles interface is only loaded when that extension runs the hook.

Adding a check means defining its signal in `docs/sidecar-api.md`, implementing it in the sidecar, then adding an evaluator here.

# Tests

`composer test` is PHP lint (syntax + PHPCS).

PHPUnit lives under `tests/phpunit/Unit` (and `tests/phpunit/Integration` when needed), using MediaWiki's `MediaWikiUnitTestCase` / `MediaWikiIntegrationTestCase`. It runs in CI automatically on push and pull requests. Locally, you can use mwenv (see below) or any MediaWiki checkout with UploadSafety at `extensions/UploadSafety`, before running `composer phpunit`.

## PHP tests with mwenv

[mwenv](https://github.com/ProfessionalWiki/mwenv) gives each git worktree its own MediaWiki, database, and port. It needs git plus Docker with Compose.

```sh
scripts/mwenv-setup
mwenv run -- composer phpunit
mwenv run -- composer phpunit -- --filter test_some_name
```

`scripts/mwenv-setup` defaults to MediaWiki 1.46, PHP 8.3, and MariaDB, and also installs IntegratedProfiles (pinned to a `main` commit, same as CI) so its hook tests run instead of being skipped. CI uses SQLite and tests MediaWiki 1.46 with PHP 8.3 and 8.5. To match it, run `scripts/mwenv-setup --db sqlite` (or `PHP=8.5 scripts/mwenv-setup --db sqlite`) in a fresh worktree or after `mwenv destroy`.

No classifier sidecar is started, so uploads in the test wiki fail closed unless `$wgUploadSafetyFailClosed` is `false`.

For PHP lint inside the container (`composer test` on the host works too):

```sh
mwenv run -- sh -c 'composer install && composer test'
```
