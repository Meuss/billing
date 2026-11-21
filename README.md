# Billing

Personal freelance billing tool (replaces Invoice Ninja): Clients, Projects, Tasks, Time Entries,
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
php artisan billing:create-user you@example.ch "Your Name"   # once; 2FA is set up at first login
php artisan test                                              # MySQL database billing_testing
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

### GitHub secrets

Repo → Settings → Secrets and variables → Actions (or `gh secret set NAME`):

| Secret | Value |
| --- | --- |
| `INFOMANIAK_SSH_KEY` | Private key `~/.ssh/billing-infomaniak-github-deploy` (on Thomas' laptop): `gh secret set INFOMANIAK_SSH_KEY < ~/.ssh/billing-infomaniak-github-deploy` |
| `INFOMANIAK_SSH_HOST` | SSH host from Manager → FTP / SSH, e.g. `xxxx.ftp.infomaniak.com` |
| `INFOMANIAK_SSH_USER` | the FTP/SSH account name |
| `INFOMANIAK_KNOWN_HOSTS` | output of `ssh-keyscan <host>` |
| `INFOMANIAK_SITE_PATH` | absolute site folder, e.g. `/home/clients/<hash>/sites/billing.thomasmiller.ch` (the deploy refuses anything else) |

### Deploy key

`~/.ssh/billing-infomaniak-github-deploy` (ed25519, no passphrase) only lets GitHub Actions deploy
this app. Its public half goes on ONE line in the server's `~/.ssh/authorized_keys`, comment
`github-actions deploy: Meuss/billing -> billing.thomasmiller.ch (Infomaniak)`. Infomaniak refuses
RSA keys. To rotate: generate a new key, replace the line in `authorized_keys`, then
`gh secret set INFOMANIAK_SSH_KEY < ~/.ssh/<new key>`.

### First-time setup (done once)

1. **Infomaniak Manager:** site `billing.thomasmiller.ch` with its folder pointing at
   `<site path>/public`, PHP **8.5**, Let's Encrypt certificate; PHP extensions `intl`, `mbstring`,
   `gd`, `zip`, `pdo_mysql` enabled; a database + user (MariaDB ≥ 10.2 — the schema uses STORED
   generated columns with unique indexes); an SSH account.
2. **Server `.env`:** over SFTP/SSH, create `<site path>/.env` from `.env.production.example`.
   `APP_KEY` = the key from your local `.env` (the imported 2FA secret is encrypted with it).
3. **SSH:** add the deploy public key to `~/.ssh/authorized_keys` on the server; set the five
   GitHub secrets above.
4. **Deploy:** push to `master`. The first `migrate --force` creates the schema in the empty database.
5. **Data:** run `./scripts/export-data.sh` locally, then import the file it prints
   (`storage/app/private/db-export/billing-data-*.sql`) into the production database with
   phpMyAdmin. It is data only (REPLACE rows, no schema), so it fits the migrated tables, and it
   brings your user + 2FA, settings, clients, projects, tasks and time entries.
   Delete the export file afterwards (it contains client data).
6. **Check:** log in at https://billing.thomasmiller.ch with your local credentials and 2FA app.

### Good to know

- **`APP_KEY` must never change** on the server: 2FA secrets and recovery codes are encrypted with it.
- Issued invoice PDFs and receipts live only in `<site path>/storage/app/private/` on the server
  (not in git, not in the database). No extra backups are configured.
- HTTPS is terminated by Infomaniak in front of PHP; `bootstrap/app.php` trusts forwarded headers
  (`trustProxies(at: '*')`) so generated URLs stay `https`.
- No cron or queue worker is needed (`QUEUE_CONNECTION=sync`; the app has no scheduled tasks).
- The `local` disk has `serve => true`: signed temporary URLs (receipt previews) work without
  login until they expire.
- Lost 2FA device: use a recovery code. Otherwise, over SSH from the site folder:
  `/opt/php8.5/bin/php artisan tinker` →
  `App\Models\User::first()->forceFill(['app_authentication_secret' => null, 'app_authentication_recovery_codes' => null])->save();`
  and set 2FA up again at next login.
