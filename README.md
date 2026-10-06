# Fudge Donuts

PHP 8.1+ ecommerce application for Fudge Donuts.

## Image upload path

Upload canonical storefront images to:

`public/images/`

Recommended names:
- `hero.png`
- `gift-box.png`
- `footer.png`
- `flavor-smores.png`
- `flavor-caramel-pretzel.png`
- `flavor-cookies-cream.png`
- `flavor-mint-chocolate.png`

## Local development

```bash
cp .env.example .env
php scripts/migrate.php
php -S 127.0.0.1:8080 -t public
```

The certified database runtime is SQLite. The current migration set intentionally targets SQLite for local and production deployment.

Administrator access is database-backed. On a fresh install, create the first Super Admin at `/setup-admin.php` after running migrations.


## Production

See `DEPLOYMENT.md`. Before launch, run:

```bash
php scripts/migrate.php
php scripts/preflight.php
php scripts/release-audit.php
```

The production web root must be `public/`.
