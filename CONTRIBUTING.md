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

- `src/UploadScreener.php` runs every configured check against an upload and decides the outcome (fail open/closed, always-reject, debug detail).
- `src/Check/` holds the checks. Each implements `ImageCheck` and returns a `CheckResult` with a `CheckVerdict` (`Pass`, `Reject` or `Unavailable`). Register new checks in `src/ServiceWiring.php`.
- `src/Hooks.php` wires the screener into core's upload hooks.

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

`scripts/mwenv-setup` defaults to MediaWiki 1.46, PHP 8.3, and MariaDB. CI uses SQLite and tests MediaWiki 1.46 with PHP 8.3 and 8.5. To match it, run `scripts/mwenv-setup --db sqlite` (or `PHP=8.5 scripts/mwenv-setup --db sqlite`) in a fresh worktree or after `mwenv destroy`.

No classifier sidecar is started, so uploads in the test wiki fail closed unless `$wgUploadSafetyFailClosed` is `false`.

For PHP lint inside the container (`composer test` on the host works too):

```sh
mwenv run -- sh -c 'composer install && composer test'
```
