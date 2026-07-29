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
   a webhook secret you choose, and the mailbox that WhatsApp conversations should land in.
4. Copy the webhook URL shown on that page.
5. Register the webhook with Kapso, using the same secret:

   ```bash
   curl -X POST "https://api.kapso.ai/platform/v1/whatsapp/phone_numbers/<PHONE_NUMBER_ID>/webhooks" \
     -H "X-API-Key: $KAPSO_API_KEY" -H "Content-Type: application/json" \
     -d '{"whatsapp_webhook":{
           "url":"https://your-freescout/kapso-whatsapp/webhook",
           "kind":"kapso",
           "secret_key":"<THE SAME SECRET>",
           "payload_version":"v2",
           "active":true,
           "events":["whatsapp.message.received","whatsapp.message.sent","whatsapp.message.failed"]
         }}'
   ```

   Message events are delivered **only** to phone-number webhooks, not project webhooks.

6. Send yourself a WhatsApp message and check the mailbox.

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
