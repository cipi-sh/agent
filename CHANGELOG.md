# Changelog

All notable changes to `cipi/agent` are documented here.

---

## [2.0.0] — 2026-10-09

A catch-up release aligned with Cipi 5.5, the Cipi API 1.32 / PHP SDK 1.0 and the current Model Context Protocol revisions. Backward compatible on the wire; see **Upgrading from 1.x** in the README for the behaviour changes.

### Added

- **MCP: protocol revisions 2025-03-26, 2025-06-18, 2025-11-25 and 2026-07-28** next to 2024-11-05. The server is dual-era: `initialize` clients are served according to the version they negotiate; 2026-07-28 clients get `server/discover`, per-request `_meta` handling, `resultType`, `_meta` server info, `UnsupportedProtocolVersion` (-32022) / `HeaderMismatch` (-32020) errors with the right HTTP status, and `Mcp-Method` / `Mcp-Name` header validation. `GET`/`DELETE` answer 405, notifications 202, `Origin` is validated (`CIPI_MCP_ALLOWED_ORIGINS`).
- **MCP: tool metadata** — every tool declares `title`, behaviour `annotations` (`readOnlyHint`, `destructiveHint`, `idempotentHint`, `openWorldHint`), JSON Schema input (and output where useful), returns `structuredContent` next to text, and reports failures with `isError` instead of plain text.
- **MCP: new tools** — `deploy_status` (live release and commit, releases, running/queued state, last deploy outcome, deploy log tail), `db_schema` (tables with row counts; columns, indexes, foreign keys and personal-data hints for one table), `env_check` (which `.env` keys are set/empty vs `.env.example` and `cipi.yml` `env.required` — names only), `cipi_yml` (validate / show / generate / example), `anonymizer` (audit / config / scan / transformations / start).
- **MCP: resources** — `cipi://cipi.yml`, `cipi://cipi.yml/schema`, `cipi://cipi.yml/example`, `cipi://anonymization.json`, `cipi://anonymization.json/schema`.
- **MCP: panel tools via `cipi/sdk`** (optional) — `panel_status`, `panel_deploy_audit`, `panel_rollback`, `panel_db_backup`, `panel_job`, exposed when the SDK is installed and `CIPI_BASE_URL` / `CIPI_TOKEN` are set.
- **MCP: redaction** — logs and command output are prefixed with a production-content warning and common secrets (env assignments, bearer tokens, DSNs, `APP_KEY`, provider keys, Cipi tokens) are masked (`CIPI_MCP_REDACT`), mirroring the Cipi API's guard.
- **MCP: `logs`** reads `horizon`, `reverb` and `all` in addition to `laravel`, `nginx`, `php`, `worker`, `deploy`; output is bounded.
- **MCP: `db_query`** — `limit` and `format` (table/json) arguments, automatic cap on reads without `LIMIT`, `WITH` queries, read-only mode (`CIPI_MCP_DB_WRITE=false`), default cap `CIPI_MCP_MAX_ROWS`.
- **MCP: `deploy`** — accepts `actor`, `ref`, `request_id`, refuses while a deploy is running, reports an already queued trigger.
- **`cipi.yml` tooling** — `php artisan cipi:yml validate|show|generate|example|schema`: a line-for-line PHP port of the server's YAML-subset parser and schema validator (identical messages, checked against the server's Python on a corpus), an inferring generator (Horizon/queues, Reverb, Scout, scheduler, `/up` route, PHP version, namespaced databases, `env.required` from `.env.example`), the commented template, the `env.required` check against the local `.env`, `--json` for CI and a JSON Schema (`resources/schema/cipi.schema.json`, publishable with `--tag=cipi-schemas`).
- **Anonymizer: PostgreSQL support that works** — `pg_dump --format=plain` with streaming transformation of `COPY … FROM stdin` blocks and `INSERT` statements (standard-conforming and `E''` strings), in addition to MySQL/MariaDB.
- **Anonymizer: new transformations** — `fakeUserName`, `fakeSafeEmail`, `fakeJobTitle`, `fakeStreetAddress`, `fakeState`, `fakeCountry`, `fakeCountryCode`, `fakeDateTime`, `fakeDomain`, `fakeSentence`, `fakeText`, `fakeWord`, `fakeIban`, `fakeCreditCardNumber`, `fakeIpv4`, `fakeIpv6`, `fakeMacAddress`, `fakeUserAgent`, `fakeUuid`, `fakeLatitude`, `fakeLongitude`, `fakeNumber`, `fakeBoolean`, `fakeToken`, `fakeIdentifier`, `numerify`, `lexify`, `bothify`, `null`, `empty`, `fixed`, `hash`, `mask`, `redact`, `keep`; argument syntax (`fixed:ACME`, `mask:1,2`) and object form (`{"type": …, "unique": …, "consistent": …}`).
- **Anonymizer: `consistent` mode** (same original → same fake across tables and runs, seeded) and **`unique`** guarantee (default for e-mail, user name, UUID, token, IBAN); **`truncate`** list to empty operational tables; `gzip` output; `options.connection`, `options.dump_args`, `options.seed`.
- **Anonymizer: `cipi:init-anonymize --scan`** proposes a complete config from the live schema with personal-data heuristics; **`cipi:anonymize-audit`** (and `cipi:anonymize --check`) validates the config against the database and lists uncovered personal-data columns with suggestions; `cipi:anonymize --transformations` lists the catalogue; JSON Schema for `anonymization.json`.
- **Anonymizer: API** — `gzip` flag on `POST /cipi/db`, `CIPI_ANONYMIZER_TTL`, `CIPI_ANONYMIZER_CONFIG`, summary (size, rows, tables, warnings) in the notification e-mail.
- **Webhook** — Bitbucket (`X-Hub-Signature: sha256=`), Gitea and Azure DevOps payloads; GitHub `ping` → `pong`; non-push events acknowledged with `202 ignored`; the trigger file now carries `source`, `actor`, `ip`, `ref` (`branch@commit`) and `request_id` in the format `cipi-app-deploy` reads for the deploy audit ledger.
- **Health** — `checks.app.maintenance`, `checks.queue.failed_jobs`, `checks.storage` (writable paths, disk usage), `checks.deploy.release` / `commit` from Deployer's `REVISION`, `in_progress`, `pending`, `last_deploy` from `~/logs/deploy.log`.
- **`cipi:status`** shows release and commit, MCP/anonymizer/panel state and whether the `cipi.yml` is valid; **`cipi:mcp`** lists tools and resources from the registry and prints Cursor, VS Code, Claude Code and Claude Desktop snippets (`--json` for the raw config).
- **`CIPI_HOME`** to override the app home; **test suite** (Orchestra Testbench, 44 tests) and `composer test`.

