<?php

namespace App\Services\Ai;

use App\Services\Ai\Exceptions\AiUnavailableException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * The only class in the application that talks to the model provider, and
 * the only one that reads the optional Ollama API key (R9).
 *
 * The provider is a locally hosted Ollama server running IBM Granite, so in
 * the common case there is no credential at all — tenant data never leaves
 * the machine, which is the strongest possible reading of R5. The api_key
 * setting exists for an Ollama placed behind an authenticating reverse
 * proxy; when set, it travels as a bearer header from here and from nowhere
 * else, so "the key never appears in a log, a response or a serialised
 * prop" stays testable rather than aspirational: there is exactly one place
 * to check.
 *
 * Laravel's HTTP client is used rather than a vendor SDK on purpose. The
 * phase's own acceptance test is "mock the HTTP client and inspect the
 * outbound body" — Http::fake() makes that a first-class assertion against
 * the real transport.
 */
class OllamaClient
{
    public function configured(): bool
    {
        return filled(config('services.ollama.base_url'));
    }

    /**
     * POST /api/chat with bounded retries.
     *
     * Retries 429 and 5xx with exponential backoff plus jitter, and honours
     * a Retry-After header when one is sent. A 4xx that is not 429 is a
     * request we built wrong — a missing model, a malformed body — and
     * retrying it just burns time, so it fails immediately.
     *
     * @param  array<string, mixed>  $payload
     * @return array{body: array, status: int, latency_ms: int}
     */
    public function chat(array $payload): array
    {
        if (! $this->configured()) {
            throw new AiUnavailableException('OLLAMA_BASE_URL is not configured.');
        }

        $maxRetries = max(0, (int) config('services.ollama.max_retries', 3));
        $baseMs = max(50, (int) config('services.ollama.retry_base_ms', 500));
        $startedAt = microtime(true);
        $lastError = 'No attempt was made.';

        for ($attempt = 0; $attempt <= $maxRetries; $attempt++) {
            try {
                $response = $this->send($payload);
            } catch (ConnectionException $e) {
                // Timeouts and refused connections — usually an Ollama
                // server that is not running. Message is ours, not the
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
        $base = rtrim((string) config('services.ollama.base_url'), '/');
        $key = (string) config('services.ollama.api_key');

        $pending = Http::withHeaders(['content-type' => 'application/json'])
            ->timeout((int) config('services.ollama.timeout', 300))
            ->connectTimeout((int) config('services.ollama.connect_timeout', 10));

        if ($key !== '') {
            $pending = $pending->withToken($key);
        }

        return $pending->post($base.'/api/chat', $payload);
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
     * Ollama reports errors as {"error": "message"}. The message is kept —
     * "model 'granite4:micro' not found, try pulling it first" is exactly
     * what an operator needs to see — but scrubbed first, because an echoed
     * request header in an error payload is a credible route for a key to
     * reach a log file. Nothing here is shown to a user either way —
     * AiUnavailableException replaces it with fixed text — but the
     * interaction record keeps it, and the interaction record is exportable.
     */
    private function describe(Response $response): string
    {
        $error = $response->json('error');

        $detail = is_string($error) ? trim($error) : '';

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
        $key = (string) config('services.ollama.api_key');

        if ($key !== '') {
            $text = str_replace($key, '[REDACTED]', $text);
        }

        return $text;
    }
}
