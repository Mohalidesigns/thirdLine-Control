<?php

namespace App\Services\Ai;

use App\Services\Ai\Exceptions\AiUnavailableException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * The only class in the application that reads the Anthropic API key (R9).
 *
 * Everything above it deals in arrays and never sees a credential, which is
 * what makes "the key never appears in a log, a response or a serialised
 * prop" testable rather than aspirational: there is exactly one place to
 * check.
 *
 * Laravel's HTTP client is used rather than a vendor SDK on purpose. The
 * phase's own acceptance test is "mock the HTTP client and inspect the
 * outbound body" — Http::fake() makes that a first-class assertion against
 * the real transport, where an SDK would need its own injected handler and
 * would put a beta dependency inside a production GRC deployment for no
 * capability we use.
 */
class AnthropicClient
{
    public function configured(): bool
    {
        return filled(config('services.anthropic.api_key'));
    }

    /**
     * POST /v1/messages with bounded retries.
     *
     * Retries 429 and 5xx with exponential backoff plus jitter, and honours
     * a Retry-After header when the provider sends one. A 4xx that is not
     * 429 is a request we built wrong — retrying it just burns the budget,
     * so it fails immediately.
     *
     * @param  array<string, mixed>  $payload
     * @return array{body: array, status: int, latency_ms: int}
     */
    public function messages(array $payload): array
    {
        if (! $this->configured()) {
            throw new AiUnavailableException('ANTHROPIC_API_KEY is not configured.');
        }

        $maxRetries = max(0, (int) config('services.anthropic.max_retries', 3));
        $baseMs = max(50, (int) config('services.anthropic.retry_base_ms', 500));
        $startedAt = microtime(true);
        $lastError = 'No attempt was made.';

        for ($attempt = 0; $attempt <= $maxRetries; $attempt++) {
            try {
                $response = $this->send($payload);
            } catch (ConnectionException $e) {
                // Timeouts and DNS failures. Message is ours, not the
                // provider's, and carries no configuration.
                $lastError = 'Connection to the model provider failed or timed out.';
                $this->backoff($attempt, $baseMs, null);

                continue;
            }

            if ($response->successful()) {
                return [
                    'body' => (array) $response->json(),
                    'status' => $response->status(),
                    'latency_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                ];
            }

            $lastError = $this->describe($response);

            if (! $this->retryable($response->status()) || $attempt === $maxRetries) {
                break;
            }

            $this->backoff($attempt, $baseMs, $response->header('retry-after'));
        }

        throw new AiUnavailableException($lastError);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function send(array $payload): Response
    {
        $base = rtrim((string) config('services.anthropic.base_url'), '/');

        return Http::withHeaders([
            'x-api-key' => (string) config('services.anthropic.api_key'),
            'anthropic-version' => (string) config('services.anthropic.version'),
            'content-type' => 'application/json',
        ])
            ->timeout((int) config('services.anthropic.timeout', 120))
            ->connectTimeout((int) config('services.anthropic.connect_timeout', 10))
            ->post($base.'/v1/messages', $payload);
    }

    private function retryable(int $status): bool
    {
        return $status === 429 || $status >= 500;
    }

    private function backoff(int $attempt, int $baseMs, ?string $retryAfter): void
    {
        if (app()->runningUnitTests()) {
            return;
        }

        $delayMs = $retryAfter !== null && is_numeric($retryAfter)
            ? ((int) $retryAfter) * 1000
            : (int) ($baseMs * (2 ** $attempt) + random_int(0, $baseMs));

        usleep(min($delayMs, 30000) * 1000);
    }

    /**
     * A short, safe description of a failed response.
     *
     * The provider's error body is summarised to its type and message and
     * then scrubbed, because an echoed request header in an error payload is
     * a credible route for a key to reach a log file. Nothing here is shown
     * to a user either way — AiUnavailableException replaces it with fixed
     * text — but the interaction record keeps it, and the interaction record
     * is exportable.
     */
    private function describe(Response $response): string
    {
        $error = (array) ($response->json('error') ?? []);

        $detail = trim(implode(': ', array_filter([
            $error['type'] ?? null,
            $error['message'] ?? null,
        ])));

        if ($detail === '') {
            $detail = 'no detail returned';
        }

        return $this->scrub("Model provider returned HTTP {$response->status()} ({$detail}).");
    }

    /**
     * Remove anything that looks like a credential from a string bound for
     * storage. Belt and braces over never putting one there.
     */
    public function scrub(string $text): string
    {
        $key = (string) config('services.anthropic.api_key');

        if ($key !== '') {
            $text = str_replace($key, '[REDACTED]', $text);
        }

        return (string) preg_replace('/sk-ant-[A-Za-z0-9\-_]{8,}/', '[REDACTED]', $text);
    }
}
