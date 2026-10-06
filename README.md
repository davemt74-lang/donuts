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

The default database is SQLite. Set `DB_DSN`, `DB_USER`, and `DB_PASS` to use MySQL in production.

Admin product management is protected by `ADMIN_PASSWORD`.
