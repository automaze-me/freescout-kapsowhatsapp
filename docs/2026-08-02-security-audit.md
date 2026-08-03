# Security audit — KapsoWhatsApp

**Date:** 2026-08-02
**Scope:** `Modules/KapsoWhatsApp` (the module only; upstream FreeScout core is out of scope)
**Reviewed at:** commit `55a271c`, module version `0.2.1`
**Method:** Manual source review of the full module (~7,300 LOC of PHP plus the Blade views and the picker JavaScript), tracing every value from an untrusted source (inbound webhook payload, Kapso API response, agent form input) to its sink. No dynamic testing was run against the live instance.

## Verdict

**No exploitable vulnerabilities found.** The internet-facing and data-handling paths are well-hardened. Three low/informational hardening notes are recorded below; none is a defect that blocks release.

## Threat model

The module's untrusted inputs are:

- **The public webhook endpoint** (`POST /kapso-whatsapp/webhook`) — the only unauthenticated, internet-facing route. Authenticated per-account by HMAC.
- **Inbound WhatsApp payloads** — even once HMAC-verified, the envelope is controlled by Meta/Kapso and the message content is controlled by the customer. Reaches queued jobs, the customer/conversation model, and rendered thread HTML.
- **Agent form input** — the settings forms (admin-gated) and the template picker (per-conversation, any agent who can reply).

## Surfaces verified clean

### Webhook authentication (`Http/Middleware/KapsoSignature.php`)

