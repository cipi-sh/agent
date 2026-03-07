# Cipi Agent for Laravel

[![Latest Version on Packagist](https://img.shields.io/packagist/v/andreapollastri/cipi-agent.svg)](https://packagist.org/packages/andreapollastri/cipi-agent)
[![PHP Version](https://img.shields.io/badge/php-%5E8.1-blue)](https://www.php.net)
[![Laravel](https://img.shields.io/badge/laravel-10%2B%20%7C%2011%2B%20%7C%2012%2B-red)](https://laravel.com)
[![License: MIT](https://img.shields.io/badge/license-MIT-green.svg)](LICENSE)

Laravel package that integrates your application with the [Cipi](https://cipi.sh) server control panel. It adds webhook-triggered deployments, a health check endpoint, a Model Context Protocol (MCP) server for AI assistants, and a database anonymizer for safe local development datasets — all configurable via environment variables with zero boilerplate.

---

## Features

| Feature | Endpoint / Command | Description |
|---|---|---|
| **Webhook deploy** | `POST /cipi/webhook` | Receives push events from GitHub, GitLab, Gitea, Bitbucket and triggers a Deployer deployment |
| **Health check** | `GET /cipi/health` | Returns JSON status for app, database, cache, queue, and last deploy commit |
| **MCP server** | `POST /cipi/mcp` | JSON-RPC 2.0 server for AI assistants (Cursor, Claude Desktop) |
| **DB anonymizer** | `POST /cipi/db` | Creates anonymized database dumps for local development / testing |

---

## Requirements

- PHP 8.1+
- Laravel 10, 11, or 12
- MySQL or PostgreSQL (for database anonymizer)
- `mysqldump` / `pg_dump` (for database anonymizer)

---

## Installation

```bash
composer require andreapollastri/cipi-agent
```

The service provider is auto-discovered — no changes to `config/app.php` are needed. Cipi automatically injects the required environment variables when it creates your app.

To publish the configuration file manually:

```bash
php artisan vendor:publish --tag=cipi-config
```

---

## Environment Variables

| Variable | Default | Description |
|---|---|---|
| `CIPI_WEBHOOK_TOKEN` | `""` | Secret token for webhook and health check authentication |
| `CIPI_APP_USER` | `""` | Linux username for the app (set by Cipi) |
| `CIPI_PHP_VERSION` | system PHP | PHP version shown in health check |
| `CIPI_DEPLOY_SCRIPT` | `~/.deployer/deploy.php` | Path to the Deployer config file |
| `CIPI_DEPLOY_BRANCH` | `null` | Only deploy pushes to this branch (`null` = any branch) |
| `CIPI_ROUTE_PREFIX` | `cipi` | URL prefix for all Cipi routes |
| `CIPI_HEALTH_CHECK` | `true` | Enable/disable the health check endpoint |
| `CIPI_LOG_CHANNEL` | `null` | Laravel log channel for deploy events |
| `CIPI_MCP_ENABLED` | `false` | Enable the MCP server endpoint |
| `CIPI_MCP_TOKEN` | `""` | Dedicated Bearer token for MCP access |
| `CIPI_ANONYMIZER_ENABLED` | `false` | Enable the database anonymizer endpoints |
| `CIPI_ANONYMIZER_TOKEN` | `""` | Bearer token for database anonymizer access |

---

## Webhook Deploy

The webhook endpoint listens for push events and writes a `.deploy-trigger` flag file consumed by the Cipi cron, which then runs Deployer.

**Supported providers:** GitHub, GitLab, Gitea, Bitbucket.

### Setup

In your Git provider, add a webhook pointing to:

```
POST https://yourdomain.com/cipi/webhook
```

Use `CIPI_WEBHOOK_TOKEN` as the secret. The middleware verifies:

- **GitHub** — `X-Hub-Signature-256` HMAC
- **GitLab** — `X-Gitlab-Token` header
- **Gitea** — `X-Gitea-Signature` HMAC
- **Bitbucket** — Bearer token in `Authorization` header

### Branch Filtering

Set `CIPI_DEPLOY_BRANCH=main` to ignore pushes to other branches.

---

## Health Check

`GET /cipi/health` — protected by `CIPI_WEBHOOK_TOKEN` Bearer token.

```bash
curl -H "Authorization: Bearer $CIPI_WEBHOOK_TOKEN" https://yourdomain.com/cipi/health
```

**Example response:**

```json
{
  "status": "healthy",
  "app_user": "myapp",
  "php": "8.3.0",
  "laravel": "11.0.0",
  "environment": "production",
  "checks": {
    "app":      { "ok": true, "version": "2.1.0", "debug": false },
    "database": { "ok": true, "database": "myapp_prod" },
    "cache":    { "ok": true },
    "queue":    { "ok": true, "connection": "redis", "pending_jobs": 0 },
    "deploy":   { "ok": true, "commit": "a1b2c3d4e5f6...", "short_commit": "a1b2c3d" }
  },
  "timestamp": "2026-03-07T10:00:00.000000Z"
}
```

**Deploy commit is resolved in order from:**
1. `/home/{app_user}/.cipi/deploy.json`
2. `/home/{app_user}/.cipi/last_commit`
3. `/home/{app_user}/logs/deploy.log`
4. `.git/HEAD`
5. `git rev-parse HEAD`

---

## MCP Server

The MCP (Model Context Protocol) server lets AI assistants — Cursor, Claude Desktop, and any MCP-compatible client — interact with your production application via JSON-RPC 2.0.

### Setup

**1. Enable the feature:**

```
CIPI_MCP_ENABLED=true
```

**2. Generate a dedicated token:**

```bash
php artisan cipi:mcp --token
```

**3. Add the token to `.env`:**

```
CIPI_MCP_TOKEN=your_generated_token_here
```

**4. Get client configuration snippets:**

```bash
php artisan cipi:mcp
```

This prints ready-to-paste configuration for both Cursor (native HTTP transport) and Claude Desktop (via `mcp-remote` bridge).

### Available MCP Tools

| Tool | Description |
|---|---|
| `health` | App, database, cache, and queue status |
| `app_info` | Full application configuration and environment |
| `deploy` | Trigger a zero-downtime deployment |
| `logs` | Read application logs with `type`, `level`, and `search` filters |
| `artisan` | Run Artisan commands (long-running commands are blocked) |
| `db_query` | Execute SQL queries — SELECT/SHOW/DESCRIBE/EXPLAIN for reads, INSERT/UPDATE/DELETE for writes. DDL and file I/O are blocked. Results capped at 100 rows. |

### Log Filtering

The `logs` tool supports all Cipi log types:

| Parameter | Values | Description |
|---|---|---|
| `type` | `laravel`, `nginx`, `php`, `worker`, `deploy` | Log file to read |
| `level` | `debug`, `info`, `notice`, `warning`, `error`, `critical`, `alert`, `emergency` | Minimum severity (Laravel logs only) |
| `search` | any string | Case-insensitive keyword filter |

Multi-line entries (stack traces) are kept intact.

---

## Database Anonymizer

Creates anonymized database dumps by replacing sensitive data with Faker-generated values. Safe to use in CI pipelines or share with developers.

### Setup

**1. Enable the feature:**

```
CIPI_ANONYMIZER_ENABLED=true
```

**2. Generate a token:**

```bash
php artisan cipi:anonymizer-token
```

**3. Add the token to `.env`:**

```
CIPI_ANONYMIZER_TOKEN=your_generated_token_here
```

**4. Create an `anonymization.json` configuration file** at one of:
- `/home/{app_user}/.cipi/anonymization.json`
- `/home/{app_user}/anonymization.json`
- `storage/cipi/anonymization.json`

An example file is published alongside the config:

```json
{
  "transformations": {
    "users": {
      "name":     "fakeName",
      "email":    "fakeEmail",
      "password": "password",
      "phone":    "fakePhoneNumber"
    },
    "orders": {
      "customer_notes": "fakeParagraph"
    }
  },
  "options": {
    "hash_algorithm": "auto",
    "faker_locale":   "en_US"
  }
}
```

### Supported Transformations

| Value | Description |
|---|---|
| `fakeName` | Full name |
| `fakeFirstName` / `fakeLastName` | First or last name |
| `fakeEmail` | Email address |
| `fakeCompany` | Company name |
| `fakeAddress` / `fakeCity` / `fakePostcode` | Address fields |
| `fakePhoneNumber` | Phone number |
| `fakeDate` | Random date |
| `fakeUrl` | URL |
| `fakeParagraph` | Lorem ipsum paragraph |
| `password` | Re-hashes using the project's algorithm (bcrypt / argon / auto) |

### Endpoints

**Queue an anonymization job:**

```bash
curl -X POST https://yourdomain.com/cipi/db \
  -H "Authorization: Bearer $CIPI_ANONYMIZER_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"email": "developer@example.com"}'
```

The job runs asynchronously. When complete, an email is sent to the provided address with a signed download link valid for **15 minutes**.

**Lookup a user ID by email** (useful when debugging an anonymized dump):

```bash
curl -X POST https://yourdomain.com/cipi/db/user \
  -H "Authorization: Bearer $CIPI_ANONYMIZER_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"email": "user@example.com"}'
```

```json
{
  "user_id": 123,
  "email": "user@example.com",
  "found_at": "2026-03-07T10:00:00.000000Z"
}
```

**Download a dump** (via the signed link from the email):

```
GET /cipi/db/{token}
```

### Manual Anonymization

Run the anonymizer directly from the CLI (useful for scripting):

```bash
php artisan cipi:anonymize /path/to/anonymization.json /path/to/output.sql
```

---

## Artisan Commands

| Command | Description |
|---|---|
| `php artisan cipi:status` | Shows agent configuration and live database connectivity |
| `php artisan cipi:deploy-key` | Prints the SSH deploy key for the app |
| `php artisan cipi:mcp` | Prints MCP endpoint URL and client configuration snippets |
| `php artisan cipi:mcp --token` | Generates and prints a new `CIPI_MCP_TOKEN` |
| `php artisan cipi:anonymizer-token` | Generates a secure `CIPI_ANONYMIZER_TOKEN` |
| `php artisan cipi:anonymize <config> <output>` | Creates an anonymized dump directly |

---

## Security

- **Dedicated middleware per feature** — `VerifyWebhookToken`, `VerifyMcpToken`, `VerifyAnonymizerToken` each validate their own token and return HTTP 404 when their feature is disabled (hiding endpoint existence).
- **Webhook signature verification** — HMAC-SHA256 for GitHub and Gitea; header token for GitLab; Bearer token for Bitbucket.
- **Token isolation** — webhook, MCP, and anonymizer tokens are independent. Compromising one does not affect others.
- **Signed download URLs** — anonymized dumps are served via time-limited signed URLs (15 minutes).
- **Automatic cleanup** — expired tokens and dump files are removed after download.
- **Blocked SQL patterns** — `db_query` blocks DROP, TRUNCATE, GRANT/REVOKE, and file I/O operations.
- **Blocked Artisan commands** — `serve`, `tinker`, `queue:work`, `queue:listen`, `schedule:work`, `horizon`, `octane:start`, `reverb:start` cannot be called via MCP.

---

## Documentation

Full documentation, server setup guides, and Cipi panel integration details are available at [cipi.sh](https://cipi.sh).

## License

MIT — see [LICENSE](LICENSE).
