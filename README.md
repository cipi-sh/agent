# Cipi Agent for Laravel

Laravel package that integrates your application with [Cipi](https://cipi.sh) server control panel.

## What it does

- **Webhook endpoint** — receives push events from GitHub or GitLab and triggers a deploy via Deployer
- **Health check** — exposes a `/cipi/health` endpoint that reports app, database, cache, queue, and deploy status
- **Database anonymizer** — creates anonymized database dumps for local development/testing (when `CIPI_ANONYMIZER_ENABLED=true`)
- **MCP server** — Model Context Protocol endpoint for AI assistants like Cursor and Claude Desktop
- **Artisan commands** — `cipi:status`, `cipi:deploy-key`, `cipi:mcp`, `cipi:anonymizer-token` for diagnostics and token management

## Installation

```bash
composer require andreapollastri/cipi-agent
```

The service provider is auto-discovered. Configuration is handled automatically by Cipi when it creates your app.

To publish the config manually:

### Health Check Details

The `/cipi/health` endpoint provides comprehensive status information:

```json
{
  "status": "healthy",
  "app_user": "myapp",
  "php": "8.2.0",
  "laravel": "11.0.0",
  "environment": "production",
  "checks": {
    "app": {
      "ok": true,
      "version": "1.0.0",
      "debug": false
    },
    "database": {
      "ok": true,
      "database": "myapp_prod"
    },
    "cache": {
      "ok": true
    },
    "queue": {
      "ok": true,
      "connection": "redis",
      "pending_jobs": 0
    },
    "deploy": {
      "ok": true,
      "commit": "a1b2c3d4e5f6789abcdef0123456789abcdef0123",
      "short_commit": "a1b2c3d",
      "info": {
        "timestamp": "2026-03-06T10:30:00Z",
        "branch": "main"
      }
    }
  },
  "timestamp": "2026-03-06T10:35:00.000000Z"
}
```

**Deploy Information Sources** (checked in order):
1. `/home/{app_user}/.cipi/deploy.json` - Cipi deploy metadata
2. `/home/{app_user}/.cipi/last_commit` - Last commit hash file
3. `/home/{app_user}/logs/deploy.log` - Deploy log parsing
4. `.git/HEAD` - Git repository HEAD
5. `git rev-parse HEAD` - Git command execution

```bash
php artisan vendor:publish --tag=cipi-config
```

## Database Anonymizer

When `CIPI_ANONYMIZER_TOKEN` is set in your `.env`, the package provides database anonymization features for creating safe development datasets.

### Setup

1. **Enable the feature in your `.env`**:
   ```
   CIPI_ANONYMIZER_ENABLED=true
   ```

2. **Generate a token**:

   ```bash
   php artisan cipi:anonymizer-token
   ```

3. **Add the token to your `.env`**:

   ```
   CIPI_ANONYMIZER_TOKEN=your_generated_token_here
   ```

4. **Create configuration file** at one of these locations:
   - `/home/{app_user}/.cipi/anonymization.json`
   - `/home/{app_user}/anonymization.json`
   - `storage/cipi/anonymization.json`

4. **Example configuration** (`anonymization.example.json` is published with the config):
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

### Usage

**Start anonymization job**:

```bash
curl -X POST /cipi/db \
  -H "Authorization: Bearer YOUR_ANONYMIZER_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"email": "developer@example.com"}'
```

**Find user ID by email** (useful for debugging anonymized databases):

```bash
# This endpoint uses the same token as the anonymizer
curl -X POST /cipi/db/user \
  -H "Authorization: Bearer YOUR_ANONYMIZER_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"email": "user@example.com"}'
```

Response:
```json
{
  "user_id": 123,
  "email": "user@example.com",
  "found_at": "2026-03-06T10:30:00.000000Z"
}
```

**Receive email notification** with download link (valid for 15 minutes).

### Supported Transformations

- `fakeName`, `fakeFirstName`, `fakeLastName`
- `fakeEmail`, `fakeCompany`, `fakeAddress`, `fakeCity`, `fakePostcode`
- `fakePhoneNumber`, `fakeDate`, `fakeUrl`, `fakeParagraph`
- `password` — re-hashes using your project's algorithm (bcrypt/argon/auto)

### Manual Anonymization

You can also run anonymization directly:

```bash
php artisan cipi:anonymize /path/to/config.json /path/to/output.sql
```

## MCP Server

The package includes a Model Context Protocol (MCP) server that allows AI assistants to interact with your Laravel application.

### Setup

1. **Generate MCP token** (recommended for security):

   ```bash
   php artisan cipi:mcp --token
   ```

2. **Add to your `.env`**:

   ```
   CIPI_MCP_TOKEN=your_generated_token_here
   ```

3. **Get setup instructions**:
   ```bash
   php artisan cipi:mcp
   ```

This provides ready-to-use configuration snippets for Cursor and Claude Desktop.

### Available MCP Tools

- `health` — Check app, database, cache, and queue status
- `app_info` — Get detailed application configuration
- `deploy` — Trigger a deployment
- `logs` — Read application logs with filtering options
- `artisan` — Run Artisan commands (with safety restrictions)
- `db_query` — Execute SQL queries for debugging

## Authentication & Security

The package uses dedicated middleware for different functionalities:

- **`VerifyWebhookToken`** — Handles webhook authentication with signature verification (GitHub/GitLab/Bitbucket/Gitea)
- **`VerifyMcpToken`** — Protects MCP server endpoints using `CIPI_AGENT_MCP_TOKEN`. Returns 404 if MCP is disabled.
- **`VerifyAnonymizerToken`** — Secures database anonymizer endpoints using `CIPI_ANONYMIZER_TOKEN`. Returns 404 if anonymizer is disabled.

Each middleware supports Bearer token authentication and query string fallback for compatibility. When features are disabled, their endpoints return HTTP 404 to indicate the functionality is not available.

## Requirements

- PHP 8.1+
- Laravel 10.0+
- MySQL or PostgreSQL database
- `mysqldump` or `pg_dump` command-line tools (for database anonymizer)
- FakerPHP library (automatically installed)

## Documentation

For full documentation, webhook setup, and configuration details visit [cipi.sh](https://cipi.sh).

## License

MIT
