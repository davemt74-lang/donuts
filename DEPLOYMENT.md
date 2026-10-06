# Production deployment

## Requirements
- PHP 8.1+
- PDO + the selected database driver
- cURL
- mbstring
- JSON
- HTTPS
- writable `storage/`

## Deploy
1. Copy the repository to the server with the web root pointed at `public/`.
2. Copy `.env.example` to `.env` and set production values.
3. Set `APP_ENV=production` and an HTTPS `APP_URL`.
4. Configure a long random `APP_KEY` and `ADMIN_PASSWORD`.
5. Configure Stripe secret and webhook signing keys.
6. Run `php scripts/migrate.php`.
7. Run `php scripts/preflight.php`; every check must pass.
8. Configure Stripe's webhook endpoint as `/stripe-webhook.php`.
9. Schedule `php scripts/send-notifications.php` every few minutes.
10. Upload storefront images into `public/images/`.
11. Verify `/health.php` returns HTTP 200.

## Canonical image paths
- `public/images/hero.png`
- `public/images/gift-box.png`
- `public/images/footer.png`
- `public/images/flavor-smores.png`
- `public/images/flavor-caramel-pretzel.png`
- `public/images/flavor-cookies-cream.png`
- `public/images/flavor-mint-chocolate.png`

Never expose `.env`, database files, Stripe secrets, or writable storage through the web root.
