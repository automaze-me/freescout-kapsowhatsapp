# KapsoWhatsApp

WhatsApp as a communication channel for [FreeScout](https://freescout.net), via the
[Kapso](https://kapso.ai) API.

Stage 1 (this release) handles **inbound** messages: WhatsApp messages become FreeScout
conversations and threads, with media, reactions and customer identity matching. Replies are not
yet sent from FreeScout.

## Requirements

- FreeScout 1.8.128+
- A Kapso project API key and a WhatsApp number connected in Kapso

## Installation

1. Extract into `Modules/KapsoWhatsApp/`.
2. Activate the module in **Manage → Modules**.
3. Go to **Manage → WhatsApp Accounts** and add an account: name, Phone Number ID, Kapso API key,
   and the mailbox that WhatsApp conversations should land in.
4. Click **Register with Kapso** on that account's row. The module generates the webhook secret,
   registers the webhook against your phone number, and subscribes to exactly the events it needs.
   You never copy a secret or a URL by hand, and FreeScout and Kapso cannot end up holding
   different secrets.
5. Send yourself a WhatsApp message and check the mailbox.

FreeScout must be reachable from the public internet for Kapso to deliver anything. If it is not,
the accounts page says so.

The **Webhook** column shows what Kapso currently thinks. Kapso pauses a webhook automatically
after a run of failed deliveries and never resumes it on its own, so a paused webhook shows up
here as *Paused by Kapso*, together with the HTTP status your install returned, and a **Re-enable**
button. Registering again is always safe: it adopts the existing webhook rather than creating a
second one, and re-issues the secret.

Webhooks belonging to anything else on the same number — an n8n bridge, another helpdesk — are
never read, changed or deleted by this module.

## Configuration

- **Default country code.** Phone numbers typed locally into FreeScout in national format (a
  single leading trunk zero, e.g. a German number written `0151 12345678`) need a country code to
  normalise to E.164. Nothing here is specific to one deployment, so this is unset (no code
  assumed) by default — WhatsApp itself always delivers `message.from`/`message.to` as bare
  international digits, so this only matters for numbers agents type locally. If your install has
  customers with locally-typed national-format numbers, set it once via `php artisan tinker`:

  ```php
  \Option::set('kapsowhatsapp.default_country_code', '49'); // bare digits, no "+"
  ```

## Outbound event reconciliation

Kapso also delivers `whatsapp.message.sent` and `whatsapp.message.failed` to the webhook. These
exist so FreeScout's copy of the conversation stays complete even while something *other* than
FreeScout is sending WhatsApp messages for the same number — during the Stage 1 parallel run
that is the n8n bridge, or an agent using Kapso's own inbox directly. (Stage 2 adds native
sending from FreeScout itself; see "Stage 1 done" below.)

- **A send made outside FreeScout** appears as a normal-looking outbound thread on the matching
  conversation, marked *"Sent outside FreeScout"* so it's clear the reply did not originate here.
  A send FreeScout itself already knows about (once Stage 2 lands) is recognised by its message
  id and not duplicated.
- **A delivery failure** — the customer-service window has closed, the number is invalid, etc. —
  is posted as a line item on the conversation with Kapso's error code and message, so a failed
  send is never silently invisible. This is posted whether or not the corresponding `sent` event
  has been processed yet: Kapso does not guarantee event ordering, so a failure can arrive before
  its sibling send, or the send event may never arrive at all for some error classes. Once a
  message is marked failed this way, a `sent` event for the same message id can never overwrite
  that outcome.
- The conversation a foreign send or failure attaches to is found by matching the WhatsApp
  number against this module's own message history for the account, without regard to whether
  that conversation is currently open or closed.

## Channel code

This module registers communication channel **102**. Codes 100 and 101 are used by the
`MetaWhatsApp` community module, so the two can coexist.

## Testing

The suite runs inside a FreeScout development installation. One-time setup, from the FreeScout
root:

1. Create the database for the `testing` connection (see `config/database.php`) and set
   `DB_TEST_HOST` / `DB_TEST_DATABASE` / `DB_TEST_USERNAME` / `DB_TEST_PASSWORD` in `.env`.
2. Migrate it and activate the module in it:

   ```bash
   DB_CONNECTION=testing php artisan migrate --force
   # use whichever client your server has: `mysql` or, on MariaDB 11.4+, `mariadb`
   mysql <test-db> -e "INSERT INTO modules (alias, active) VALUES ('kapsowhatsapp',1);"
   DB_CONNECTION=testing php artisan migrate --force
   ```

3. `php artisan config:clear` — a cached config overrides the `testing` connection, and the suite
   refuses to run rather than touch the live database.

Run it:

```bash
vendor/bin/phpunit -c Modules/KapsoWhatsApp/phpunit.xml
```

## License

MIT
