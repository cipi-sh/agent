<h1 align="center">Cipi Agent for Laravel</h1>

<p align="center">
  The official Laravel companion package for the <a href="https://cipi.sh"><strong>Cipi</strong></a> server control panel.<br>
  Webhook deploys, health monitoring, an MCP server for AI assistants, <code>cipi.yml</code> tooling and a database anonymizer — all in one package.
</p>

---

## What is Cipi Agent?

**Cipi Agent** runs inside your Laravel application on a server managed by [Cipi](https://cipi.sh). Cipi manages the infrastructure (LEMP stack, SSL, PHP versions, domains, workers, backups); this package is the part that lives in the app and bridges the two:

- **Webhook-triggered deployments** from GitHub, GitLab, Bitbucket, Gitea and Azure DevOps, recorded in Cipi's deploy audit ledger
- **Health monitoring** of app, database, cache, queue (pending and failed jobs), storage and deploy state
- **An MCP server** that lets AI assistants (Cursor, VS Code, Claude Code, Claude Desktop, any MCP client) inspect and operate the production app
- **`cipi.yml` tooling** — validate, inspect and generate the declarative server configuration with the same rules as `cipi yml validate` on the server
- **A database anonymizer** for MySQL/MariaDB **and PostgreSQL** that produces safe, privacy-compliant dumps for local development
- **Optional panel integration** through [`cipi/sdk`](https://cipi.sh/docs/php-sdk): rollback, deploy audit ledger, database backups and job status from the same MCP server

Everything is configured through environment variables. When you create an application through Cipi, the core variables are injected automatically.

> **Note:** some features (health check, MCP, `cipi.yml` validation, anonymizer) work on any host. Automated deployments, infrastructure logs and release information require a Cipi-managed environment.

---

## Table of Contents

- [Requirements](#requirements)
- [Installation](#installation)
- [Configuration](#configuration)
- [Webhook Deploy](#webhook-deploy)
- [Health Check](#health-check)
- [MCP Server](#mcp-server)
- [cipi.yml Tooling](#cipiyml-tooling)
- [Database Anonymizer](#database-anonymizer)
- [Panel Integration (cipi/sdk)](#panel-integration-cipisdk)
- [Artisan Commands](#artisan-commands)
- [Security](#security)
- [Upgrading from 1.x](#upgrading-from-1x)
- [Development](#development)
- [License](#license)

---

## Requirements

| Requirement | Version                                                   |
| ----------- | --------------------------------------------------------- |
| PHP         | 8.3 or higher                                             |
| Laravel     | 12 or 13                                                  |
| Database    | MySQL / MariaDB or PostgreSQL (for the anonymizer)        |
| CLI tools   | `mysqldump` (or `mariadb-dump`) / `pg_dump` on the server |
| Optional    | `cipi/sdk` ^1.0 for the panel tools, `ext-zlib` for gzip  |

---

## Installation

```bash
composer require cipi/agent
```

The service provider is auto-discovered. On a Cipi-managed server the required environment variables are already in place. To customize defaults, publish the configuration:

```bash
php artisan vendor:publish --tag=cipi-agent-config
```

Check that everything is wired up:

```bash
php artisan cipi:status
```

---

## Configuration

| Variable                       | Default                  | Description                                                              |
| ------------------------------ | ------------------------ | ------------------------------------------------------------------------ |
| `CIPI_WEBHOOK_TOKEN`           | `""`                     | Secret for webhook authentication (set by Cipi)                          |
| `CIPI_APP_USER`                | `""`                     | Linux user of the app (set by Cipi) — locates `/home/<user>`             |
| `CIPI_HOME`                    | `/home/<app_user>`       | Override the app home directory                                          |
| `CIPI_PHP_VERSION`             | system PHP               | PHP version assigned by Cipi (set by Cipi)                               |
| `CIPI_DEPLOY_BRANCH`           | `null`                   | Only deploy pushes to this branch (`null` = any)                         |
| `CIPI_ENV`                     | `APP_ENV`, else `production` | Environment label of the app (set by Cipi 5.8); `cipi-<env>.yml` is read before `cipi.yml` |
| `CIPI_ROUTE_PREFIX`            | `cipi`                   | URL prefix for all agent routes                                          |
| `CIPI_LOG_CHANNEL`             | `null`                   | Log channel for deploy events                                            |
| `CIPI_HEALTH_CHECK`            | `true`                   | Enable the health endpoint                                               |
| `CIPI_HEALTH_TOKEN`            | `""`                     | Bearer token for the health endpoint (falls back to `CIPI_WEBHOOK_TOKEN`) |
| `CIPI_MCP`                     | `false`                  | Enable the MCP server                                                    |
| `CIPI_MCP_TOKEN`               | `""`                     | Bearer token for MCP                                                     |
| `CIPI_MCP_ALLOWED_ORIGINS`     | app host                 | Browser origins allowed on the MCP endpoint (`*` = any)                  |
| `CIPI_MCP_DB_WRITE`            | `true`                   | Allow INSERT/UPDATE/DELETE through the `db_query` tool                   |
| `CIPI_MCP_MAX_ROWS`            | `100`                    | Default row cap for `db_query` (max 500)                                 |
| `CIPI_MCP_REDACT`              | `true`                   | Redact common secrets in logs and command output sent over MCP           |
| `CIPI_ANONYMIZER`              | `false`                  | Enable the anonymizer endpoints                                          |
| `CIPI_ANONYMIZER_TOKEN`        | `""`                     | Bearer token for the anonymizer API                                      |
| `CIPI_ANONYMIZER_CONFIG`       | `~/.db/anonymization.json` | Explicit path of the anonymization config                              |
| `CIPI_ANONYMIZER_TTL`          | `15`                     | Minutes a download link stays valid                                      |
| `CIPI_MYSQLDUMP` / `CIPI_PG_DUMP` | from `PATH`           | Dump binaries, when not on `PATH`                                        |
| `CIPI_PANEL`                   | `true`                   | Expose the panel tools when `cipi/sdk` is installed and configured       |
| `CIPI_PANEL_APP`               | `CIPI_APP_USER`          | App name on the panel                                                    |
| `CIPI_BASE_URL` / `CIPI_TOKEN` | —                        | Panel API URL and Sanctum token (read by `cipi/sdk`)                     |

Toggle features and generate tokens from the CLI; the configuration cache is rebuilt for you when one exists:

```bash
php artisan cipi:service mcp --enable
php artisan cipi:generate-token mcp
php artisan cipi:service anonymize --enable
php artisan cipi:generate-token anonymize
```

---

## Webhook Deploy

**Endpoint:** `POST /cipi/webhook`

The endpoint verifies the push, then drops `~/.deploy-trigger`. The app's crontab (installed by Cipi) picks it up within a minute and runs `cipi-app-deploy`, which wraps Deployer: clone, `composer install`, migrations, symlink swap, worker restart, `cipi.yml` reconciliation, post-deploy steps and the post-deploy healthcheck.

| Provider     | Verification                                 | Events                                  |
| ------------ | -------------------------------------------- | --------------------------------------- |
| GitHub       | `X-Hub-Signature-256` (HMAC-SHA256)          | `push`; `ping` answers `pong`           |
| GitLab       | `X-Gitlab-Token`                             | `Push Hook`                             |
| Bitbucket    | `X-Hub-Signature` (`sha256=`)                | `repo:push`                             |
| Gitea        | `X-Gitea-Signature`                          | `push`                                  |
| Azure DevOps | `X-Gitlab-Token` (as registered by Cipi)     | `git.push`                              |

Other events are acknowledged with `202 ignored`. With `CIPI_DEPLOY_BRANCH` set, pushes to other branches get `200 skipped`. A queued deploy answers with the branch and the app's environment label (`env`, see below), which the log line carries too.

**Audit ledger.** The trigger file carries what the payload said about the push — provider, actor, commit, delivery id and the client IP. Cipi records these under `claimed` in the root-owned, hash-chained deploy audit ledger (`cipi deploy <app> --audit`), next to the facts root establishes itself. The same format is used by the MCP `deploy` tool (`source: mcp`), so every deploy says who asked for it.

> On Cipi-managed servers the webhook is configured automatically when you connect the repository through the panel. Use `CIPI_WEBHOOK_TOKEN` as the webhook secret elsewhere.

---

## Health Check

**Endpoint:** `GET /cipi/health` — Bearer token (`CIPI_HEALTH_TOKEN`, then `CIPI_WEBHOOK_TOKEN`).

```bash
php artisan cipi:generate-token health
curl -H "Authorization: Bearer $TOKEN" https://yourdomain.com/cipi/health
```

```json
{
  "status": "healthy",
  "app_user": "myapp",
  "php": "8.4",
  "laravel": "13.35.0",
  "environment": "production",
  "checks": {
    "app": { "ok": true, "version": "2.1.0", "debug": false, "maintenance": false },
    "database": { "ok": true, "driver": "mysql", "database": "myapp" },
    "cache": { "ok": true, "driver": "database" },
    "queue": { "ok": true, "connection": "database", "pending_jobs": 0, "failed_jobs": 0 },
    "storage": { "ok": true, "disk_free_gb": 31.2, "disk_used_percent": 41 },
    "deploy": {
      "ok": true,
      "release": "20261009103000",
      "commit": "0123456789abcdef0123456789abcdef01234567",
      "short_commit": "0123456",
      "commit_source": "REVISION",
      "in_progress": false,
      "pending": false,
      "last_deploy": { "status": "ok", "trigger": "webhook", "branch": "main", "duration_seconds": 42 }
    }
  },
  "timestamp": "2026-10-09T10:00:00+00:00"
}
```

`status` is `degraded` (HTTP 503) when the database, cache, queue or storage check fails. The deployed commit is read from Deployer's `REVISION` file of the live release, with fallbacks for apps not deployed by Cipi. A failed last deploy is reported under `deploy.attention` without degrading the status: the previous release is still serving.

---

## MCP Server

**Endpoint:** `POST /cipi/mcp` — Streamable HTTP, JSON-RPC 2.0, Bearer token (`CIPI_MCP_TOKEN`).

The server speaks every current revision of the [Model Context Protocol](https://modelcontextprotocol.io): the handshake-based revisions **2024-11-05 → 2025-11-25** (`initialize`, used by today's Cursor, VS Code, Claude Desktop…) and the stateless revision **2026-07-28** (`server/discover`, per-request `_meta`, `resultType`, `Mcp-Method`/`Mcp-Name` header validation). Tools carry titles, behaviour annotations (`readOnlyHint`, `destructiveHint`, …) and return both text and `structuredContent`; tool failures use `isError`. `GET`/`DELETE` answer `405` (no SSE stream, no sessions), the `Origin` header is validated against the app host, and notifications get `202`.

### Setup

```bash
php artisan cipi:service mcp --enable
php artisan cipi:generate-token mcp
php artisan cipi:mcp          # endpoint, tools and ready-to-paste client configuration
```

`cipi:mcp` prints snippets for **Cursor** (`~/.cursor/mcp.json`), **VS Code** (`.vscode/mcp.json`, token prompted once), **Claude Code** (`claude mcp add --transport http …`) and **Claude Desktop** (via the `mcp-remote` bridge).

### Tools

| Tool             | Mode            | What it does                                                                                                           |
| ---------------- | --------------- | ---------------------------------------------------------------------------------------------------------------------- |
| `health`         | read-only       | Same report as `/cipi/health`                                                                                          |
| `app_info`       | read-only       | Versions, drivers, environment label and the `cipi-<env>.yml` lookup, Cipi endpoints, enabled features, live release and commit (no secrets) |
| `deploy`         | action          | Queue a zero-downtime deploy; records `actor`, `ref`, `request_id` as audit claims; refuses while a deploy is running   |
| `deploy_status`  | read-only       | Live release and commit, deploy branch and environment label, releases kept for rollback, queued/running state, last deploy outcome, deploy log tail |
| `logs`           | read-only       | `laravel`, `nginx`, `php`, `worker`, `horizon`, `reverb`, `deploy` or `all`; severity and keyword filters; redacted    |
| `artisan`        | can change data | Run an Artisan command; interactive, long-running and data-destroying commands are blocked                             |
| `db_query`       | configurable    | SELECT/WITH/SHOW/DESCRIBE/EXPLAIN as table or JSON (auto-capped); INSERT/UPDATE/DELETE unless `CIPI_MCP_DB_WRITE=false` |
| `db_schema`      | read-only       | Tables with row counts, or one table's columns, indexes, foreign keys and the columns that look like personal data      |
| `env_check`      | read-only       | Which `.env` keys are set or empty, vs `.env.example` and `cipi.yml` `env.required` — names only, never values          |
| `cipi_yml`       | read-only       | `validate`, `show`, `generate` or `example` for `cipi.yml`; validates drafts passed as `content`                        |
| `anonymizer`     | action          | `audit`, `config`, `scan`, `transformations`, or `start` an anonymized dump                                             |
| `panel_*`        | see below       | Rollback, deploy audit ledger, DB backup, job status, app/server status through the panel API                           |

**Resources:** `cipi://cipi.yml`, `cipi://cipi.yml/schema`, `cipi://cipi.yml/example`, `cipi://anonymization.json`, `cipi://anonymization.json/schema`.

### Example prompts

- *"Is production healthy, and what commit is live?"*
- *"Show the last 100 error entries and tell me which job is failing."*
- *"Which columns of the users table look like personal data?"*
- *"Validate this cipi.yml draft before I commit it."*
- *"Queue a deploy and record that Jane asked for it."*

### Safety measures

- **Blocked Artisan commands:** `serve`, `tinker`, `pail`, `queue:work`, `queue:listen`, `schedule:work`, `horizon`, `horizon:work`, `horizon:supervisor`, `octane:start`, `reverb:start`, `db`, `db:wipe`, `migrate:fresh`, `migrate:reset` — extend the list with `cipi.mcp_blocked_artisan`
- **Blocked SQL:** DDL (`DROP`, `ALTER`, `TRUNCATE`, `CREATE …`), `GRANT`/`REVOKE`, file I/O, administrative statements, multi-statement queries; reads without `LIMIT` are capped
- **Redaction:** tokens, passwords, DSNs, `APP_KEY`, provider keys are masked in logs and command output; a production-content warning is prepended (`CIPI_MCP_REDACT`)
- **Origin validation** against the app host (`CIPI_MCP_ALLOWED_ORIGINS` to extend)

---

## cipi.yml Tooling

[`cipi.yml`](https://cipi.sh/cipi-yml) is the declarative file committed with the app that drives its server configuration: PHP version, aliases, php.ini overrides, limits, basic auth, extra databases, workers (queues, Horizon, Reverb), scheduler, healthcheck, deploy recipe and post-deploy steps, redirects, proxies, SSL, required `.env` names, crons and backup profiles. On the server `cipi yml validate|plan|apply` reconcile it.

This package ships the **same parser and validator** (a line-for-line port of the server's), so you get the server's verdict — and the server's exact error messages — locally and in CI:

```bash
php artisan cipi:yml validate              # ./cipi.yml, then the server locations; exit 1 on errors
php artisan cipi:yml validate --json       # machine-readable, for CI
php artisan cipi:yml validate --strict     # warnings (e.g. env.required missing in .env) also fail
php artisan cipi:yml show                  # the normalized document with the server defaults applied
php artisan cipi:yml generate --write      # a cipi.yml inferred from this Laravel app
php artisan cipi:yml example --write       # the commented template, in the app's namespace
php artisan cipi:yml schema --write        # JSON Schema for editor autocompletion
```

`generate` looks at the application itself: Horizon or plain queue workers (`QUEUE_CONNECTION`), Reverb, Scout with Meilisearch, scheduled tasks, the `/up` health route, `CIPI_PHP_VERSION`, extra databases in the app namespace, the credential-looking keys of `.env.example` for `env.required` and the deploy branch (`CIPI_DEPLOY_BRANCH`) as a commented `deploy.branch`. The output is run through the validator before it is returned. `cipi yml generate <app>` on the server remains the source of truth for what is configured *today*.

**One file per environment.** Cipi 5.8 gives every app an environment label (`cipi app create --env staging`, `cipi app edit --env`; `production` by default) and writes it to the app's `.env` as `CIPI_ENV`. For an app labelled `staging` the server reads `cipi-staging.yml` (or `.yaml`) first and falls back to `cipi.yml`, so a repository deployed to a staging app and a production app can carry `cipi-staging.yml` next to `cipi.yml` (or `cipi-production.yml`). The agent looks the same way — `cipi:yml validate|show`, `cipi:status`, the `cipi_yml` and `env_check` tools and the `cipi://cipi.yml` resource all pick the environment's file when there is one — and reports the label (`CIPI_ENV`, then `APP_ENV`, then `production`) in `cipi:status`, `app_info`, `deploy_status`, the health report and the webhook answer. The agent never changes it. `deploy.branch` names the git branch the app deploys (validated like `cipi app edit --branch`), so each environment's file can name its own.

For editor validation add this as the first line of `cipi.yml` after `cipi:yml schema --write`:

```yaml
# yaml-language-server: $schema=./cipi.schema.json
```

Namespace rules need the app name: `CIPI_APP_USER`, or `--app=<name>` outside the server.

---

## Database Anonymizer

Creates an anonymized dump — **MySQL/MariaDB** (`mysqldump`, extended inserts) and **PostgreSQL** (`pg_dump`, `COPY` blocks and `INSERT` statements) — by streaming the dump and replacing sensitive columns with realistic fake values from [Faker](https://fakerphp.github.io/). The real data never lingers: the original dump is deleted as soon as it has been processed, output files are `0600`, and database passwords travel in the environment, never on the command line.

### Setup

```bash
php artisan cipi:service anonymize --enable
php artisan cipi:generate-token anonymize
php artisan cipi:init-anonymize --scan      # propose a config from the live schema
php artisan cipi:anonymize-audit            # check it against the database
```

`--scan` reads the schema and proposes a transformation for every column that looks like personal data (e-mails, names, phones, addresses, IPs, tokens, passwords, tax ids, card data, free-text notes…) and empties the usual operational tables (`sessions`, `jobs`, `failed_jobs`, `password_reset_tokens`, `personal_access_tokens`, Telescope, Pulse…). Review the file before the first dump. The config lives at `/home/<app>/.db/anonymization.json`, outside the repository, with permissions `0640`.

`cipi:anonymize-audit` (also `cipi:anonymize --check` and the MCP `anonymizer` tool) reports tables and columns that no longer exist, unknown transformations, password columns not using `password`, and the personal-data columns left uncovered, with a suggestion for each.

### Configuration

```json
{
  "transformations": {
    "users": {
      "name": "fakeName",
      "email": { "type": "fakeEmail", "unique": true, "consistent": true },
      "password": "password",
      "remember_token": "null",
      "phone": "mask:3,2"
    },
    "orders": {
      "customer_email": { "type": "fakeEmail", "consistent": true },
      "card_last_four": "numerify:####",
      "internal_notes": "fakeParagraph"
    }
  },
  "truncate": ["sessions", "jobs", "failed_jobs", "password_reset_tokens", "personal_access_tokens"],
  "options": {
    "hash_algorithm": "auto",
    "password": "password",
    "faker_locale": "en_US",
    "consistent": true,
    "seed": null,
    "gzip": false
  }
}
```

A transformation is a name (`"fakeEmail"`), a name with an argument (`"fixed:ACME"`, `"numerify:###"`, `"mask:1,2"`, `"hash:32"`), or an object with `type` plus `unique` / `consistent` flags. `NULL` values stay `NULL` (except with `fixed`). A JSON Schema is published with `vendor:publish --tag=cipi-schemas`.

- **`consistent`** — the same original value becomes the same fake value in every table and every run with the same `seed`, so denormalized e-mails and names still join.
- **`unique`** — no two rows of a column receive the same value; on by default for `fakeEmail`, `fakeSafeEmail`, `fakeUserName`, `fakeUuid`, `fakeToken`, `fakeIban` (columns that usually carry a unique index).
- **`password`** — every row gets the hash of `options.password` (default `password`), computed once, so developers can log in to the anonymized copy. `hash_algorithm` `auto` uses the app's Hash driver.
- **`truncate`** — structure is kept, rows are dropped from the dump.

### Transformations

| Transformation | Output | Transformation | Output |
| --- | --- | --- | --- |
| `fakeName` / `fakeFirstName` / `fakeLastName` | Person names | `fakeIpv4` / `fakeIpv6` / `fakeMacAddress` | Network identifiers |
| `fakeUserName` | User name (unique) | `fakeUserAgent` | Browser user agent |
| `fakeEmail` / `fakeSafeEmail` | E-mail (unique) | `fakeUuid` | UUID v4 (unique) |
| `fakeCompany` / `fakeJobTitle` | Company, job title | `fakeLatitude` / `fakeLongitude` | Coordinates |
| `fakeAddress` / `fakeStreetAddress` | Postal / street address | `fakeNumber[:digits]` | Random integer |
| `fakeCity` / `fakeState` / `fakePostcode` | City, region, postcode | `fakeBoolean` | Random true/false |
| `fakeCountry` / `fakeCountryCode` | Country name / ISO code | `fakeToken[:length]` | Random hex token |
| `fakePhoneNumber` | Phone number | `fakeIdentifier` | Alphanumeric id (tax id, passport…) |
| `fakeDate` / `fakeDateTime` | `Y-m-d` / `Y-m-d H:i:s` | `numerify:###` / `lexify:???` / `bothify:?#` | Patterns |
| `fakeUrl` / `fakeDomain` | URL, domain | `password` | Hash of `options.password` |
| `fakeParagraph` / `fakeSentence` / `fakeText[:len]` / `fakeWord` | Text | `null` / `empty` / `fixed:value` / `redact` | Constants |
| `fakeIban[:CC]` / `fakeCreditCardNumber` | Banking (test ranges) | `hash[:len]` / `mask:first,last` / `keep` | Derived from the original |

`php artisan cipi:anonymize --transformations` prints the list.

### API

```bash
# queue a dump — the signed download link is e-mailed (a queue worker and MAIL_* are required)
curl -X POST https://yourdomain.com/cipi/db \
  -H "Authorization: Bearer $CIPI_ANONYMIZER_TOKEN" -H "Content-Type: application/json" \
  -d '{"email": "developer@example.com", "gzip": true}'

# find the original user id for an e-mail (debugging an anonymized dataset)
curl -X POST https://yourdomain.com/cipi/db/user \
  -H "Authorization: Bearer $CIPI_ANONYMIZER_TOKEN" -H "Content-Type: application/json" \
  -d '{"email": "user@example.com"}'
```

The e-mail contains a signed link (`GET /cipi/db/{token}`, valid `CIPI_ANONYMIZER_TTL` minutes) and a summary: size, rows transformed per table, tables emptied, warnings.

### CLI

```bash
php artisan cipi:anonymize                                   # server config → storage/cipi/anonymized_<timestamp>.sql
php artisan cipi:anonymize --gzip                            # .sql.gz
php artisan cipi:anonymize /path/config.json /path/out.sql   # explicit paths (1.x signature)
php artisan cipi:anonymize --check                           # validate + audit, no dump
```

---

## Panel Integration (cipi/sdk)

The agent only knows the app it runs in. With the [PHP SDK](https://cipi.sh/docs/php-sdk) installed and a Sanctum token created on the server with the abilities you want to allow, the MCP server gains tools that no in-app process could offer:

```bash
composer require cipi/sdk
# on the server:  cipi api token create   → abilities: apps-view, deploy-manage, dbs-manage, status-view
```

```env
CIPI_BASE_URL=https://api.myserver.com
CIPI_TOKEN=...
```

| Tool                 | Panel operation                                           | Ability         |
| -------------------- | --------------------------------------------------------- | --------------- |
| `panel_status`       | App as the panel sees it + server snapshot                | `apps-view`, `status-view` |
| `panel_deploy_audit` | Hash-chained deploy audit ledger (`--audit`)               | `deploy-manage` |
| `panel_rollback`     | `cipi deploy --rollback` (code only, migrations stay)      | `deploy-manage` |
| `panel_db_backup`    | `cipi db backup` server-side snapshot                      | `dbs-manage`    |
| `panel_job`          | Status, result and output of an asynchronous panel job     | any             |

The tools appear automatically when the SDK is installed and `CIPI_BASE_URL`/`CIPI_TOKEN` are set (`CIPI_PANEL=false` hides them). Both packages read `config/cipi.php`; publish the agent's copy with `--tag=cipi-agent-config`.

---

## Artisan Commands

| Command | Description |
| ------- | ----------- |
| `cipi:status` | Agent configuration, environment label, release and commit, feature state, `cipi.yml` validity, database connectivity |
| `cipi:deploy-key` | Print the SSH deploy key of the app |
| `cipi:mcp [--json]` | MCP endpoint, tools, resources and client configuration snippets |
| `cipi:generate-token {mcp\|health\|anonymize}` | Generate a token and write it to `.env` |
| `cipi:service {mcp\|health\|anonymize} --enable\|--disable` | Toggle a feature in `.env` |
| `cipi:yml validate [--file=] [--app=] [--json] [--strict]` | Validate `cipi.yml` with the server's rules |
| `cipi:yml show [--json]` | Normalized document with defaults applied |
| `cipi:yml generate [--write] [--force]` | Infer a `cipi.yml` from the application |
| `cipi:yml example [--write]` | Commented template in the app's namespace |
| `cipi:yml schema [--write]` | JSON Schema for editors |
| `cipi:init-anonymize [--scan] [--path=] [--stdout] [--force]` | Create `anonymization.json` from the example or from the live schema |
| `cipi:anonymize-audit [config] [--json]` | Check the config against the database |
| `cipi:anonymize [config] [output] [--gzip] [--check] [--transformations]` | Create an anonymized dump |

---

## Security

- **Token isolation** — every feature has its own Bearer token (`CIPI_WEBHOOK_TOKEN`, `CIPI_HEALTH_TOKEN`, `CIPI_MCP_TOKEN`, `CIPI_ANONYMIZER_TOKEN`); compromising one grants nothing else
- **Feature gating** — disabled features return 404
- **Webhook verification** — provider HMAC signatures or secret headers, compared in constant time
- **Deploy claims** — what the agent writes about a deploy is recorded by Cipi as a *claim*, never as a fact
- **MCP** — blocked commands and statements, redaction, Origin validation, row caps, `isError` for failures, read-only database mode available
- **Anonymizer** — config outside the repository, passwords via environment, original dump deleted right after processing, `0600` outputs, signed time-limited download links, downloads confined to `storage/cipi`

---

## Upgrading from 1.x

2.0 is backward compatible on the wire — existing MCP clients, webhooks, health monitors and the anonymizer API keep working. Changes worth knowing:

- **`password` transformation** now sets every password to the hash of `options.password` (default `password`) instead of re-hashing the stored hash. The old behaviour produced unusable passwords; set `options.password` if you relied on a specific value.
- **Health** no longer reports `degraded` when deploy metadata is unavailable or the last deploy failed; the information is in `checks.deploy`.
- **Deploy trigger file** gained `source`, `actor`, `ip`, `ref`, `request_id` (read by Cipi's audit ledger); the 1.x keys are still written.
- **`db_query`** additionally blocks `ALTER`, `RENAME`, `CREATE …`, administrative statements and multi-statement queries; `CIPI_MCP_DB_WRITE=false` makes it read-only.
- **`artisan` tool** additionally blocks `db:wipe`, `migrate:fresh`, `migrate:reset`, `pail`, `horizon:*`.
- **Publishing** — `vendor:publish --tag=cipi-agent-config` publishes only this package's config (the `cipi-config` tag is shared with `cipi/sdk` and still works).
- **Anonymizer config** — `truncate` and the object form of transformations are new; existing files keep working. The PostgreSQL dump format changed from `custom` to `plain` so that it can actually be transformed.

---

## Development

```bash
composer install
composer test
```

The suite runs on Orchestra Testbench with an in-memory SQLite database and a fake app home; the `cipi.yml` validator is additionally checked against the server's Python validator on a corpus of valid and invalid files.

---

## License

MIT — see [LICENSE](LICENSE).

---

<p align="center">
  Built with care by <a href="https://web.ap.it">Andrea Pollastri</a> for the <a href="https://cipi.sh">Cipi</a> community.
</p>
