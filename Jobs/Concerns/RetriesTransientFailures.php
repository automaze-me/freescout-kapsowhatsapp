<?php

namespace Modules\KapsoWhatsApp\Jobs\Concerns;

use Modules\KapsoWhatsApp\Exceptions\KapsoApiException;

/**
 * Delayed retries for the send jobs when a Kapso call fails for a reason
 * that can clear up on its own (KapsoApiException::isTransient()).
 *
 * Laravel 5.5 re-runs a failed job after the worker's --delay, which core's
 * scheduler leaves at 0, so "rethrow and let $tries retry" burned all three
 * attempts within seconds: a DNS hiccup shorter than a minute lost a reply
 * for good (seen in production). Instead, a transient failure queues a
 * fresh run of the same job 1, 5 and 15 minutes later, then hourly, for
 * just under a day -- a free-form reply cannot reach the customer outside
 * WhatsApp's 24h window anyway. A fresh run rather than
 * release() keeps $tries meaning what it always meant (immediate retries
 * for unexpected exceptions and timeouts), so a genuine bug never loops for
 * a whole day.
 *
 * Re-running is safe by construction: the send-once claim rows skip every
 * part Kapso already accepted, and guards() re-checks on every run that the
 * thread is still a published message.
 */
trait RetriesTransientFailures
{
    /**
     * How many delayed retries were scheduled before this run.
     */
    public $transientRetries = 0;

    /**
     * Seconds to wait before delayed retry number $retry (0-based), or null
     * once the schedule is used up: 1, 5 and 15 minutes, then 23 hourly
     * steps -- 23h21m in total.
     */
    public static function transientRetryDelay($retry)
    {
        $steps = [60, 300, 900];

        if ($retry < count($steps)) {
            return $steps[$retry];
        }

        return $retry < count($steps) + 23 ? 3600 : null;
    }

    /**
     * A new instance of this job for the same send.
     */
    abstract protected function freshRun();

    /**
     * Queues the next delayed retry. Returns false once the schedule is used
     * up -- the caller then records the failure.
     */
    protected function retryLater(KapsoApiException $e)
    {
        $delay = static::transientRetryDelay($this->transientRetries);

        if ($delay === null) {
            return false;
        }

        \Log::error('[KapsoWhatsApp] '.class_basename($this).': temporary Kapso failure, retrying later', [
            'thread_id' => $this->threadId,
            'retry'     => $this->transientRetries + 1,
            'delay'     => $delay,
            'error'     => $e->getMessage(),
        ]);

        $next = $this->freshRun();
        $next->transientRetries = $this->transientRetries + 1;

        dispatch($next->onConnection($this->connection)->onQueue($this->queue)->delay($delay));

        return true;
    }
}
