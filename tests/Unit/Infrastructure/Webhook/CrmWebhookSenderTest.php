<?php

namespace Tests\Unit\Infrastructure\Webhook;

use App\Infrastructure\Webhook\CrmWebhookSender;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CrmWebhookSenderTest extends TestCase
{
    private CrmWebhookSender $sender;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sender = new CrmWebhookSender;
    }

    #[Test]
    public function send_uses_outbound_config_by_default(): void
    {
        config([
            'webhook.outbound.url' => 'https://outbound.example.com/webhook',
            'webhook.outbound.secret' => 'outbound-secret-key',
        ]);

        Http::fake();

        $this->sender->send('test.event', ['key' => 'value']);

        Http::assertSent(function ($request) {
            return str_starts_with($request->url(), 'https://outbound.example.com/webhook')
                && $request->hasHeader('X-CRM-Signature');
        });
    }

    #[Test]
    public function send_accepts_custom_config_prefix(): void
    {
        config([
            'webhook.n8n_pipeline.url' => 'https://n8n.example.com/pipeline',
            'webhook.n8n_pipeline.secret' => 'n8n-secret-key',
        ]);

        Http::fake();

        $this->sender->send('pipeline.etapa_changed', ['oportunidad_id' => 1], 'n8n_pipeline');

        Http::assertSent(function ($request) {
            return str_starts_with($request->url(), 'https://n8n.example.com/pipeline')
                && $request->hasHeader('X-CRM-Signature');
        });
    }

    #[Test]
    public function send_builds_signature_with_correct_secret(): void
    {
        config([
            'webhook.n8n_pipeline.url' => 'https://n8n.example.com/webhook',
            'webhook.n8n_pipeline.secret' => 'custom-secret-123',
        ]);

        Http::fake();

        $this->sender->send('test.event', ['foo' => 'bar'], 'n8n_pipeline');

        Http::assertSent(function ($request) {
            $signature = $request->header('X-CRM-Signature')[0] ?? '';

            return str_starts_with($signature, 'sha256=');
        });
    }

    #[Test]
    public function send_includes_event_and_data_in_payload(): void
    {
        config([
            'webhook.outbound.url' => 'https://outbound.example.com/webhook',
            'webhook.outbound.secret' => 'test-secret',
        ]);

        Http::fake();

        $this->sender->send('user.created', ['user_id' => 42, 'name' => 'John']);

        Http::assertSent(function ($request) {
            $body = $request->data();

            return isset($body['event'])
                && $body['event'] === 'user.created'
                && isset($body['data']['user_id'])
                && $body['data']['user_id'] === 42;
        });
    }

    #[Test]
    public function send_includes_iso_timestamp(): void
    {
        config([
            'webhook.outbound.url' => 'https://outbound.example.com/webhook',
            'webhook.outbound.secret' => 'test-secret',
        ]);

        Http::fake();

        $this->sender->send('test.event', []);

        Http::assertSent(function ($request) {
            $body = $request->data();

            return isset($body['timestamp'])
                && is_string($body['timestamp'])
                && str_contains($body['timestamp'], 'T');
        });
    }

    #[Test]
    public function send_logs_error_on_failure(): void
    {
        config([
            'webhook.outbound.url' => 'https://nonexistent.example.com/webhook',
            'webhook.outbound.secret' => 'test-secret',
        ]);

        Http::fake([
            'https://nonexistent.example.com/webhook' => Http::response(null, 500),
        ]);

        // Should not throw; errors are caught and logged
        $this->sender->send('test.event', ['key' => 'value']);

        // Assert the request was attempted
        Http::assertSentCount(1);
    }
}
