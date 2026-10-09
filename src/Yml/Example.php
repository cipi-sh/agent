<?php

namespace Cipi\Agent\Yml;

/**
 * The blank, commented template `cipi yml example <app>` prints on the
 * server, in the app's namespace so it validates as-is.
 */
final class Example
{
    public static function render(?string $app, ?string $domain = null): string
    {
        $app = $app !== null && $app !== '' ? $app : 'example';
        $domain = $domain !== null && $domain !== '' ? $domain : 'example.com';

        return strtr(self::TEMPLATE, ['{app}' => $app, '{domain}' => $domain]);
    }

    private const TEMPLATE = <<<'YML'
# cipi.yml — declarative configuration for one Cipi app.
#
# Commit this at the root of your repository. After a deploy, run
#   cipi yml plan {app}     to see what would change
#   cipi yml apply {app}    to apply it
# or turn on automatic apply with
#   cipi yml auto {app} on
#
# Only this app is ever touched: databases must be named {app} or {app}_*,
# and backup profiles {app} or {app}-*. Everything else is rejected.
#
# Check the file before committing:  php artisan cipi:yml validate
# Tip: to start from what the server already has, use
#   cipi yml generate {app} > cipi.yml

version: 1

app:
  # 8.3, 8.4 or 8.5 — must already be installed (cipi php install 8.5)
  php: "8.5"

  # The declared list replaces the current aliases: an alias you remove here
  # is removed from the server. The primary domain is not managed here.
  aliases:
    - "www.{domain}"
    - "*.{domain}"      # wildcard, for multi-tenant subdomains

  # Per-app php.ini overrides. Server-wide values stay with `cipi ini set`.
  ini:
    upload_max_filesize: 50M
    post_max_size: 60M
    memory_limit: 512M

  # Per-app limits (cipi app limits). Only the declared keys are reconciled;
  # the bounds are the CLI's (fpm_max_children 1-50, octane_workers 1-16,
  # worker_procs 1-20) and a value outside them blocks the plan.
  # limits:
  #   memory_limit: 512M
  #   fpm_max_children: 10

  # Canonical www redirect: to-root (www → apex), from-root (apex → www) or none.
  # The other name of the pair must be in the aliases above.
  www: to-root

  # HTTP basic auth in front of the app (ACME challenges stay public).
  # Never commit a password. A user listed by name keeps the password already set
  # on the server (cipi basicauth enable {app} --user=NAME); password_hash takes
  # a bcrypt (cost 10+) or SHA-512 crypt hash:  htpasswd -nbB -C 12 NAME 'secret'
  # Server users not listed here are removed. "basic_auth: false" turns it off.
  basic_auth:
    users:
      - admin
      # - name: preview
      #   password_hash: "$2y$12$…"

# Extra databases beyond the one created with the app.
# Credentials land in /home/{app}/shared/cipi-databases.env — never dropped.
databases:
  - name: {app}_reporting
  - name: {app}_analytics
    engine: pgsql          # mariadb (default) or pgsql

workers:
  horizon: false           # true replaces the queue workers below

  # Laravel Reverb. Cipi gives it a localhost port, a Supervisor program and an
  # nginx proxy for /app/{key} and /apps/{id}/… on this app's own domain, and
  # generates REVERB_APP_ID/KEY/SECRET (plus the VITE_ copies) in the .env the
  # first time it is enabled. An app with its own /app or /apps route cannot
  # use it behind the same domain — those paths belong to the Pusher protocol.
  reverb: false

