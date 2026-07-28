<?php

namespace Modules\KapsoWhatsApp\Http\Middleware;

use Closure;
use Modules\KapsoWhatsApp\Entities\KapsoAccount;

class KapsoSignature
{
    /**
     * Kapso auto-pauses a webhook (irreversibly, until a human re-enables it in
     * their dashboard) after ~15 minutes at a >=85% failure rate. This runs on
     * fully unauthenticated input, before the controller's own try/catch even
     * starts, so nothing here may be allowed to throw uncaught.
     */
    public function handle($request, Closure $next)
    {
        try {
            $raw     = $request->getContent();
            $payload = json_decode($raw, true);

            if (!is_array($payload)) {
                return $this->reject($request, 'invalid JSON');
            }

            $phoneNumberId = $payload['phone_number_id']
                ?? ($payload['conversation']['phone_number_id'] ?? null);

            if ($phoneNumberId === null) {
                return $this->reject($request, 'missing phone_number_id');
            }

            // Kapso phone number ids are short numeric strings. Reject anything
            // that isn't a scalar outright: an array/object here would otherwise
            // pass this check, reach the (string) cast inside
            // KapsoAccount::findByPhoneNumberId(), and raise an "Array to string
            // conversion" warning that Laravel promotes to an uncaught
            // ErrorException -> 500, with no signature required.
            if (!is_scalar($phoneNumberId)) {
                return $this->reject($request, 'invalid phone_number_id: expected scalar, got '.gettype($phoneNumberId));
            }

            $phoneNumberId = (string) $phoneNumberId;
            // Strip control characters before this value is ever used in a log
            // line (it is attacker-controlled and unauthenticated at this point).
            $phoneNumberId = preg_replace('/[\x00-\x1F\x7F]/', '', $phoneNumberId);

            if ($phoneNumberId === '' || strlen($phoneNumberId) > 64) {
                return $this->reject($request, 'invalid phone_number_id');
            }

            $account = KapsoAccount::findByPhoneNumberId($phoneNumberId);

            if (!$account) {
                return $this->reject($request, 'unknown or inactive account for '.$phoneNumberId);
            }

            $secret = $account->webhook_secret;

            if (!$secret) {
                return $this->reject($request, 'account '.$account->id.' has no usable webhook secret');
            }

            $signature = (string) $request->header('X-Webhook-Signature', '');
            $expected  = hash_hmac('sha256', $raw, $secret);

            if ($signature === '' || !hash_equals($expected, $signature)) {
                return $this->reject($request, 'bad signature for account '.$account->id);
            }

            $request->attributes->set('kapso_account', $account);
            $request->attributes->set('kapso_payload', $payload);

            return $next($request);
        } catch (\Throwable $e) {
            // A 503 counts toward Kapso's auto-pause budget exactly the same as
            // an uncaught 500 would -- this does not buy extra headroom. The
            // point is controlled failure: unlike an uncaught exception, this
            // does not render a stack trace / SQL / bindings into the response
            // body when APP_DEBUG is on. It also must NOT be a 200: that would
            // acknowledge a delivery we never actually verified or processed,
            // which permanently drops it once Kapso stops retrying.
            \Log::error('[KapsoWhatsApp] Webhook middleware failed: '.$e->getMessage());

            return response('Service Unavailable', 503);
        }
    }

    protected function reject($request, $reason)
    {
        \Log::warning('[KapsoWhatsApp] Webhook rejected: '.$reason, ['ip' => $request->ip()]);

        return response('Forbidden', 403);
    }
}
