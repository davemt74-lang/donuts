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
