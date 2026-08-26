# Launch checklist

Things that must be true before Duely is live, and that no test can check
because they live outside this repository.

Each blocking item names what has to happen, who can do it, and what goes wrong
if it is skipped. Tick nothing you have not actually done.

---

## Blocking — Stripe

### Tell Stripe that Duely now moves money

**Status:** not done
**Owner:** Santos
**Blocks:** enabling Stripe Connect for any real workspace

During platform onboarding, Duely told Stripe it

> "does not collect, process, or hold payments on behalf of our customers or
> their clients."

That was accurate when it was written. Stripe Connect Standard makes it wrong:
Duely now creates payment links on connected accounts and receives webhooks
telling it that a client paid. Funds still never enter a Duely-controlled
balance and Duely is never the merchant of record — but the earlier statement
said something stronger than that, and it is now false as written.

That statement lives in Stripe's dashboard, not in this repository. **No code
change fixes it.** It has to be corrected with Stripe directly, before Connect
is switched on for anyone other than a test account.

What to say, in substance:

- Duely uses **Connect Standard only**. Never Express, never Custom.
- Connected users are the merchant of record. They own their own KYC, disputes,
  refunds and payouts.
- Funds settle directly into the user's own Stripe account. Nothing routes
  through a platform balance.
- Duely sets **no `application_fee_amount`** and takes no cut.
- Duely's own subscription billing is separate and unchanged.

Why this is blocking rather than a nice-to-have: a platform that begins moving
money after describing itself as pure SaaS is one of the more reliable ways to
trigger a Stripe account review. A review that arrives unannounced can freeze
payouts — including, in the worst case, payouts belonging to users who did
nothing wrong. Telling Stripe first turns a red flag into a routine
notification.

### Create the Connect webhook endpoint and its own signing secret

**Status:** not done
**Owner:** Santos
**Blocks:** payments being marked paid automatically

The Connect webhook is a **separate** endpoint from the subscription webhook,
with a **separate** signing secret. Subscribe it to the Connect events only, and
put its secret in `STRIPE_CONNECT_WEBHOOK_SECRET` — not the subscription one.

Sharing a secret between the two means either endpoint can be replayed against
the other.

In Stripe: Developers → Webhooks → **Add endpoint** → *Connect* (not *Account*).

- URL: `https://yourdomain/webhooks/stripe-connect`
- Events: `payment_intent.succeeded`, `checkout.session.completed`,
  `checkout.session.async_payment_succeeded`, `charge.refunded`,
  `account.updated`, `account.application.deauthorized`
- Copy the signing secret into `STRIPE_CONNECT_WEBHOOK_SECRET`

### Fill in the Connect client id

**Status:** not done
**Blocks:** the Connect button doing anything

`STRIPE_CONNECT_CLIENT_ID` comes from Settings → Connect → Platform settings.
Set the OAuth redirect URI there to `https://yourdomain/settings/payments/callback`
— Stripe rejects a callback to any URI not on that list.

Leave the client id empty and the whole feature stays off: `/settings/payments`
says payments are not available, no link is ever generated, and nothing else
about Duely changes.

---

## Blocking — general

### Fill in the remaining Stripe price IDs

**Status:** not done

`STRIPE_PRICE_SOLO_MONTHLY` and `STRIPE_PRICE_STUDIO_MONTHLY` are still empty.
Until they are set, a non-founding buyer is redirected to
`/billing/upgrade?error=price_not_configured` rather than being charged. Safe,
but it looks broken.

### Have a lawyer read the terms and the privacy page

**Status:** not done

Both are written to be read rather than to be defensible, which is the right
instinct and not a substitute for review — particularly the merchant-of-record
and liability clauses now that money is involved.

---

## Environment

These are configuration rather than obligations, but a fresh deployment fails
without them.

- `curl.cainfo` set in `php.ini`, or every Anthropic call fails TLS verification.
  Not the default on a fresh Helm install.
- `mod_rewrite` enabled, or every URL except `/` returns 404. Also not the
  default on a fresh Helm install.
- The worker running (`php bin/worker.php`), or no reminder is ever sent.
- `MAIL_ENCRYPTION` matching `MAIL_PORT` — 465 needs `ssl`, 587 needs `tls`.
  Mismatching them hangs the sign-in request until PHP's execution limit kills it.
- `STRIPE_CONNECT_CLIENT_ID` and `STRIPE_CONNECT_WEBHOOK_SECRET`, if payments
  are being switched on. Both empty is a valid, fully working configuration.
