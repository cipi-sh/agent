<h1 align="center">Cipi Agent for Laravel</h1>

<p align="center">
  The official Laravel companion package for the <a href="https://cipi.sh"><strong>Cipi</strong></a> server control panel.<br>
  Automated deployments, health monitoring, AI-powered management, and database anonymization — all in one package.
</p>

---

## What is Cipi Agent?

**Cipi Agent** is a Laravel package designed to work alongside the [Cipi](https://cipi.sh) server control panel. While Cipi manages your server infrastructure (LEMP stack, SSL, PHP versions, domains, etc.), this package bridges the gap between your Laravel application and Cipi by providing:

- **Webhook-triggered deployments** from GitHub and GitLab
- **Real-time health monitoring** of your app, database, cache, and queue
- **An MCP server** that lets AI assistants (Cursor, Claude Desktop) manage your production app
- **A database anonymizer** that creates safe, privacy-compliant dumps for local development

All features are configurable via environment variables with zero boilerplate. When you create an application through the Cipi panel, the required environment variables are injected automatically.

> **Note:** This package is built to work with servers managed by [Cipi](https://cipi.sh). While some features (health check, MCP server) can work standalone, full functionality — including automated deployments and log access — requires a Cipi-managed environment.

---

## Table of Contents

- [Requirements](#requirements)
- [Installation](#installation)
- [Configuration](#configuration)
- [Webhook Deploy](#webhook-deploy)
- [Health Check](#health-check)
- [MCP Server](#mcp-server)
- [Database Anonymizer](#database-anonymizer)
- [Artisan Commands](#artisan-commands)
- [Security](#security)
- [Documentation](#documentation)
- [License](#license)

---

## Requirements

| Requirement | Version                                      |
| ----------- | -------------------------------------------- |
| PHP         | 8.3 or higher                                |
| Laravel     | 12 or higher                                 |
| Database    | MySQL or PostgreSQL (for the anonymizer)     |
| CLI tools   | `mysqldump` / `pg_dump` (for the anonymizer) |

---

## Installation

```bash
composer require andreapollastri/cipi-agent
```

The service provider is **auto-discovered** — no changes to `config/app.php` are needed.

If your application runs on a Cipi-managed server, the required environment variables are already in place. Otherwise, you can publish the configuration file to customize defaults:

```bash
php artisan vendor:publish --tag=cipi-config
```

Verify that everything is configured correctly:

```bash
php artisan cipi:status
```

---

## Configuration

All settings are driven by environment variables. When Cipi creates your application, it sets the core variables automatically. You only need to configure the optional features you want to enable.

| Variable                | Default                  | Description                                                        |
| ----------------------- | ------------------------ | ------------------------------------------------------------------ |
| `CIPI_WEBHOOK_TOKEN`    | `""`                     | Secret token for webhook authentication (set by Cipi)              |
| `CIPI_APP_USER`         | `""`                     | Linux username for the app (set by Cipi)                           |
| `CIPI_PHP_VERSION`      | system PHP               | PHP version reported in health check (set by Cipi)                 |
| `CIPI_DEPLOY_SCRIPT`    | `~/.deployer/deploy.php` | Path to the Deployer config file (set by Cipi)                     |
| `CIPI_DEPLOY_BRANCH`    | `null`                   | Only deploy pushes to this branch (`null` = any branch)            |
| `CIPI_ROUTE_PREFIX`     | `cipi`                   | URL prefix for all Cipi Agent routes                               |
| `CIPI_LOG_CHANNEL`      | `null`                   | Laravel log channel for deploy events                              |
| `CIPI_HEALTH_CHECK`     | `true`                   | Enable/disable the health check endpoint                           |
| `CIPI_HEALTH_TOKEN`     | `""`                     | Bearer token for health check (falls back to `CIPI_WEBHOOK_TOKEN`) |
| `CIPI_MCP`              | `false`                  | Enable/disable the MCP server endpoint                             |
| `CIPI_MCP_TOKEN`        | `""`                     | Bearer token for MCP access                                        |
| `CIPI_ANONYMIZER`       | `false`                  | Enable/disable the database anonymizer                             |
| `CIPI_ANONYMIZER_TOKEN` | `""`                     | Bearer token for anonymizer access                                 |

You can toggle features directly from the CLI without editing `.env` manually:

```bash
php artisan cipi:service mcp --enable
php artisan cipi:service health --disable
php artisan cipi:service anonymize --enable
```

---

## Webhook Deploy

The webhook endpoint receives push events from your Git provider and writes a `.deploy-trigger` flag file. The Cipi cron picks up this flag and runs [Deployer](https://deployer.org) for zero-downtime deployments.

**Endpoint:** `POST /cipi/webhook`

### Supported Git Providers

| Provider   | Authentication method             |
| ---------- | --------------------------------- |
| **GitHub** | `X-Hub-Signature-256` HMAC-SHA256 |
| **GitLab** | `X-Gitlab-Token` header           |

### Setup

1. In your Git provider settings, add a new webhook pointing to:

   ```
   https://yourdomain.com/cipi/webhook
   ```

2. Use the value of `CIPI_WEBHOOK_TOKEN` as the webhook secret.

3. Select **push events** as the trigger.

> **Tip:** On Cipi-managed servers, the webhook is configured automatically when you connect your Git repository through the panel.

### Branch Filtering

To deploy only when a specific branch is pushed:

```env
CIPI_DEPLOY_BRANCH=main
```

When set, pushes to other branches are acknowledged (HTTP 200) but do not trigger a deploy.

---

## Health Check

A lightweight monitoring endpoint that returns the real-time status of your application and its dependencies.

**Endpoint:** `GET /cipi/health`

### Authentication

Protected by Bearer token. The endpoint resolves the token in this order:

1. `CIPI_HEALTH_TOKEN` (dedicated)
2. `CIPI_WEBHOOK_TOKEN` (fallback)

Generate a dedicated token:

```bash
php artisan cipi:generate-token health
```

### Usage

```bash
curl -H "Authorization: Bearer YOUR_TOKEN" https://yourdomain.com/cipi/health
```

### Response Example

```json
{
  "status": "healthy",
  "app_user": "myapp",
  "php": "8.3.0",
  "laravel": "11.0.0",
  "environment": "production",
  "checks": {
    "app": { "ok": true, "version": "2.1.0", "debug": false },
    "database": { "ok": true, "database": "myapp_prod" },
    "cache": { "ok": true },
    "queue": { "ok": true, "connection": "redis", "pending_jobs": 0 },
    "deploy": {
      "ok": true,
      "commit": "a1b2c3d4e5f6...",
      "short_commit": "a1b2c3d"
    }
  },
  "timestamp": "2026-03-07T10:00:00.000000Z"
}
```

### Deploy Commit Detection

The last deployed commit is resolved from the first available source:

1. `/home/{app_user}/.cipi/deploy.json` (Cipi deploy metadata)
2. `/home/{app_user}/.cipi/last_commit`
3. `/home/{app_user}/logs/deploy.log`
4. `.git/HEAD`
5. `git rev-parse HEAD`

### Integration with Monitoring Tools

The health endpoint is ideal for services like **UptimeRobot**, **Grafana**, **Better Stack**, or any monitoring solution that supports HTTP checks with Bearer token authentication.

---

## MCP Server

The **Model Context Protocol** (MCP) server lets AI assistants interact with your production Laravel application through a standard JSON-RPC 2.0 interface. Compatible with **Cursor**, **Claude Desktop**, and any MCP-compatible client.

**Endpoint:** `POST /cipi/mcp`

### Setup

**1. Enable the MCP server:**

```bash
php artisan cipi:service mcp --enable
```

**2. Generate a dedicated token:**

```bash
php artisan cipi:generate-token mcp
```

**3. Get client configuration:**

```bash
php artisan cipi:mcp
```

This prints ready-to-paste configuration snippets for:

- **Cursor** — native HTTP transport (direct connection)
- **Claude Desktop** — via `mcp-remote` bridge

### Available Tools

The MCP server exposes six tools that AI assistants can use to inspect and manage your application:

#### `health` — Application Status

Returns the same comprehensive status as the `/cipi/health` endpoint: app version, database connectivity, cache, queue health, and last deploy commit.

#### `app_info` — Application Configuration

Returns full application configuration and environment details, including Laravel version, PHP version, configured services, and environment settings.

#### `deploy` — Trigger Deployment

Triggers a zero-downtime deployment through the Cipi deploy pipeline. The AI assistant can deploy new versions after reviewing code changes or fixing issues.

#### `logs` — Read Application Logs

Reads and filters application logs with support for all Cipi log types:

| Parameter | Values                                                                          | Description                          |
| --------- | ------------------------------------------------------------------------------- | ------------------------------------ |
| `type`    | `laravel`, `nginx`, `php`, `worker`, `deploy`                                   | Log file to read                     |
| `level`   | `debug`, `info`, `notice`, `warning`, `error`, `critical`, `alert`, `emergency` | Minimum severity (Laravel logs only) |
| `search`  | any string                                                                      | Case-insensitive keyword filter      |

Multi-line entries (stack traces) are kept intact during filtering.

#### `artisan` — Run Artisan Commands

Executes Artisan commands remotely. Long-running and potentially dangerous commands are blocked for safety (see [Security](#security)).

#### `db_query` — Execute SQL Queries

Runs SQL queries against the application database:

- **Read:** `SELECT`, `SHOW`, `DESCRIBE`, `EXPLAIN`
- **Write:** `INSERT`, `UPDATE`, `DELETE`
- **Blocked:** `DROP`, `TRUNCATE`, `GRANT`, `REVOKE`, file I/O operations

Results are formatted as a readable ASCII table, capped at 100 rows.

### Example Workflow

With the MCP server enabled, you can ask your AI assistant things like:

- _"Check the health of the production app"_
- _"Show me the last 50 error logs"_
- _"Run `php artisan migrate:status`"_
- _"Query the users table to find accounts created today"_
- _"Deploy the latest changes"_

---

## Database Anonymizer

Creates anonymized database dumps by replacing sensitive data (names, emails, passwords, addresses) with realistic fake values generated by [Faker](https://fakerphp.github.io/). The resulting SQL file is safe to share with developers, use in CI pipelines, or load into local environments.

### Setup

**1. Enable the anonymizer:**

```bash
php artisan cipi:service anonymize --enable
```

**2. Generate a token:**

```bash
php artisan cipi:generate-token anonymize
```

**3. Initialize the configuration file:**

```bash
php artisan cipi:init-anonymize
```

This creates `/home/{app_user}/.db/anonymization.json` from the built-in template. The file is stored **outside the project repository** to keep sensitive field mappings out of version control (permissions `0640`). Use `--force` to overwrite an existing file.

**4. Edit the configuration** to match your actual tables and sensitive columns:

```json
{
  "transformations": {
    "users": {
      "name": "fakeName",
      "email": "fakeEmail",
      "password": "password",
      "phone": "fakePhoneNumber"
    },
    "orders": {
      "customer_notes": "fakeParagraph"
    }
  },
  "options": {
    "hash_algorithm": "auto",
    "faker_locale": "en_US"
  }
}
```

### Supported Transformations

| Transformation    | Output                                                          |
| ----------------- | --------------------------------------------------------------- |
| `fakeName`        | Full name (e.g., "John Smith")                                  |
| `fakeFirstName`   | First name                                                      |
| `fakeLastName`    | Last name                                                       |
| `fakeEmail`       | Email address                                                   |
| `fakeCompany`     | Company name                                                    |
| `fakeAddress`     | Full street address                                             |
| `fakeCity`        | City name                                                       |
| `fakePostcode`    | Postal code                                                     |
| `fakePhoneNumber` | Phone number                                                    |
| `fakeDate`        | Random date                                                     |
| `fakeUrl`         | URL                                                             |
| `fakeParagraph`   | Lorem ipsum paragraph                                           |
| `password`        | Re-hashes using the project's algorithm (bcrypt / argon / auto) |

### API Endpoints

#### Queue an Anonymization Job

```bash
curl -X POST https://yourdomain.com/cipi/db \
  -H "Authorization: Bearer $CIPI_ANONYMIZER_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"email": "developer@example.com"}'
```

The anonymization runs **asynchronously**. When complete, an email is sent to the provided address with a **signed download link** valid for 15 minutes.

#### Lookup a User by Email

Useful when debugging an anonymized dump — find the original user ID for a given email:

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

#### Download the Dump

The email contains a signed URL in this format:

```
GET /cipi/db/{token}
```

The link expires after 15 minutes and the file is cleaned up after download.

### CLI Anonymization

For scripting or CI pipelines, you can run the anonymizer directly:

```bash
php artisan cipi:anonymize /path/to/anonymization.json /path/to/output.sql
```

---

## Artisan Commands

Cipi Agent provides a complete set of Artisan commands for managing all features from the terminal.

### Status & Info

| Command                       | Description                                                  |
| ----------------------------- | ------------------------------------------------------------ |
| `php artisan cipi:status`     | Show agent configuration and live database connectivity      |
| `php artisan cipi:deploy-key` | Print the SSH deploy key for the current app                 |
| `php artisan cipi:mcp`        | Print the MCP endpoint URL and client configuration snippets |

### Token Management

| Command                                     | Description                               |
| ------------------------------------------- | ----------------------------------------- |
| `php artisan cipi:generate-token mcp`       | Generate a secure `CIPI_MCP_TOKEN`        |
| `php artisan cipi:generate-token health`    | Generate a secure `CIPI_HEALTH_TOKEN`     |
| `php artisan cipi:generate-token anonymize` | Generate a secure `CIPI_ANONYMIZER_TOKEN` |

### Service Toggle

| Command                                        | Description                       |
| ---------------------------------------------- | --------------------------------- |
| `php artisan cipi:service mcp --enable`        | Enable the MCP server             |
| `php artisan cipi:service mcp --disable`       | Disable the MCP server            |
| `php artisan cipi:service health --enable`     | Enable the health check endpoint  |
| `php artisan cipi:service health --disable`    | Disable the health check endpoint |
| `php artisan cipi:service anonymize --enable`  | Enable the database anonymizer    |
| `php artisan cipi:service anonymize --disable` | Disable the database anonymizer   |

### Database Anonymizer

| Command                                        | Description                                            |
| ---------------------------------------------- | ------------------------------------------------------ |
| `php artisan cipi:init-anonymize`              | Create `anonymization.json` from the built-in template |
| `php artisan cipi:anonymize <config> <output>` | Run an anonymized dump directly from the CLI           |

---

## Security

Cipi Agent is designed with a **defense-in-depth** approach. Every feature has its own authentication layer and can be independently enabled or disabled.

### Token Isolation

Each feature uses a **dedicated Bearer token**. Compromising one token does not grant access to other features:

| Feature        | Token variable          | Middleware              |
| -------------- | ----------------------- | ----------------------- |
| Webhook deploy | `CIPI_WEBHOOK_TOKEN`    | `VerifyWebhookToken`    |
| Health check   | `CIPI_HEALTH_TOKEN`     | `VerifyHealthToken`     |
| MCP server     | `CIPI_MCP_TOKEN`        | `VerifyMcpToken`        |
| DB anonymizer  | `CIPI_ANONYMIZER_TOKEN` | `VerifyAnonymizerToken` |

### Feature Gating

When a feature is disabled, its middleware returns **HTTP 404** — the endpoint appears to not exist at all, revealing nothing to potential attackers.

### Webhook Signature Verification

The webhook endpoint uses provider-specific verification:

- **GitHub** — HMAC-SHA256 signature validation
- **GitLab** — secret token header comparison

### MCP Safety Measures

- **Blocked Artisan commands:** `serve`, `tinker`, `queue:work`, `queue:listen`, `schedule:work`, `horizon`, `octane:start`, `reverb:start`
- **Blocked SQL operations:** `DROP`, `TRUNCATE`, `GRANT`, `REVOKE`, and file I/O
- **Query result limits:** maximum 100 rows per query

### Anonymizer Safety

- **Signed download URLs** with 15-minute expiration
- **Automatic file cleanup** after download
- **Configuration stored outside the repository** (`/home/{app_user}/.db/`) with restricted permissions

---

## Documentation

This package is part of the **Cipi** ecosystem. For full documentation including server setup guides, panel configuration, and deployment workflows, visit:

**[cipi.sh](https://cipi.sh)**

---

## License

MIT — see [LICENSE](LICENSE).

---

<p align="center">
  Built with care by <a href="https://web.ap.it">Andrea Pollastri</a> for the <a href="https://cipi.sh">Cipi</a> community.
</p>
