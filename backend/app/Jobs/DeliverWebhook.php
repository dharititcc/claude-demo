<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Tenant;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Delivers one webhook to one endpoint.
 *
 * Queued because it makes an outbound HTTP call: the request that triggered the
 * event must not block on a slow or dead receiver. Runs inside the originating
 * tenant's context — a queued job has no ambient tenant, so it re-initializes
 * tenancy from the id it carries.
 */
class DeliverWebhook implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** Retry a few times with backoff before giving up on this event. */
    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [10, 60, 300];

    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        public readonly string $tenantId,
        public readonly int $endpointId,
        public readonly string $event,
        public readonly array $payload,
    ) {}

    public function handle(): void
    {
        $tenant = Tenant::find($this->tenantId);

        if ($tenant === null) {
            return; // organization deleted since the event fired
        }

        $tenant->run(function () {
            $endpoint = WebhookEndpoint::find($this->endpointId);

            // The endpoint may have been deleted or paused between enqueue and
            // run; there is nothing to deliver to.
            if ($endpoint === null || ! $endpoint->is_active) {
                return;
            }

            $body = json_encode([
                'event' => $this->event,
                'data' => $this->payload,
                'timestamp' => now()->toIso8601String(),
            ], JSON_THROW_ON_ERROR);

            // Sign the exact bytes we send. The receiver recomputes the HMAC to
            // prove the payload is ours and untampered.
            $signature = hash_hmac('sha256', $body, $endpoint->secret);

            try {
                $response = Http::timeout(10)
                    ->withHeaders([
                        'Content-Type' => 'application/json',
                        'X-Webhook-Event' => $this->event,
                        'X-Signature' => "sha256={$signature}",
                    ])
                    ->withBody($body, 'application/json')
                    ->post($endpoint->url);

                $this->record($endpoint, $response->status(), $response->successful());

                if ($response->successful()) {
                    $this->markHealthy($endpoint);
                } else {
                    $this->markFailed($endpoint);
                }
            } catch (Throwable $e) {
                $this->record($endpoint, null, false, $e->getMessage());
                $this->markFailed($endpoint);

                // Re-throw so the queue applies its backoff/retry, unless we have
                // exhausted attempts — then let it fail quietly (already logged).
                if ($this->attempts() < $this->tries) {
                    throw $e;
                }
            }
        });
    }

    private function record(WebhookEndpoint $endpoint, ?int $status, bool $success, ?string $error = null): void
    {
        WebhookDelivery::create([
            'webhook_endpoint_id' => $endpoint->id,
            'event' => $this->event,
            'payload' => $this->payload,
            'status_code' => $status,
            'success' => $success,
            'error' => $error !== null ? mb_substr($error, 0, 1000) : null,
            'attempt' => $this->attempts(),
            'delivered_at' => now(),
        ]);
    }

    private function markHealthy(WebhookEndpoint $endpoint): void
    {
        $endpoint->forceFill([
            'consecutive_failures' => 0,
            'last_success_at' => now(),
        ])->save();
    }

    /**
     * Count a failure and, past the threshold, auto-pause the endpoint. A dead
     * receiver should stop consuming queue workers, and its owner can re-enable
     * it once fixed.
     */
    private function markFailed(WebhookEndpoint $endpoint): void
    {
        $failures = $endpoint->consecutive_failures + 1;

        $endpoint->forceFill([
            'consecutive_failures' => $failures,
            'last_failure_at' => now(),
            'is_active' => $failures < WebhookEndpoint::FAILURE_THRESHOLD,
        ])->save();
    }
}
