# Billing — Design

Personal freelance billing tool replacing Invoice Ninja. Single user (Thomas Miller, Bulle). Vocabulary: see `/GLOSSARY.md`. Decisions with lock-in: `/docs/adr/`.

## Domain

- **Client**: name, contact person (optional), address line 1, address line 2 (optional), postal code, city, country (default CH), email, phone (optional), website (optional), language (`fr` | `en`), notes (optional). Archivable.
- **Project**: belongs to one Client; name; optional Project Rate; notes. Archivable. No per-task rates.
- **Default Rate**: CHF 120/h, editable in settings. Project Rate overrides it.
- **Task**: belongs to a Project; name; description (optional); status `todo` | `in_progress` | `done` | `billed`.
  - First Time Entry on a `todo` Task moves it to `in_progress`. Other transitions manual (except billed).
  - Invoiced as a whole. Adding to an Invoice → `billed`. Removed from a draft / Invoice deleted / Invoice cancelled → `done`.
  - Any non-billed Task can be added to an Invoice.
  - A billed Task takes no new Time Entries. While its Invoice is a draft its existing Time Entries stay editable (line totals follow live). Once sent: fully locked.
- **Time Entry**: belongs to a Task; start (date+time), end (date+time), duration, description (required).
  - Enter start + duration → end computed; enter start + end → duration computed.
  - Duration rounded **up** to 15-minute steps on save (minimum 15). End is then start + rounded duration.
  - Manual entry only (no timer).
- **Expense**: belongs to a Client, optionally one of its Projects; date, supplier, description, cost (CHF), billed amount (CHF, defaults to cost), original-amount note (optional free text, e.g. "USD 99.00"), receipt file (optional, private). Status `unbilled` | `billed`; same release rules as Tasks. "Duplicate" action. Only costs that are re-billed.

## Invoices

- Status `draft` → `sent` → `paid`; `sent` → `cancelled`. **Overdue** is derived: sent, unpaid, today > Due Date. Never stored.
- Only drafts are editable. A draft can be deleted (releases its Tasks/Expenses).
- Lines: **Task Line** (one per Task, rate copied when added: Project Rate else Default Rate; hours = sum of Time Entries), **Expense Line** (billed amount copied when added), **Item Line** (free description, quantity, unit price; may be negative). Tasks from any of the Client's Projects; never mixes Clients.
- One optional **Discount**: percentage or fixed CHF amount, on the subtotal.
- **Rounding**: line amounts exact; final total rounded to nearest CHF 0.05, difference shown as an "Arrondi"/"Rounding" row.
- Optional **Invoice Note** printed above the lines. No VAT. Currency CHF only.
- **Mark as sent**: assigns Invoice Number `YYYY-NNN` (sequence restarts each year; first 2026 invoice is `2026-024`, continuing Invoice Ninja's 0001–0023), asks Sent Date (default today, editable, not earlier than the latest Sent Date of any other invoice), Due Date = Sent Date + 30 days, renders and stores the PDF permanently. User sends it manually.
- **Mark as paid**: one action with payment date. No partial payments.
- **Cancel** (sent/overdue only): number kept forever, PDF kept, Tasks → `done`, Expenses → `unbilled`.
- Client or Project with Invoices (via lines or client) cannot be deleted, only archived.

## PDF

- White page, `logo-color` from the portfolio brand kit, Schibsted Grotesk text, DM Mono uppercase grey labels/figures, 1px hairlines, orange (#ff6a13) only in the logo and one rule under the total. No colour fills.
- Language = Client language (FR/EN).
- Order: Sender block + Client address, invoice meta (number, sent date, due date), Invoice Note, Task Lines grouped under Project name with Time Entries (date, duration, description) beneath each in smaller text, "Frais / Expenses" section, Item Lines, subtotal, discount, rounding, total. Footer: Sender footer text + IBAN.
- Draft PDFs (preview) carry a "DRAFT"/"BROUILLON" watermark. Sent PDFs are stored and always served from storage.
- Sender settings page: name, address line 1, postal code, city, country, email, phone, website, IBAN, footer text.

## Dashboard

1. Unbilled work per Client: done-but-unbilled Task hours + amount, unbilled Expenses amount, total CHF.
2. Recent Time Entries (latest 10) and Tasks in progress.
3. Open (sent) and overdue Invoices with totals.
4. Revenue paid this calendar year.

## Tech

- Laravel 13, Filament 5, PHP 8.4, Pest, MySQL 8 (local via Flock at 127.0.0.1, root, no password; Infomaniak in production).
- Admin UI in English. Dark only, portfolio tokens: paper #121212, surface #1b1b1a, surface-2 #242423, line #333331, ink #f3f2ee, muted #a3a39e, faint #82817a, accent #ff6a13, accent-hover #ff8a47, text on accent #121212. Radii 2/4/8px. Fonts Schibsted Grotesk + DM Mono. Orange only for primary action and focus.
- Auth: one user, no registration, created by artisan command; TOTP 2FA (authenticator app) with recovery codes, required.
- Shared hosting: PDFs via dompdf (ADR 0001), no queue workers (sync), assets built in CI. Deployment via GitHub Actions is a later step, out of scope here.
- Import: one-off command importing Clients and Projects only from the Invoice Ninja `backup.json`.
  - Skip deleted clients/projects. Archived → archived.
  - Language: IN ids 4, 40 → `fr`; 1 → `en`; null → `fr`.
  - All clients CHF regardless of IN currency.
  - Email/phone from primary contact; contact person = primary contact first + last name.
  - Project Rate set only when IN `task_rate` is non-zero and differs from 120.
- Export folder `2026-11-21_Thomas_Miller_*` stays in the repo dir, gitignored, deleted after import.
- Later (out of scope): QR-bill, emailing invoices, deployment.