HMAC-SHA256 over the raw request body, compared with `hash_equals` (timing-safe). Empty signatures are rejected; the account must be active and have a decryptable secret. The `phone_number_id` is scalar-checked, length-capped (≤64), and stripped of control characters before it is used in a query or written to a log. The middleware never throws uncaught (it would otherwise leak a stack trace under `APP_DEBUG` and count toward Kapso's webhook auto-pause), returning a uniform `403` on rejection and `503` on internal error.

### Webhook controller (`Http/Controllers/WebhookController.php`)

Deliberately never-throw: verify (in middleware) → dedupe → dispatch. A queue/infrastructure failure returns `503` so Kapso retries; every other outcome is a uniform `200` with no debug oracle. Idempotency-key caching plus inbound-only `wamid` dedupe are correct — `sent`/`failed` events are never deduped on `wamid` (which would swallow a delivery failure).

### SSRF on media download (`Services/KapsoClient.php::downloadMedia`)

The media URL comes from the (HMAC-authenticated) payload, and the fetch carries the install's API key. Before any request, the URL must be `https` to a public, non-reserved host — enforced via core's `Helper::checkUrlIpAndHost()`, which rejects loopback, private, link-local, cloud-metadata addresses and the app's own host. Guarded with `method_exists()` for older core. Outbound links are always built from `APP_URL` and never pass through this method.

### Injection & output encoding

- Inbound message text → thread body via `nl2br(e($raw, true))` (`Jobs/ProcessInboundMessage.php::body`). No stored XSS from message content.
- Customer-supplied **reaction emoji** rendered `e()`-escaped (`Providers/KapsoWhatsAppServiceProvider.php:139`).
- Attachment preview escapes URL, filename, and alt text (`Providers/…:74-76`).
- Delivery-failure line item escapes Kapso's error summary with `e()` (`Services/DeliveryFailureLineItem.php:39`).
- No `{!! !!}` unescaped output anywhere in the views.
- The template-picker JavaScript (`Public/js/kapsowhatsapp.js`) builds all DOM through `createElement`/`textContent`; API data never touches `innerHTML` (the only `innerHTML` writes are `= ''` clears).
- SQL is all Eloquent bindings. The single `LIKE` prefilter (`Services/CustomerResolver.php`) escapes the needle with `Helper::sqlEscapeLike()` and is gated by exact `PhoneNumber::toE164()` equality in PHP, so a crafted phone cannot broaden the match to attach to the wrong customer.
- Template placeholder substitution uses `preg_replace_callback` returning a literal string (no `$`/backreference interpretation), and the whole substituted body is `e()`-escaped before storage.

### Authorization / IDOR

- Every admin action in `KapsoWhatsAppController` (API key, default country code, add/edit/delete number, webhook register/refresh/resume) calls `authorizeAdmin()` (→ `isAdmin()` → `abort(403)`), directly or via the shared `runWebhookAction()` helper. Coverage is complete.
- The per-conversation template endpoints (`TemplatesController::list`/`send`) enforce `$user->can('view', $conversation)` (core `ConversationPolicy` — admins pass, others need mailbox access and, where the mailbox restricts to assigned conversations, to be the assignee/creator). The sending account is derived server-side from the conversation's own latest inbound row; the template is re-validated against a fresh Kapso list; variables are scalar/count/length-validated. No cross-mailbox send and no client-supplied account id.
- No mass assignment: `store()`/`update()` bind only whitelisted fields, and the number's identity fields (`phone_number_id`, `business_account_id`) are resolved from Kapso's own number list, not from the request.
- The public webhook route is correctly the only route outside `web`/`auth`; every wildcard route is `[0-9]+`-constrained so the webhook path cannot be shadowed, and every state-changing admin route is a CSRF-protected POST in the `web` group.

### Secrets & cryptography

- Webhook signing secret: `Str::random(48)` — Laravel's CSPRNG (~288 bits of entropy).
- API key stored `encrypt()`-at-rest in Options (`Services/Settings.php`), read via `decrypt()`; decrypt failure is logged **without** the value.
- Per-account webhook secret stored via an `encrypt()`/`decrypt()` model accessor (`Entities/KapsoAccount.php`); a key-rotation-undecryptable secret returns `null` rather than throwing.
- No secret is ever written to a log line, exception message, flash, or HTTP response. The API client sanitizes Kapso's error text (strip tags + control chars, length-capped) and never includes the key itself.

### Media file handling (`Jobs/ProcessInboundMessage.php::attachMedia`)

The attacker-influenced `filename`/`content_type` are forwarded to core's `Attachment::create()`, which sanitizes restricted extensions (`Helper::sanitizeUploadedFileName()`), strips `/` from the name, and generates a hashed on-disk path — so no path traversal and no executable drop. `type` is passed `null` so core detects it from the mime type. The filename is `e()`-escaped everywhere it is rendered.

### Denial-of-service surface

`PhoneNumber::toE164()` uses only linear `\D+`/`\d+` regexes with an 8–15 digit output cap — no catastrophic backtracking (ReDoS). Phone parsing does not read config itself; the country code is passed in explicitly.

### Release CI (`.github/workflows/release.yml`)

Triggers on `v*` tags. Minimal `contents: write` permission; the ephemeral `github.token` (no PAT). Tag names are passed to `gh` as shell-expanded environment variables (`"${GITHUB_REF_NAME}"`), **not** interpolated into the script via `${{ }}` — so no tag-name command injection. `git archive` honors `.gitattributes` `export-ignore`, so no tests, CI, or dev files (and no secrets) ship in the published zip.

## Hardening notes (not vulnerabilities)

| # | Severity | Location | Note |
|---|----------|----------|------|
| 1 | Low | `Services/KapsoClient.php::downloadMedia` | No size cap on the media download: the full response body is read into worker memory with no `Content-Length`/stream limit. Reaching it requires a valid HMAC signature (the CSPRNG secret) and a URL that passes the SSRF guard, and WhatsApp media is inherently size-bounded — so this is defense-in-depth only. To close it, cap the download (streamed max-bytes guard) so a hostile-but-authenticated payload cannot OOM the queue worker. |
| 2 | Low | `Http/routes.php` / `KapsoWhatsAppController` | Admin gating is a per-method convention (`authorizeAdmin()` inside each action), not route-group middleware. Coverage is complete today, but a future action that forgets the call would ship unguarded. A `roles`/`admin` middleware on the group would make that structurally impossible. |
| 3 | Info | `TemplatesController::resolveConversation` | Existence oracle: eligibility is checked before authorization, so a `404` vs `403` distinguishes "a WhatsApp conversation with this id exists" to a caller without mailbox access. Documented in code, mirrors core's own `findOrFail`-then-authorize behavior, and leaks nothing core does not. Accepted. |

## Strong patterns observed

- HMAC verification with a constant-time comparison and a CSPRNG secret.
- An explicit SSRF allow/deny on the one server-side fetch of an externally supplied URL.
- A never-throw webhook that avoids both a debug oracle and Kapso's auto-pause budget.
- Escaping at every rendering sink and no unescaped Blade output.
- Encrypted-at-rest secrets with leak-free failure handling.
- A CI pipeline with least-privilege permissions and injection-safe tag handling.

## Out of scope

- Upstream FreeScout core (only the module's own use of core APIs was assessed).
- Dynamic/authenticated penetration testing against the running instance.
- The default admin credentials on the deployment (an operational concern, not a module issue).
