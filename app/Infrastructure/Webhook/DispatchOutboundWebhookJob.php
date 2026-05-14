<?php

namespace App\Infrastructure\Webhook;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class DispatchOutboundWebhookJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(
        public string $event,
        public array $data,
    ) {
        $this->queue = 'webhooks';
    }

    public function backoff(): array
    {
        return [60, 120, 180];
    }

    public function handle(CrmWebhookSender $sender): void
    {
        $sender->send($this->event, $this->data);
    }
}
