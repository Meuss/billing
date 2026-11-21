# Billing

Personal freelance billing tool: Clients, Projects, Tasks, Time Entries,
Expenses, and branded CHF Invoices as PDF. Single user with required 2FA.

- Production: https://billing.thomasmiller.ch (Infomaniak shared hosting)
- Local: https://billing.test (Flock; PHP 8.5 from `.flockrc`, Node 26 from `.nvmrc`)
- Stack: Laravel 13, Filament 5, MySQL/MariaDB, dompdf ([ADR 0001](docs/adr/0001-dompdf-for-invoice-pdfs.md))
- Vocabulary: [GLOSSARY.md](GLOSSARY.md) · Design: [spec](docs/superpowers/specs/2026-11-21-billing-design.md)

## Local development

```bash
composer install && npm ci && npm run build
cp .env.example .env && php artisan key:generate
php artisan migrate
php artisan test
```

## Deployment

Every push to `master` runs `.github/workflows/deploy.yml` (or run it by hand: Actions → "Test & deploy" → Run workflow):

1. **Test:** PHP 8.5 + MySQL 8 service, `npm run build`, `php artisan test`.
2. **Deploy** (only if tests pass), to Infomaniak over SSH:
   `composer install --no-dev` and `npm run build` on the runner → `artisan down` → `rsync --delete`
   of the code → `migrate --force` → `optimize` → `filament:optimize` → `artisan up`.
   The server CLI PHP is `/opt/php8.5/bin/php` (plain `php` on the server may be another version).

**Never touched by a deploy** (server-owned): `.env`, `storage/` (issued invoice PDFs, receipts,
logs, font cache) and Infomaniak's `.user.ini` / `public/.infomaniak-maintenance.html`.

If a deploy fails midway the site stays in maintenance mode on purpose: fix and re-run, or SSH in
and run `/opt/php8.5/bin/php artisan up` from the site folder.
