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
     * Kapso pauses a webhook after ~15 minutes at a >=85% failure rate,
     * until a human re-enables it in their dashboard. Nothing in this method
     * may throw uncaught: verify (done in middleware), dedupe, dispatch. This
     * mostly ends in 200 -- but not always: a job-dispatch/infrastructure
     * failure (the queue backend is unreachable) returns 503 instead, so
     * Kapso actually retries the delivery rather than believing it was
     * handled. See the inner try/catch below for why that one failure mode
     * is deliberately allowed to differ from the "always 200" rule that
     * governs everything else in this method.
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

            try {
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
            } catch (\Throwable $e) {
                // A queue/infrastructure failure while dispatching the job:
                // Kapso must actually retry this delivery, or the message is
                // gone for good once Kapso stops retrying (a 200 here would
                // acknowledge a delivery that was never queued at all -- the
                // same reasoning KapsoSignature applies to its own 503).
                // This is the one failure inside this method allowed to
                // produce a non-200; everything else is deliberately
                // swallowed as 200 below, because it would keep failing
                // identically on every retry and only burn Kapso's
                // auto-pause budget for nothing. Not committing the
                // idempotency key here (it's set only after this try
                // succeeds, below) is what lets the retry actually be
                // reprocessed instead of hitting the dedupe short-circuit.
                \Log::error('[KapsoWhatsApp] Job dispatch failed, asking Kapso to retry: '.$e->getMessage(), [
                    'account_id' => $account->id,
                    'event'      => $event,
                ]);

                return response('Service Unavailable', 503);
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
            // Anything landing here (as opposed to the narrower catch above)
            // is not a dispatch/infrastructure failure -- it would fail
            // identically on every retry, so 200 is correct: a non-200 would
            // just burn Kapso's auto-pause budget for a payload that is never
            // going to succeed.
            \Log::error('[KapsoWhatsApp] Webhook handling failed: '.$e->getMessage(), [
                'account_id' => $account->id,
                'event'      => $event,
            ]);
        }

        return response('OK', 200);
    }
}
