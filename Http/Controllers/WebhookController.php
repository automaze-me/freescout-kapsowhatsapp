<?php

namespace Modules\KapsoWhatsApp\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\KapsoWhatsApp\Entities\KapsoMessage;
use Modules\KapsoWhatsApp\Jobs\ProcessInboundMessage;
use Modules\KapsoWhatsApp\Jobs\ReconcileOutboundMessage;

class WebhookController extends Controller
{
    /**
     * Kapso pauses a webhook after ~15 minutes at a >=85% failure rate and
     * does not re-enable it. Nothing in this method may throw: verify (done in
     * middleware), dedupe, dispatch, 200.
     */
    public function receive(Request $request)
    {
        $account = $request->attributes->get('kapso_account');
        $payload = $request->attributes->get('kapso_payload');
        $event   = (string) $request->header('X-Webhook-Event', '');

        try {
            $account->last_webhook_at = now();
            $account->save();

            // Retry of a delivery we already handled. Each Kapso delivery has its
            // own key, so this is safe across every event type.
            $idempotencyKey = (string) $request->header('X-Idempotency-Key', '');
            $cacheKey       = $idempotencyKey !== '' ? 'kapsowhatsapp.idem.'.md5($idempotencyKey) : null;

            if ($cacheKey !== null && \Cache::has($cacheKey)) {
                return response('OK', 200);
            }

            $wamid = $payload['message']['id'] ?? null;

            // The wamid check applies ONLY to inbound messages. A sent and a failed
            // event share one wamid, so deduping those on wamid would swallow the
            // failure after the send was recorded — and a silently dropped delivery
            // failure is the exact outcome this module exists to prevent.
            if ($event === 'whatsapp.message.received' && $wamid && KapsoMessage::seen($wamid)) {
                if ($cacheKey !== null) {
                    \Cache::put($cacheKey, 1, 60); // minutes; Kapso gives up after ~2.5
                }

                return response('OK', 200);
            }

            switch ($event) {
                case 'whatsapp.message.received':
                    ProcessInboundMessage::dispatch($account->id, $payload);
                    break;

                case 'whatsapp.message.sent':
                case 'whatsapp.message.failed':
                    ReconcileOutboundMessage::dispatch($account->id, $event, $payload);
                    break;

                default:
                    \Log::info('[KapsoWhatsApp] Ignoring unsubscribed event: '.$event);
                    break;
            }

            // Committed only after the work above has actually succeeded (queued
            // or intentionally ignored). If dispatch() throws, execution never
            // reaches here, so the catch below does not leave a "handled" marker
            // behind — a genuine Kapso retry after a transient queue outage is
            // still processed instead of being silently swallowed forever.
            if ($cacheKey !== null) {
                \Cache::put($cacheKey, 1, 60); // minutes; Kapso gives up after ~2.5
            }
        } catch (\Throwable $e) {
            // Never 500: that would count towards Kapso's auto-pause threshold.
            // \Throwable (not \Exception) so a TypeError/ArgumentCountError from
            // the code this dispatches to (Tasks 6 and 9) can't escape either.
            \Log::error('[KapsoWhatsApp] Webhook handling failed: '.$e->getMessage(), [
                'account_id' => $account->id,
                'event'      => $event,
            ]);
        }

        return response('OK', 200);
    }
}
