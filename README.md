<div align="center">

# Duely

**Get paid without writing the awkward follow-up.**

Polite, escalating invoice reminders that go out *as you*, from your own inbox.
For freelancers and small studios who track invoices in a spreadsheet, not an accounting suite.

</div>

---

## What it does

You add an invoice and a due date. Duely watches the clock.

| Day | Tone | What goes out |
|-----|------|---------------|
| +3 | Polite | A light nudge. "Just floating this back to the top of your inbox." |
| +14 | Firm | Direct, factual, still friendly. |
| +30 | Final | Firm and unambiguous. Never hostile. |

Every message is sent through **your** mail server, from **your** address, threaded into a single conversation so it reads like you wrote it. Duely watches your inbox for a reply and stops the sequence the moment your client responds — or the moment you mark the invoice paid.

No accounting suite. No client portal. No "sent via" footer.

---

## Why it exists

Chasing money is the worst part of freelancing. Not because it's hard, but because it's uncomfortable. Most people delay the follow-up for weeks, then write something stiff and over-apologetic.

Duely removes the discomfort by removing the decision. The reminders are already written, already scheduled, and already in your voice.

---

## Stack

- **PHP 8.2** on [Keel](https://get-keel.dev) — custom MVC SaaS framework
- **MySQL 8** with PDO, prepared statements only
- **Tailwind CSS** + Vite
- **Vanilla JavaScript** (ES6+), no jQuery, no framework
- **PHPMailer** over user-supplied SMTP
- **IMAP** for reply and bounce detection
- **Stripe** for subscriptions
- **Claude API** for optional tone assist

Inherited from Keel: OTP auth, CSRF, multi-tenancy, background job queue, audit log, API tokens, PWA, light/dark theme, self-maintaining sitemap and robots.

---

## Requirements

- PHP 8.2+ with `pdo_mysql`, `imap`, `openssl`, `mbstring`, `curl`
- MySQL 8.0+
- Composer 2
- Node 20+ (for Vite)
- A cron entry or systemd timer for the worker

---

## Setup

```bash
git clone git@github.com:srivera145/duely.git
cd duely

composer install
npm install && npm run build

cp .env.example .env
```

Fill in `.env`:

```ini
APP_URL=http://duely.local
APP_ENV=local

DB_HOST=127.0.0.1
DB_NAME=duely
DB_USER=root
DB_PASS=

# 32-byte key, base64 encoded. Generate with:
#   php -r "echo base64_encode(random_bytes(32)).PHP_EOL;"
APP_ENCRYPTION_KEY=

STRIPE_SECRET=
STRIPE_WEBHOOK_SECRET=
STRIPE_PRICE_SOLO=
STRIPE_PRICE_STUDIO=

ANTHROPIC_API_KEY=
```

Then:

```bash
php bin/migrate.php
php bin/seed.php
```

Point your vhost document root at `public_html/` and add `duely.local` to your hosts file. On Windows, [Helm](https://github.com/srivera145/helm) handles the Apache/MariaDB/PHP side.

---

## The worker

Duely does nothing useful without the background worker running. It sends due reminders and polls inboxes for replies.

```bash
php bin/worker.php
```

In production, run it under systemd or as a cron entry every minute:

```
* * * * * cd /var/www/duely && php bin/worker.php >> storage/logs/worker.log 2>&1
```

Jobs:

| Job | Frequency | Purpose |
|-----|-----------|---------|
| `ProcessDueChasesJob` | every minute | Sends reminders that have come due |
| `PollInboxesJob` | every 5 minutes | Reads replies and bounces, pauses chases |
| `RefreshMetricsJob` | hourly | Rolls up dashboard counters |

---

## Project structure

```
app/
  Controllers/      Route handlers
  Models/           Tenant-scoped data access
  Services/         ChaseScheduler, ImapPoller, MailAccountService, Crypto
  Mail/             MailTransport interface + SmtpTransport
  Jobs/             Queue workers
bin/                CLI entry points (migrate, seed, worker)
database/
  migrations/
  seeds/
public_html/        Document root — all publicly served files
  api/              JSON endpoints
  dashboard/
  invoices/
  clients/
  sequences/
  settings/
resources/          Tailwind source, JS modules
storage/            Logs, cache, temp
```

---

## Email credentials

Duely never sends from its own servers. Each user connects a mailbox with standard SMTP and IMAP credentials, which works with Gmail, Outlook, Fastmail, Zoho, iCloud, and any custom-domain mailbox.

**How credentials are handled:**

- Encrypted at rest with AES-256-GCM. The key lives in `.env`, never in the database.
- Decrypted in memory only at send or poll time.
- Never logged, never returned to the client, never rendered into HTML.
- Connection is tested live — a real SMTP handshake and a real IMAP login — before anything is saved.

**How the mailbox is treated:**

- Read-only. Duely never marks messages read, never deletes, never moves anything.
- Only message headers and a short snippet are stored, never full bodies.
- Gmail and Outlook require an app password rather than the account password. The UI walks users through it when auth fails.

---

## Sending safeguards

Consumer mail providers throttle or lock accounts that send in bursts, so the scheduler is deliberately conservative:

- Max 30 sends per hour, 200 per day, per connected account
- 20–90 seconds of jitter between sends
- Sends only inside the configured window (default 9:00–16:00, weekends skipped), in the **client's** timezone
- Hard stops checked immediately before every send: invoice paid or void, chase paused or stopped, client suppressed, account needing reauth
- Row-locked claims and transactional status transitions, so a crash mid-send can never double-send

An invoice imported already 18 days overdue enters at the correct rung of the ladder. It does not fire every missed step at once.

---

## Reply detection

The worst possible failure is sending a Final Notice to someone who already replied. Matching runs in strict priority order:

1. `In-Reply-To` / `References` against a stored `Message-ID`
2. Provider thread id
3. Sender address against a client with an active chase, within a 60-day window

Never on subject line alone.

Out-of-office and other auto-replies are detected and **do not** pause the sequence. Hard bounces stop the chase and flag the address.

---

## Testing

```bash
composer test           # unit + integration
composer test:cadence   # scheduler edge cases
composer lint
```

The cadence suite covers the cases that matter: an invoice already overdue at import, a reply landing between scheduling and send, a worker killed mid-send, and a duplicate poll cycle.

---

## Roadmap

- [ ] Gmail and Microsoft Graph OAuth (drops in behind `MailTransport`, no scheduler changes)
- [ ] Stripe Invoicing sync
- [ ] QuickBooks and FreshBooks import
- [ ] Payment link embedded in reminders
- [ ] Team seats and shared client ownership
- [ ] Recovered-revenue reporting

---

## Related

- [Keel](https://get-keel.dev) — the PHP SaaS framework Duely is built on
- [Helm](https://github.com/srivera145/helm) — local Windows dev environment

---

## License

Proprietary. © 2026 EchoDial LLC. All rights reserved.