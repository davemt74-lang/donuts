# Production deployment

## Requirements
- PHP 8.1+
- PDO SQLite (`pdo_sqlite`) — current certified database runtime
- cURL
- mbstring
- JSON
- HTTPS
- writable `storage/`

## Deploy
1. Copy the repository to the server with the web root pointed at `public/`.
2. Copy `.env.example` to `.env` and set production values.
3. Set `APP_ENV=production` and an HTTPS `APP_URL`.
4. Configure a long random `APP_KEY`.
5. Configure Stripe secret and webhook signing keys.
6. Run `php scripts/migrate.php`.
7. Open `/setup-admin.php` once and create the first Super Admin. The setup route locks itself after creation.
8. Run `php scripts/preflight.php`; every check must pass.
9. Run `php scripts/release-audit.php`; every required check must pass. Missing image assets are reported as warnings until uploaded.
10. Configure Stripe's webhook endpoint as `/stripe-webhook.php`.
11. Schedule `php scripts/send-notifications.php` every few minutes.
12. Schedule `php scripts/recover-reservations.php` every 5 minutes so abandoned Stripe sessions cannot strand inventory.
13. Set `CHECKOUT_HOLD_MINUTES` between 30 and 120 (30 is the default).
14. Upload storefront images into `public/images/`.
15. Verify `/health.php` returns HTTP 200.
16. Trigger the **Release Package** workflow to generate the deploy ZIP and SHA-256 manifest.

## Canonical image paths
- `public/images/hero.png`
- `public/images/gift-box.png`
- `public/images/footer.png`
- `public/images/flavor-smores.png`
- `public/images/flavor-caramel-pretzel.png`
- `public/images/flavor-cookies-cream.png`
- `public/images/flavor-mint-chocolate.png`

Never expose `.env`, database files, Stripe secrets, or writable storage through the web root.


## Database backups
Schedule this at least daily:

```bash
php scripts/backup-database.php scheduled
```

Backups are stored outside the web root in `storage/backups/`, verified with SQLite `PRAGMA integrity_check`, and accompanied by SHA-256 metadata. Configure `BACKUP_RETENTION_DAYS` and `BACKUP_MAX_FILES`.

## Database restore
Restores are CLI-only:

```bash
php scripts/restore-database.php --file=store-YYYYMMDD-HHMMSS-xxxxxx.sqlite --confirm=RESTORE
```

The restore command creates a pre-restore backup, enables maintenance mode, checkpoints and closes SQLite, validates the staged database, swaps it into place, runs a final integrity check, and only then removes maintenance mode.


## Operations monitoring
Schedule the operational health worker every 5 minutes:

```bash
php scripts/check-operations.php
```

Set `ALERT_EMAIL` to an operations mailbox to receive deduplicated health alerts through the transactional email outbox. `OBSERVABILITY_RETENTION_DAYS` controls resolved-event retention. Runtime errors also fall back to `storage/logs/app.log` if the database is unavailable.

The public `/health.php` endpoint exposes only readiness status, database availability, a request ID, and timestamp. Detailed operational state is available to administrators at `/admin-operations.php`.


## Scheduled worker heartbeat expectations
The production scheduler should match these defaults:

- `send-notifications.php`: every 5 minutes
- `recover-reservations.php`: every 5 minutes
- `check-operations.php`: every 5 minutes
- `backup-database.php scheduled`: every 24 hours

The matching `JOB_*_INTERVAL_MINUTES` values are used for stale-worker detection. Admin → Operations shows last success, failure streaks, active runs and stale jobs. Overlapping copies of the same worker are blocked automatically.


## Safe migrations and release certification
Database migrations are tracked by filename and SHA-256 checksum. Never edit or delete an applied migration; add a new migration instead.

Before deployment:

```bash
php scripts/migrate.php --status
php scripts/migrate.php
```

Production migration runs create a verified pre-migration database backup whenever pending migrations exist and refuse to run concurrently. After activating a release:

```bash
php scripts/post-deploy-check.php
```

The post-deploy check verifies migration state, SQLite integrity, required application files/tables, and operational health, then writes `storage/release-certification.json`.


## Shipping and local pickup
After migrations, configure fulfillment in **Admin → Shipping**. Set active shipping methods, rates, free-shipping thresholds, ETA ranges, local-pickup ZIP codes, pickup location/hours, and customer-facing instructions. Checkout always re-quotes the selected method server-side before payment.


## Session and browser security
Production sessions force the Secure and HttpOnly cookie flags with SameSite=Lax. Configure `ADMIN_SESSION_IDLE_MINUTES`, `ADMIN_SESSION_MAX_HOURS`, `USER_SESSION_IDLE_MINUTES`, and `USER_SESSION_MAX_HOURS` to match the deployment policy.

The application emits CSP, frame-denial, MIME-sniffing, referrer, permissions, cross-origin resource, and HSTS headers in production. Keep `APP_URL` on HTTPS; authenticated responses are marked private/no-store.


## Reverse proxy boundary
Leave `TRUST_PROXY_HEADERS=0` unless TLS is terminated by a trusted reverse proxy that overwrites `X-Forwarded-Proto`. Set it to `1` only in that controlled topology; direct client-supplied forwarded headers are otherwise ignored.


## Tax configuration
Configure Stripe tax behavior in **Admin → Tax**. The store currently certifies exclusive tax only, so Stripe tax is added after the server-calculated pre-tax order total and then reconciled against the Stripe Checkout result.

If Stripe Automatic Tax is enabled, configure the required business tax registrations in Stripe separately. Optionally set a Stripe product tax code such as `txcd_99999999`; leaving it blank uses Stripe's configured default tax treatment.


## Customer support
Set `SUPPORT_EMAIL` to the internal mailbox that should receive new-ticket alerts. Customers submit requests at `/contact.php`; administrators manage the queue at **Admin → Support**. Order-linked tickets verify ownership for signed-in customers and verify the order email for guests.


## Batch fulfillment
Admin → Orders supports atomic batch transitions from **Paid → Preparing** and **Preparing → Ready**. Shipping transitions remain individual so carrier and tracking information can be attached safely.

Fulfillment operators can print per-order packing slips, print the ready local-pickup sheet, and export ready shipping orders as CSV. Customer-controlled CSV fields are neutralized against spreadsheet formula execution.


## Accessibility
The launch-critical storefront flow includes skip navigation, visible keyboard focus, labeled form controls, assistive live regions for the pack builder, 44px interactive targets, and reduced-motion support. Keep these semantics intact when changing templates or shared CSS; Section 50 CI checks the critical regressions.


## Frontend performance
The application explicitly marks session-backed and mutating routes as `private, no-store`. Read-only catalog/content routes such as flavor, story, FAQ, policy and sitemap responses use a short public cache window.

Shared CSS and JavaScript URLs are versioned from the deployed file modification time so browsers can safely retain cached assets across requests while receiving a new URL after a deployment.

At the web-server or CDN layer, enable gzip/Brotli compression for HTML/CSS/JS/JSON and set static image caching independently from dynamic PHP responses. Do not override application `private, no-store` headers on cart, checkout, account, admin, payment, support or other session-backed routes.


## HTTP smoke certification
Before production release, run the real HTTP smoke suite against a migrated environment:

```bash
SMOKE_BASE_URL=https://your-store.example php scripts/http-smoke.php
```

It verifies the homepage, active flavor page, FAQ, pack builder, cart, empty-checkout redirect, support page, first-admin setup behavior, Admin redirect, health endpoint, security headers, and public/private cache boundaries. It does not submit a live Stripe payment.