### Changed

- **`password` transformation** sets every row to the hash of `options.password` (default `password`), computed once, instead of re-hashing the stored hash — the anonymized copy is now usable for login and large tables no longer pay one bcrypt per row.
- **Health** no longer degrades when deploy metadata is unavailable or the last automatic deploy failed (the previous release is still live); the information is reported under `checks.deploy`.
- **`db_query`** additionally blocks `ALTER`, `RENAME`, `CREATE …`, `SET GLOBAL`, `KILL`/`SHUTDOWN`, PostgreSQL file functions and multi-statement input; **`artisan`** additionally blocks `db:wipe`, `migrate:fresh`, `migrate:reset`, `pail`, `horizon:work`, `horizon:supervisor` (extend with `cipi.mcp_blocked_artisan`) and always runs with `--no-interaction`.
- **MySQL dumps** are taken with `--quick --skip-lock-tables` and the password is passed through `MYSQL_PWD` instead of `--password=` on the command line; PostgreSQL uses `PGPASSWORD`. Binaries are resolved on `PATH` (`mysqldump`, `mariadb-dump`, `pg_dump`) or via `CIPI_MYSQLDUMP` / `CIPI_PG_DUMP`.
- **Download route** is protected by Laravel's `signed` middleware and only serves files under `storage/cipi`.
- **Config publishing** — the config is published under both `cipi-config` (compatibility) and `cipi-agent-config` (because `cipi/sdk` uses the same `cipi-config` tag).
- `McpController` is now a thin transport layer; the protocol lives in `Cipi\Agent\Mcp\Server` with a `Registry` of `Tool` and `Resource` classes.

### Fixed

- **PostgreSQL anonymization never worked**: the dump was taken in `custom` (binary) format, which the line-based transformer could not read. Plain format with `COPY` parsing replaces it.
- **MySQL value parsing** broke on escaped quotes (`\'`), parentheses or commas inside strings, `_binary`/hex literals and `--complete-insert` column lists; `PRIMARY KEY` lines were mistaken for columns. A real tokenizer replaces the regex.
- **Webhook middleware** had its whole verification block duplicated; Bitbucket signatures (`sha256=` in `X-Hub-Signature`) were rejected.
- **`deploy` MCP tool** wrote a trigger payload (`trigger`, `branch`) the server ignored; it now writes the fields `cipi-app-deploy` reads, atomically (temp file + rename).
- **`cipi:mcp`** advertised the webhook token as a fallback that the MCP middleware would reject, and listed five tools while six existed.

---

## [1.5.4] — 2026-10-07

### Fixed

- **`cipi:service` and `cipi:generate-token` said to restart PHP-FPM** after writing `.env`. On a Cipi server the configuration is cached on every deploy (`artisan optimize`), so a restart changed nothing and the MCP endpoint (or health check, anonymizer) kept answering 404 with the old value. Both commands now rebuild the configuration cache when one exists (`config:cache`) and say the change is live; without a cache they say it applies to the next request. Octane, Horizon and queue workers are reminded that they keep the old value until they restart.

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
