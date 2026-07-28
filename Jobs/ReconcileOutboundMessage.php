<?php

namespace Modules\KapsoWhatsApp\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ReconcileOutboundMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $accountId;
    public $event;
    public $payload;
    public $tries = 3;

    public function __construct($accountId, $event, array $payload)
    {
        $this->accountId = $accountId;
        $this->event     = $event;
        $this->payload   = $payload;
    }

    public function handle()
    {
        // Implemented in Task 9.
    }
}
