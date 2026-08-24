<div align="center">

<picture>
  <source media="(prefers-color-scheme: dark)" srcset="resources/images/brand/duely-logo-dark.svg">
  <img src="resources/images/brand/duely-logo.svg" alt="Duely — Polite today. Firm later." width="300">
</picture>

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
  (the `Keel\` PSR-4 namespace is the underlying framework, not Duely's own code; application code lives under `Keel\App\`, framework code under `Keel\Core\`)
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

- PHP 8.2+ with `pdo_mysql`, `openssl`, `mbstring`, `curl`
  (no `ext-imap` — Duely speaks IMAP over a stream socket, so it still runs where the extension has been removed)
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
APP_NAME="Duely"
APP_URL=http://duely.local
APP_ENV=local

DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=duely
DB_USERNAME=root
DB_PASSWORD=

# 32-byte key, base64 encoded. Generate with:
#   php -r "echo base64_encode(random_bytes(32)).PHP_EOL;"
APP_ENCRYPTION_KEY=

STRIPE_SECRET_KEY=
STRIPE_WEBHOOK_SECRET=
STRIPE_PRICE_SOLO_MONTHLY=
STRIPE_PRICE_STUDIO_MONTHLY=
STRIPE_PRICE_FOUNDING_MONTHLY=

ANTHROPIC_API_KEY=
```

Then run the migrations:

```bash
php database/migrate.php
```

`--pretend` prints the pending migrations without applying them.

There is no seed step. The default three-step sequence is written from
`database/seeds/default_sequence.php` the first time a workspace is created, so a
new account already has its ladder. `database/seed-activity.php` exists but only
fills the audit log with demo rows for screenshots — it is not part of setup.

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
| `ProcessDueChasesJob` | every 60s (`--interval=` to change) | Sends reminders that have come due |
| `PollInboxesJob` | every 300s | Reads replies and bounces, pauses chases |
| `SendOrganizationInviteJob` | on demand | Queued through `Keel\Core\Queue`, drained on every loop |

The first two run on their own timers inside the worker loop. The third is not on
a timer — it is pushed onto Keel's `jobs` table when an invite is sent, and the
worker drains that table each pass.

Useful flags:

```bash
php bin/worker.php --once          # one pass, then exit (what the cron entry above uses)
php bin/worker.php --chases-only   # skip the Keel job queue
php bin/worker.php --no-poll       # skip inbox polling
php bin/worker.php --tenant=7      # restrict the cadence tick to one workspace
```

Composer wraps the two common ones:

```bash
composer worker        # php bin/worker.php
composer worker:once   # php bin/worker.php --once
```

---

## Project structure

```
src/                  PSR-4 root, namespace Keel\
  Core/               Framework: Router, Database, Auth, Csrf, Queue, Mailer, Env, Vite
  App/
    Controllers/      Route handlers
    Models/           Tenant-scoped data access
    Services/         ChaseScheduler, ImapPoller, MailAccountService, Crypto, PlanService
    Mail/             MailTransport interface + SmtpTransport
    Jobs/             ProcessDueChasesJob, PollInboxesJob, SendOrganizationInviteJob
    Middleware/       Auth, CSRF, tenancy, throttling
views/                Page templates — rendered by controllers, never served directly
routes/web.php        Every URL in the application
database/
  migrate.php         The migration runner
  migrations/         Schema, applied in filename order
  seeds/              default_sequence.php, read when a workspace is created
bin/worker.php        The background worker
scripts/              setup_test_db.php and other one-off maintenance
resources/            Tailwind source, JS modules, brand SVGs
storage/              Logs and generated files
tests/
  Feature/            HTTP-level tests through the real router
  Unit/
  Support/            Fake SMTP and IMAP servers, recording transports
public_html/          Document root
```

`public_html/` holds only `index.php` (the front controller), `router.php` (for
the PHP built-in server), the built Vite assets under `assets/`, and static files
such as favicons and uploads. **No page templates live under the document root** —
every URL is matched in `routes/web.php` and rendered from `views/`.

That distinction matters when adding an endpoint. Keel's rewrite rule serves any
real file directly and skips the router, so a `.php` file dropped into
`public_html/` bypasses auth, CSRF, and tenant scoping entirely. Add a route.

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

The suite runs against a **separate database**, never your development one.
`.env.testing` holds its credentials, and `DB_DATABASE_TEST` must contain `test`
or `ci` — `scripts/setup_test_db.php` refuses to run otherwise, which is what
stops a stray test run from truncating real data.

`.env.testing` is committed with working defaults, so a fresh checkout needs no
extra file — change `DB_USERNAME` / `DB_PASSWORD` only if yours differ from the
local root defaults.

```bash
composer test:db        # create the test database and bring it up to date
composer test:all       # the whole suite
composer test:feature   # HTTP-level tests only
```

`test:db` is idempotent and runs automatically as part of `test:feature` and
`test:all`, so day to day you only need one of the last two. To run a single
file or filter, call PHPUnit directly once the database exists:

```bash
vendor/bin/phpunit --filter CadenceEngineFeatureTest
```

The feature tests cover the cases that matter: an invoice already overdue at
import, a reply landing between scheduling and send, a worker killed mid-send,
and a duplicate poll cycle. `tests/Support/` contains fake SMTP and IMAP servers
so those paths are exercised against real protocol traffic rather than mocks.

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