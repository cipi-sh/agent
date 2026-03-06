# Cipi Agent for Laravel

Laravel package that integrates your application with [Cipi](https://cipi.sh) server control panel.

## What it does

- **Webhook endpoint** — receives push events from GitHub or GitLab and triggers a deploy via Deployer
- **Health check** — exposes a `/cipi/health` endpoint that reports app, database, cache, and queue status
- **Database anonymizer** — creates anonymized database dumps for local development/testing (when `CIPI_ANONYMIZER_TOKEN` is set)
- **MCP server** — Model Context Protocol endpoint for AI assistants like Cursor and Claude Desktop
- **Artisan commands** — `cipi:status`, `cipi:deploy-key`, `cipi:mcp`, `cipi:anonymizer-token` for diagnostics and token management

## Installation

```bash
composer require andreapollastri/cipi-agent
```

The service provider is auto-discovered. Configuration is handled automatically by Cipi when it creates your app.

To publish the config manually:

```bash
php artisan vendor:publish --tag=cipi-config
```

## Database Anonymizer

When `CIPI_ANONYMIZER_TOKEN` is set in your `.env`, the package provides database anonymization features for creating safe development datasets.

### Setup

1. **Generate a token**:

   ```bash
   php artisan cipi:anonymizer-token
   ```

2. **Add to your `.env`**:

   ```
   CIPI_ANONYMIZER_TOKEN=your_generated_token_here
   ```

3. **Create configuration file** at one of these locations:
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
