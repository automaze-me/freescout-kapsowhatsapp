<?php

namespace Modules\KapsoWhatsApp\Http\Middleware;

use Closure;
use Modules\KapsoWhatsApp\Entities\KapsoAccount;

class KapsoSignature
{
    public function handle($request, Closure $next)
    {
        $raw     = $request->getContent();
        $payload = json_decode($raw, true);

        if (!is_array($payload)) {
            return $this->reject($request, 'invalid JSON');
        }

        $phoneNumberId = $payload['phone_number_id']
            ?? ($payload['conversation']['phone_number_id'] ?? null);

        if (!$phoneNumberId) {
            return $this->reject($request, 'missing phone_number_id');
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
    }

    protected function reject($request, $reason)
    {
        \Log::warning('[KapsoWhatsApp] Webhook rejected: '.$reason, ['ip' => $request->ip()]);

        return response('Forbidden', 403);
    }
}
