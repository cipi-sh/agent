# Changelog

All notable changes to `cipi/agent` are documented here.

---

## [1.5.3] — 2026-03-16

### Changed

- **Package name** — renamed from `andreapollastri/cipi-agent` to `cipi/agent`. Update your `composer.json` and run `composer require cipi/agent` for new installations.

---

## [1.5.2] — 2026-03-16

### Added

- **Laravel 13 support** — updated `illuminate/support`, `illuminate/routing`, and `illuminate/console` constraints from `^12.0` to `^12.0|^13.0`. All existing features (webhook deploy, health check, MCP server, database anonymizer) are fully compatible with Laravel 13.

---

## [1.1.3] — 2026-03-07

### Fixed

- **`DatabaseController::findUserByEmail()` missing `DB` import** — fixed `Error: Class "Cipi\Agent\Http\Controllers\DB" not found` (HTTP 500) on `POST /cipi/db/user` caused by a missing `use Illuminate\Support\Facades\DB;` import.

## [1.1.2] — 2026-03-07

### Fixed

- **`DatabaseController::downloadAnonymized()` return type** — fixed `TypeError` (HTTP 500) on `GET /cipi/db/{token}` caused by a return type mismatch: the method declared `Illuminate\Http\Response` but `response()->file()` returns `Symfony\Component\HttpFoundation\BinaryFileResponse`. Changed the import to `Symfony\Component\HttpFoundation\Response` (the common base class) so the declaration is compatible with the actual return value.

---

## [1.1.1] — 2026-03-07

### Added

- **`php artisan cipi:generate-token {type}`** — unified command replacing the separate `cipi:health-token` and `cipi:anonymizer-token` commands. Accepts `mcp`, `health`, or `anonymize` as argument.
- **`php artisan cipi:service {type} --enable|--disable`** — new command to toggle Cipi services directly from the CLI. Reads and updates the `.env` file in place. Supports `mcp`, `health`, and `anonymize`.
- **`php artisan cipi:init-anonymize`** — scaffolds `/home/{app_user}/.db/anonymization.json` from the built-in example. Supports `--force` to overwrite. The file is created outside the project repo with permissions `0640`.

### Changed (Breaking)

- **`cipi:health-token` removed** — replaced by `php artisan cipi:generate-token health`.
- **`cipi:anonymizer-token` removed** — replaced by `php artisan cipi:generate-token anonymize`.
- **`cipi:mcp --token` removed** — replaced by `php artisan cipi:generate-token mcp`.
- **`CIPI_MCP_ENABLED` renamed to `CIPI_MCP`** — shorter, consistent naming. Update your `.env` accordingly.
- **`CIPI_ANONYMIZER_ENABLED` renamed to `CIPI_ANONYMIZER`** — shorter, consistent naming. Update your `.env` accordingly.
- **`anonymization.json` must now live on the server** — the file is no longer published to the project root and `storage/cipi/anonymization.json` is no longer a valid search path. Place the file at `/home/{app_user}/.db/anonymization.json` (recommended) or `/home/{app_user}/.cipi/anonymization.json` on the server to keep sensitive field mappings out of version control.

---

## [1.1.0] — 2026-03-07

### Added

- **Database Anonymizer** — complete system for creating anonymized database dumps for local development/testing. Includes JSON configuration for transformations, async job processing, and secure download links.
- **POST `/cipi/db` API endpoint** — authenticated endpoint that accepts an email address and queues a database anonymization job. Requires `CIPI_ANONYMIZER_TOKEN` to be configured.
- **POST `/cipi/db/user` API endpoint** — utility endpoint that accepts an email address and returns the corresponding user ID from the database, useful for debugging anonymized datasets.
- **Enhanced user lookup** — the `/cipi/db/user` endpoint now uses the same `CIPI_ANONYMIZER_TOKEN` as other database operations for simplified access control.
- **Enhanced health check** — `/cipi/health` endpoint now includes deploy information with the last deployed Git commit hash, checked from multiple sources (Cipi deploy files, deploy logs, Git repository).
- **GET `/cipi/db/{token}` endpoint** — signed URL endpoint (valid for 15 minutes) that serves anonymized SQL dumps for download.
- **`php artisan cipi:anonymize`** — new Artisan command that creates anonymized database dumps. Supports MySQL and PostgreSQL, applies JSON-defined transformations using Faker library, and handles password re-hashing with project-specific algorithms (bcrypt/argon/auto).
- **`php artisan cipi:anonymizer-token`** — generates a secure token for database anonymization API access (`CIPI_ANONYMIZER_TOKEN`).
- **`CIPI_ANONYMIZER_TOKEN`** — dedicated environment variable for database anonymization features. When set, enables the `/cipi/db` endpoints.
- **`CIPI_MCP_TOKEN`** — dedicated environment variable for MCP server access, separate from webhook token for better security isolation.
- **`php artisan cipi:mcp --token`** — option to generate a new MCP token without showing setup instructions.
- **`CIPI_HEALTH_TOKEN`** — dedicated environment variable for health check endpoint access. Falls back to `CIPI_WEBHOOK_TOKEN` for full backward compatibility.
- **`VerifyHealthToken` middleware** — dedicated middleware for `/cipi/health` that resolves `CIPI_HEALTH_TOKEN` (with `CIPI_WEBHOOK_TOKEN` fallback), consistent with the MCP and Anonymizer token pattern.
- **`php artisan cipi:health-token`** — generates a secure token for health check access and prints the `.env` snippet.
- **Async anonymization job** — `AnonymizeDatabaseJob` that processes database dumps in the background, sends email notifications with download links, and handles cleanup.
- **JSON transformation config** — `anonymization.json` configuration file with support for table-specific transformations (fakeName, fakeEmail, fakeAddress, password hashing, etc.) using Faker library.
- **Email notifications** — automated emails sent upon anonymization completion with signed download links valid for 15 minutes.