  queues:
    - queue: default
      processes: 2
    - queue: emails
      processes: 1
      tries: 5
      timeout: 300

# Laravel scheduler (* * * * * artisan schedule:run)
schedule: true

# Meilisearch for Laravel Scout: a scoped key for indexes {app}-* and the
# SCOUT_* / MEILISEARCH_* variables in .env. The engine itself is installed by
# root (cipi search install). false turns it off and keeps the indexes.
search: false

# Deploy recipe options (the same set as `cipi app deploy-config`) and
# commands to run after every successful deploy, from the live release
# directory. Each post step uses an allowlisted runner — no shell, no pipes,
# no free-form scripts. post runs on both 'cipi deploy' and the Git webhook
# and does not require 'cipi yml auto'.
deploy:
  # keep_releases: 5          # releases kept for rollback (1-20)
  # migrate: false            # skip artisan:migrate in the recipe
  # optimize: true            # artisan:optimize after vendors
  # storage_link: true        # artisan:storage:link after vendors
  # queue_restart: true       # artisan:queue:restart after the symlink
  # horizon_terminate: true   # horizon:terminate before the symlink
  # extra_artisan: [ "view:clear" ]   # extra artisan commands in the recipe
  # snapshot: true            # database snapshot before each deploy
  post:
    - artisan cache:clear
    - artisan scout:import --force
    - npm run build
    - composer dump-autoload -o
  # post_on_failure: abort   # default warn — log + email, leave the release live

# Force the HTTP → HTTPS redirect (cipi ssl force). It needs a certificate
# (cipi ssl install {app}) and can only ever be turned on from this file.
# ssl:
#   force_https: true

# Names (never values) the app's .env must carry: the plan is blocked while
# one is missing or empty, so code that expects them is never deployed blind.
# env:
#   required: [ STRIPE_KEY, MAIL_HOST ]

# Scheduled commands, through the same allowlisted runners as deploy.post.
# The declared list replaces the '# cipi-yml' lines of the app's crontab and
# never touches the rest of it (the Laravel scheduler included).
# crons:
#   - every: 30m              # 5m/10m/15m/20m/30m, 1h..12h, 1d..28d
#     run: artisan queue:prune-batches
#   - cron: "15 3 * * *"      # or five cron fields
#     run: php scripts/cleanup.php

# HTTP healthcheck. Probed every 5 minutes and right after every deploy.
# The URL must be one of this app's own domains.
health:
  url: "https://{domain}/up"
  expect: 200
  # grace: 8                      # seconds before the first probe after a deploy
  # postdeploy: false             # skip the check right after a deploy
  # rollback_on_unhealthy: true   # undo a release that fails the check
  #                               # (the code symlink only — migrations are NOT undone)
  # Use "health: {enabled: false}" to remove the healthcheck entirely.

# Whole-app redirect (every name of the app, www included, in one hop).
# ACME stays public, and the redirects/proxies below keep working.
# redirect:
#   to: "https://new.example.com"
#   code: 301              # 301, 302, 307 or 308
#   keep_path: true        # /a?b → https://new.example.com/a?b
#   enabled: true          # false keeps the target but serves the app again
# "redirect: {enabled: false}" removes it.

# Path redirects. The declared list replaces the current one.
# A 'from' ending in / is a prefix and carries the rest of the path over.
redirects:
  - from: /old-page
    to: /new-page
  - from: /blog/
    to: "https://blog.{domain}/"
    code: 308

# Reverse proxy on a prefix. The declared list replaces the current one.
# Refused from this file: Cipi's own local ports (databases, Valkey, SSH,
# another app's Octane/Reverb) and link-local / metadata addresses.
proxies:
  - prefix: /api/
    upstream: "http://127.0.0.1:3000"
    strip_prefix: true     # /api/users → upstream/users
    # preserve_host: true
    # timeout: 60          # seconds, 1-3600
    # buffering: false     # for SSE, long polling, streamed downloads

# Node apps only (cipi app create --node=…). Read by each deploy right after the
# checkout, so the commit that changes them is built and started with them.
# Needs: cipi yml auto {app} on. A new Node major is installed by root first.
# node:
#   framework: next        # next nuxt sveltekit astro remix vite — fills the rest
#   mode: ssr              # spa | static | ssr
#   version: 22            # even (LTS) major
#   build: npm run build
#   start: npx next start -H 127.0.0.1   # ssr: runs without a shell
#   health_path: /         # ssr: must answer below 500 before nginx switches
#   output: dist           # spa/static: the directory nginx serves

# Backup strategy for this app. Profile names must be {app} or {app}-*.
backup:
  profiles:
    # Frequent, cheap: databases only, without the noisy tables.
    - name: {app}-db
      scope: db
      databases: ["{app}", "{app}_*", "tenant_*"]
      exclude_tables: ["*.jobs", "*.failed_jobs", "*.telescope_*"]
      every: 30m           # 5m/10m/15m/20m/30m, 1h..12h, 1d..28d
      keep: 48             # keep the last 48 runs
      destinations: [local]

    # Slower, complete, off-site and encrypted.
    - name: {app}-nightly
      scope: all           # all | files | db
      cron: "0 2 * * *"    # or use `every:`
      keep_days: 14
      destinations: [s3]
      encrypt: true
YML;
}
