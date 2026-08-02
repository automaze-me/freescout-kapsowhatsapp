# KapsoWhatsApp

WhatsApp as a communication channel for [FreeScout](https://freescout.net), via the
[Kapso](https://kapso.ai) API.

This module handles both directions: WhatsApp messages become FreeScout conversations and
threads — with media attachments, incoming reactions (shown on the message they refer to) and
customer identity matching — and replying from FreeScout delivers the reply to WhatsApp. It
registers and monitors its own Kapso webhook so no manual setup step is needed.

## Requirements

- FreeScout 1.8.128+
- A Kapso project API key and a WhatsApp number connected in Kapso

## Installation

1. Download `KapsoWhatsApp.zip` from the
   [latest release](https://github.com/automaze-me/freescout-kapsowhatsapp/releases/latest) and
   extract it into your FreeScout `Modules/` directory — the zip already contains the
   `KapsoWhatsApp/` folder.
2. Activate the module in **Manage → Modules**.
3. Go to **Manage → WhatsApp Accounts** and paste your Kapso API key. One key covers the whole
   install: your WhatsApp numbers come from the Kapso project it belongs to.
4. Click **Add Number**, pick a number from the dropdown and choose the mailbox its conversations
   should land in. You never copy a Phone Number ID or a Business Account ID — the module reads
   both from Kapso.
5. That's it — adding a number registers its webhook with Kapso automatically: the module
   generates the webhook secret, registers the webhook against that number, and subscribes to
   exactly the events it needs. If Kapso cannot be reached right then, the new row explains why
   and the module keeps retrying on its own; nothing to click.
6. Send yourself a WhatsApp message and check the mailbox.

FreeScout must be reachable from the public internet for Kapso to deliver anything. If it is not,
the accounts page says so.

The **Webhook** column shows the registration status as last reported by Kapso's API — not a
local guess, but also only as fresh as the last check. Kapso pauses a webhook automatically
after a run of failed deliveries and never resumes it on its own, so a paused webhook shows up
here as *Paused by Kapso*, together with the HTTP status your install returned, and a **Re-enable**
button. Registering again is always safe in the sense that it adopts the existing webhook rather
than creating a second one — but it does change that webhook: it issues a new secret and rewrites
its URL to this install's current address, every time.

Webhooks belonging to anything else on the same number — an n8n bridge, another helpdesk — are
never modified, disabled or deleted by this module. It does list them (to find its own among
them), but that list is only ever read, never acted on.

Deleting an account here, or turning it inactive, does not touch its webhook in Kapso — the
webhook stays registered and active. Kapso will keep sending it deliveries, which this FreeScout
will now 403 (there is no account left to authenticate them against), and after enough failures
Kapso will auto-pause it. If you are retiring a number for good, remove its webhook from Kapso's
own dashboard.

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

## Replying from FreeScout

Replying to a WhatsApp conversation delivers the reply to the customer via Kapso: a long reply is
split into multiple WhatsApp messages automatically, and each attachment goes out as its own image
or document message. A short reply text (1024 characters or fewer) rides as the caption of the
first attached image or document instead of going out as a separate message. Once Kapso confirms
the send, the reply is marked *Sent via WhatsApp* on the reply. Replying also marks the customer's
own most recent message as read (the blue ticks).

If the send itself is rejected at request time (Kapso's API declines it, rather than accepting it
and reporting a failure later via webhook), the same red delivery-failure line item described below
appears immediately — this is not only a webhook-driven outcome.

Attachment replies hand Kapso a link built from the install's own `APP_URL`, so an install that
isn't reachable from the public internet will have every attachment reply rejected (surfaced the
same way, as the red delivery-failure line item).

## Outbound event reconciliation

Kapso delivers `whatsapp.message.sent` and `whatsapp.message.failed` to the webhook for every
message on the number. These keep FreeScout's copy of the conversation complete and correct in two
ways: recognising FreeScout's own sends (see above) without duplicating them, and recording
messages sent by something *other* than FreeScout for the same number — an n8n bridge, or an agent
using Kapso's own inbox directly.

- **A send made outside FreeScout** appears as a normal-looking outbound thread on the matching
  conversation, marked *"Sent outside FreeScout"* so it's clear the reply did not originate here.
  A send FreeScout itself already knows about — every reply sent from FreeScout — is recognised by
  its message id and marked *Sent via WhatsApp* rather than duplicated.
- **A delivery failure** — the customer-service window has closed (WhatsApp only allows free-form
  replies within 24 hours of the customer's last message), the number is invalid, etc. — is posted
  as a red line item on the conversation with Kapso's error code and message, whether the send was
  made from FreeScout or elsewhere, so a failed send is never silently invisible. This is posted
  whether or not the corresponding `sent` event has been processed yet: Kapso does not guarantee
  event ordering, so a failure can arrive before its sibling send, or the send event may never
  arrive at all for some error classes. Once a message is marked failed this way, a `sent` event
  for the same message id can never overwrite that outcome.
- The conversation a foreign send or failure attaches to is found by matching the WhatsApp
  number against this module's own message history for the account, without regard to whether
  that conversation is currently open or closed.

## The 24-hour window

WhatsApp only allows free-form replies within 24 hours of the customer's last message; outside
that window, only pre-approved templates go through. Every WhatsApp conversation shows where it
stands, above the reply area: a quiet line saying when the window closes while it's open, or a red
notice saying when it closed once it isn't. On a closed window the Reply button is removed
entirely and an already-open draft cannot be reopened back into the editor — notes are unaffected
and stay fully available, since they never leave FreeScout.

The window is tracked per WhatsApp contact, not per conversation: a customer's message on any of
their conversations reopens it everywhere, and the banner reflects that on the next page load (no
live countdown). This is advisory UI only — WhatsApp remains the actual enforcer. A page left open
across the window's expiry can still attempt to send; that attempt fails at delivery exactly as
described above, honestly, as a red delivery-failure line item.

### Sending a template on a closed window

The red closed-window notice carries a **Send a template…** button. It opens a small form listing
the Kapso project's currently approved templates (name and language), a preview of the template
text, and one input for each `{{n}}` placeholder the template needs. Only templates Kapso reports
as `APPROVED`, with a plain text body and no other parameterised part (no media header, no dynamic
button), are offered — this is the same slice of templates the send path can actually fill; the
template list is fetched fresh from Kapso every time the picker opens, never cached.

Sending fills in the placeholders and immediately shows the message in the conversation, attributed
to the agent who sent it. It is delivered to WhatsApp the same way an ordinary reply is: once Kapso
confirms the send it is marked *Sent via WhatsApp*, and a rejected or failed send surfaces as the
same red delivery-failure line item described above. Sending a template does **not** reopen the
24-hour window — only a reply from the customer does that, exactly as WhatsApp's own rules require.

Any agent who can reply to the conversation can send a template on it — this is not restricted to
admins.

## Choosing the channel per reply

A conversation with WhatsApp history and a customer email on file — a customer who started on one
channel and can be reached on the other too — shows a small **Send via: Email | WhatsApp** control
above the Send button. Agents pick a channel per reply: a customer who emailed in can get a WhatsApp
reply, and a customer who wrote in on WhatsApp can get an email reply later, in the very same
conversation. The picker only appears when both channels are genuinely usable for that conversation;
otherwise a reply behaves exactly as it always has, with no control shown at all.

The picker defaults to whichever channel the customer's own most recent message came in on — reply
on the same channel they're already using unless you deliberately switch. When the WhatsApp window
is closed, the WhatsApp option is shown disabled with a note explaining why, and the default falls
back to email; the Reply button itself only disappears entirely when *neither* channel is usable
(closed window and no email on file). A closed window does not stop you from sending a WhatsApp
message on a mixed conversation — the picker carries the same **Send a template…** control described
above for exactly this case.

Choosing WhatsApp on an email-first conversation, or email on a WhatsApp-first one, delivers exactly
the way a reply on that channel normally does — the same undo delay, the same delivery-failure
reporting, the same *Sent via WhatsApp* marker. The conversation's own channel never changes; the
choice applies to that one reply only.

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