### Changed

- **Token separation** — MCP server now uses dedicated `CIPI_MCP_TOKEN` instead of shared webhook token. Falls back to `CIPI_WEBHOOK_TOKEN` for backward compatibility.
- **Dedicated middleware** — Created separate middleware classes (`VerifyMcpToken`, `VerifyAnonymizerToken`) for better separation of concerns. Each endpoint now uses the appropriate middleware for its specific token type.
- **Enhanced `cipi:mcp` command** — now shows `CIPI_MCP_TOKEN` in setup instructions and supports `--token` flag for token generation.
- **`/cipi/health` middleware** — now uses `VerifyHealthToken` instead of `VerifyWebhookToken`, allowing monitoring tools (UptimeRobot, Grafana, etc.) to use a dedicated token independent of the deploy webhook secret.

### Changed (Breaking)

- **`CIPI_MCP` default is now `false`** — MCP endpoint must be explicitly enabled by setting `CIPI_MCP=true` in `.env`. Previously defaulted to `true`.

### Security

- **Token isolation** — separate tokens for different functionalities prevent cross-feature access.
- **Feature gating** — MCP and anonymizer middleware return 404 when their respective features are disabled, hiding endpoint existence.
- **Signed download URLs** — database dumps are served via time-limited signed URLs (15 minutes expiration).
- **Automatic cleanup** — expired tokens and files are automatically cleaned up.
- **Safe transformations** — password fields are properly re-hashed using Laravel's Hash facade.

---

## [1.0.3] — 2026-03-05

### Added

- **`db_query` MCP tool** — execute SQL queries against the application database for data investigation and debugging, equivalent to `cipi app tinker`. Supports SELECT, SHOW, DESCRIBE, EXPLAIN (read) and INSERT, UPDATE, DELETE (write). Results are formatted as a readable ASCII table, capped at 100 rows. Destructive DDL operations (DROP TABLE/DATABASE, TRUNCATE, GRANT/REVOKE, file I/O) are blocked for safety.

### Changed

- **Enhanced `logs` MCP tool** — the `logs` tool now supports all Cipi log types (`laravel`, `nginx`, `php`, `worker`, `deploy`) via the new `type` parameter, matching the CLI's `cipi app logs <app> --type=<type>`. Added `level` parameter for minimum severity filtering on Laravel logs (e.g. `error` returns error, critical, alert, and emergency entries) and `search` parameter for case-insensitive keyword filtering across all log types. Laravel daily log rotation (`laravel-YYYY-MM-DD.log`) is auto-detected. Multi-line Laravel log entries (stack traces) are kept intact during filtering. Fully backward-compatible — calling `logs` without new parameters behaves exactly as before.

---

## [1.0.2] — 2026-03-05

### Added

- **MCP server** — new `/cipi/mcp` HTTP endpoint implementing the Model Context Protocol (MCP 2024-11-05, JSON-RPC 2.0). AI assistants such as Cursor and Claude Desktop can connect to it directly using the existing `CIPI_WEBHOOK_TOKEN` as a Bearer token.
- **MCP tools** — five tools exposed via the MCP endpoint: `health` (app/db/cache/queue status), `app_info` (full application configuration), `deploy` (trigger a zero-downtime deployment), `logs` (read recent lines from `storage/logs/laravel.log`), and `artisan` (run Artisan commands with a blocklist for long-running ones).
- **`php artisan cipi:mcp`** — new Artisan command that prints the MCP endpoint URL and ready-to-paste config snippets for both Cursor (native HTTP) and Claude Desktop (via `mcp-remote` bridge).
- **`CIPI_MCP`** — new `.env` variable to enable or disable the MCP endpoint (default: `true`).

---

## [1.0.1] — 2026-03-03

### Changed

- Updated documentation and package metadata.
- Fixed Composer package name.

---

## [1.0] — 2026-03-02

### Added

- Initial release.
- **Webhook endpoint** at `/cipi/webhook` — receives push events from GitHub, GitLab, Gitea, and Bitbucket, verifies the signature/token, and writes a `.deploy-trigger` flag file consumed by the Cipi cron.
- **Health check endpoint** at `/cipi/health` — returns JSON status for app, database, cache, and queue. Protected by Bearer token.
- **Branch filtering** via `CIPI_DEPLOY_BRANCH` — skips deploys for pushes to non-matching branches.
- **`php artisan cipi:status`** — shows agent configuration and live database connectivity.
- **`php artisan cipi:deploy-key`** — prints the SSH deploy key for the app.
- Auto-discovery via Laravel's package manifest — no `config/app.php` change needed.
- Config file publishable via `php artisan vendor:publish --tag=cipi-config`.

---
