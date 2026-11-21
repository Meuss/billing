# Deployment notes (shared hosting)

Short checklist for the first deploy and for later ones.

## Environment
- `APP_ENV=production`, `APP_DEBUG=false`.
- `APP_KEY` must never rotate: MFA secrets and recovery codes are stored encrypted with it. Back it up with the `.env`.
- `APP_TIMEZONE=Europe/Zurich` and `QUEUE_CONNECTION=sync` (both are in `.env.example`; there is no queue worker).
- If HTTPS terminates upstream (reverse proxy, hosting panel), configure trusted proxies so URLs and signed links are generated as `https`.

## Database
- MariaDB >= 10.2 or MySQL >= 5.7: the schema uses STORED generated columns with unique indexes.
- Run `php artisan migrate --force` on every deploy.

## Build (CI)
`public/build` and the Filament assets are gitignored, so CI must produce them before shipping. Use the versions pinned in the repo: PHP from `.flockrc` (`php=8.5`) and Node from `.nvmrc` (e.g. `actions/setup-node` with `node-version-file: .nvmrc`). The production PHP on Infomaniak must be 8.5 too.

```
composer install --no-dev --optimize-autoloader
npm ci && npm run build
php artisan filament:assets
```

## First run
- `php artisan billing:create-user` once, to create the single user (MFA is set up on first login).

## Storage
- `storage/` must persist across deploys and be backed up: issued invoice PDFs are permanent records and expense receipts live there too.
- `storage/` (including `storage/fonts`, `storage/app/dompdf-tmp`, `storage/framework`, `storage/logs`) and `bootstrap/cache` must be writable by the web user.
- The local disk has `serve => true`, which exposes signed temporary URLs for receipt previews. Those URLs work without login until they expire.
