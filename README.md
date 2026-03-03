# Cipi Agent for Laravel

Laravel package that integrates your application with [Cipi](https://cipi.sh) server control panel.

## What it does

- **Webhook endpoint** — receives push events from GitHub or GitLab and triggers a deploy via Deployer
- **Health check** — exposes a `/cipi/health` endpoint that reports app, database, cache, and queue status
- **Artisan commands** — `cipi:status` and `cipi:deploy-key` for quick diagnostics

## Installation

```bash
composer require andreapollastri/cipi-agent
```

The service provider is auto-discovered. Configuration is handled automatically by Cipi when it creates your app.

To publish the config manually:

```bash
php artisan vendor:publish --tag=cipi-config
```

## Documentation

For full documentation, webhook setup, and configuration details visit [cipi.sh](https://cipi.sh).

## License

MIT
