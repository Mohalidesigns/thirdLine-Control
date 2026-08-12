<?php

namespace App\Services\Ai;

use App\Models\AiConfiguration;
use App\Models\AiInteraction;
use App\Models\AiKnowledgeChunk;
use App\Models\AiPrompt;
use App\Services\Ai\Exceptions\AiException;
use App\Services\Ai\Exceptions\AiUnavailableException;
use App\Services\Ai\Exceptions\CapabilityDisabledException;
use App\Services\Ai\Exceptions\SchemaValidationException;
use Illuminate\Support\Collection;

/**
 * The one way in and the one way out (Part C §C.4).
 *
 * Every AI call in the product runs this pipeline, in this order:
 *
 *   1  capability enabled for the tenant
 *   2  user authorised for the capability and its subject
 *   3  budget available            — before anything is spent
 *   4  rate limit                  — per user, capability and tenant
 *   5  context assembled           — permission-filtered at query time
 *   6  redaction                   — mandatory, unskippable, then verified
 *   7  prompt rendered             — from the versioned registry
 *   8  provider call               — retry, backoff, timeout
 *   9  parse and validate          — one repair attempt, then fail
 *   10 confidence scored
 *   11 citations resolved          — unresolvable ones dropped
 *   12 interaction recorded        — always, success or failure
 *   13 AiDraft returned            — a suggestion, never a decision
 *
 * Steps 12 and 13 are why the failure paths in here all write a row before
 * they throw: "the model was never asked" and "the model was asked and we
 * ignored it" are different facts, and a governance review needs both (R3).
 */
class AiGateway
{
    public function __construct(
        private OllamaClient $client,
        private PromptRegistry $prompts,
        private RedactionService $redaction,
        private RetrievalService $retrieval,
        private SchemaValidator $validator,
        private BudgetService $budget,
    ) {}

    public function execute(CapabilityRequest $request): AiDraft
    {
        $tenantId = (int) $request->user->tenant_id;
        $this->redaction->reset();

        // ── 1-4. Gates. Each writes its own interaction row and stops. ──
        try {
            $configuration = $this->assertCapabilityEnabled($tenantId, $request->capabilityKey);
            $this->assertAuthorised($request);
            $this->budget->assertWithinBudget($tenantId, $request->capabilityKey);
            $this->budget->assertWithinRateLimit($tenantId, (int) $request->user->getKey(), $request->capabilityKey);
        } catch (AiException $e) {
            throw $this->recordFailure($request, null, $e);
        }

        $prompt = $this->prompts->resolve($request->capabilityKey, $tenantId);
        $model = $this->modelFor($configuration, $prompt);

        if (! $this->client->configured()) {
            throw $this->recordFailure($request, $prompt, new AiUnavailableException(
                'No Ollama endpoint is configured for this installation.',
            ), $model);
        }

        // ── 5. Context. Permission-filtered inside RetrievalService. ──
        $hits = $request->retrievalQuery === ''
            ? collect()
            : $this->retrieval->retrieve(
                $request->user,
                $request->retrievalQuery,
                $request->effectiveSources(),
            );

        // ── 6. Redaction, then verification that it actually worked. ──
        $variables = $this->redaction->redactArray([
            ...$request->variables,
            'retrieved_context' => $this->retrieval->toContext($hits, $this->redaction),
            'has_context' => $hits->isNotEmpty(),
        ]);

        // ── 7. Render. ──
        $userMessage = $this->prompts->render($prompt, $variables);
        $systemPrompt = $prompt->system_prompt;

        // The unskippable check. If anything that matches a redaction
        // pattern is still in the payload at the point of egress, the call
        // is abandoned — a leak is worse than a missing feature (R5).
        if ($this->redaction->containsSensitive($userMessage.' '.$systemPrompt)) {
            throw $this->recordFailure($request, $prompt, new AiUnavailableException(
                'Redaction verification failed: the assembled payload still matched a sensitive pattern, so the request was abandoned before it was sent.',
            ), $model);
        }

        $interaction = $this->openInteraction($request, $prompt, $model, $variables, $hits);

        // ── 8-11. Call, parse, score, cite. ──
        try {
            return $this->run($request, $interaction, $prompt, $model, $systemPrompt, $userMessage, $hits);
        } catch (AiException $e) {
            $this->closeFailedInteraction($interaction, $e);

            throw $e;
        }
    }

    /**
     * Steps 8 through 13 for a request that has passed every gate.
     *
     * @param  Collection<int, array{chunk: AiKnowledgeChunk, score: float}>  $hits
     */
    private function run(
        CapabilityRequest $request,
        AiInteraction $interaction,
        AiPrompt $prompt,
        string $model,
        string $systemPrompt,
        string $userMessage,
        Collection $hits,
    ): AiDraft {
        $schema = (array) ($prompt->output_schema ?? []);
        $attempts = max(0, (int) config('ai.output.repair_attempts', 1));

        $messages = [['role' => 'user', 'content' => $userMessage]];
        $totalInput = 0;
        $totalOutput = 0;
        $totalLatency = 0;
        $rawParts = [];
        $errors = [];

        for ($attempt = 0; $attempt <= $attempts; $attempt++) {
            $response = $this->client->chat(
                $this->payload($model, $systemPrompt, $messages, $prompt, $schema),
            );

            $text = $this->textFrom($response['body']);

            $totalInput += (int) ($response['body']['prompt_eval_count'] ?? 0);
            $totalOutput += (int) ($response['body']['eval_count'] ?? 0);
            $totalLatency += $response['latency_ms'];
            $rawParts[] = $text;

            $parsed = $this->validator->extractJson($text);

            if ($parsed !== null) {
                $errors = $schema === [] ? [] : $this->validator->validate($parsed, $schema);

                if ($errors === []) {
                    return $this->complete(
                        $request, $interaction, $model, $parsed, $hits,
                        $totalInput, $totalOutput, $totalLatency, implode("\n---\n", $rawParts),
                    );
                }
            } else {
                $errors = ['Response was not valid JSON.'];
            }

            if ($attempt === $attempts) {
                break;
            }

            // One repair round: hand the model its own output and the
            // specific violations. Cheaper and more reliable than a blind
            // retry, which usually reproduces the same shape.
            $messages[] = ['role' => 'assistant', 'content' => $text];
            $messages[] = ['role' => 'user', 'content' => $this->repairInstruction($errors)];
        }

        $this->chargeUsage($interaction, $model, $totalInput, $totalOutput, $totalLatency, implode("\n---\n", $rawParts));

        throw new SchemaValidationException(
            'Model output failed schema validation after a repair attempt.',
            $errors,
        );
    }

    /**
     * Steps 10-13.
     *
     * @param  Collection<int, array{chunk: AiKnowledgeChunk, score: float}>  $hits
     */
    private function complete(
        CapabilityRequest $request,
        AiInteraction $interaction,
        string $model,
        array $parsed,
        Collection $hits,
        int $inputTokens,
        int $outputTokens,
        int $latencyMs,
        string $raw,
    ): AiDraft {
        // Names go back in so a reviewer reads a sentence rather than
        // "[PERSON_1] approved [PERSON_2]'s posting". Account numbers and
        // the rest stay redacted — putting those back would land them in a
        // screen and a PDF export, which is what R5 exists to prevent.
        $parsed = $this->redaction->rehydrateArray($parsed);

        $citations = $this->retrieval->resolveCitations(
            (array) ($parsed['citations'] ?? []),
            $hits,
        );

        $insufficient = (bool) ($parsed['insufficient_context'] ?? false) || ($hits->isEmpty() && $request->retrievalQuery !== '');

        $confidence = $this->score($parsed, $citations, $hits, $insufficient);

        $costMinor = $this->budget->costMinor($model, $inputTokens, $outputTokens);

        $interaction->update([
            'status' => 'Completed',
            'raw_output' => $raw,
            'parsed_output' => $parsed,
            'citations' => $citations,
            'confidence' => $confidence,
            'input_tokens' => $inputTokens,
            'output_tokens' => $outputTokens,
            'cost_minor' => $costMinor,
            'currency' => (string) config('services.ollama.pricing_currency', 'USD'),
            'latency_ms' => $latencyMs,
        ]);

        $this->budget->recordUsage((int) $request->user->tenant_id, $inputTokens + $outputTokens, $costMinor);

        return new AiDraft($interaction->refresh(), $parsed, $confidence, $citations, $insufficient);
    }

    /**
     * Confidence (§C.4 step 10): the model's own stated confidence,
     * tempered by how much of the answer is actually grounded in retrieved
     * records and whether the schema came back complete.
     *
     * The model's self-report is capped rather than trusted — a model that
     * says 0.95 about something it cited nothing for is confidently wrong,
     * and that is the failure mode this number exists to expose.
     *
     * @param  Collection<int, array{chunk: AiKnowledgeChunk, score: float}>  $hits
     */
    private function score(array $parsed, array $citations, Collection $hits, bool $insufficient): float
    {
        if ($insufficient) {
            return 0.0;
        }

        $stated = (float) ($parsed['confidence'] ?? 0.5);
        $stated = max(0.0, min(1.0, $stated));

        // Nothing was retrieved and nothing needed to be: judge on the
        // model's own report alone, mildly discounted.
        if ($hits->isEmpty()) {
            return round($stated * 0.85, 3);
        }

        $coverage = $this->retrieval->coverage($citations, $hits);

        if ($citations === []) {
            // Records were available and none were cited. That is the
            // ungrounded case, and it is worth flagging hard.
            return round($stated * 0.4, 3);
        }

        return round(($stated * 0.6) + ($coverage * 0.4), 3);
    }

    /**
     * The request body for Ollama's /api/chat.
     *
     * Optional parameters are gated on the per-model feature matrix in
     * config/services.php rather than assumed: not every Granite build
     * supports the think toggle, and sending an unsupported parameter is an
     * error or a silent no-op depending on the model. Streaming is always
     * off — the gateway parses one complete body, and the pipeline's schema
     * validation has nothing to do with a half-arrived response.
     *
     * @param  array<int, array<string, string>>  $messages
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    private function payload(string $model, string $system, array $messages, AiPrompt $prompt, array $schema): array
    {
        $features = $this->features($model);

        $payload = [
            'model' => $model,
            'messages' => [
                ['role' => 'system', 'content' => $system],
                ...$messages,
            ],
            'stream' => false,
            'options' => [
                'num_predict' => $prompt->max_tokens ?: (int) config('services.ollama.max_tokens', 8192),
                'num_ctx' => (int) config('services.ollama.num_ctx', 16384),
            ],
        ];

        if ($features['temperature'] && $prompt->temperature !== null) {
            $payload['options']['temperature'] = $prompt->temperature;
        }

        // Where the provider can enforce the schema, let it — Ollama takes
        // a JSON schema in `format` and constrains generation to it — then
        // validate anyway. A provider guarantee is not a control we own.
        if ($features['structured_output'] && $schema !== []) {
            $payload['format'] = $this->strictSchema($schema);
        }

        if ($features['thinking']) {
            $payload['think'] = true;
        }

        return $payload;
    }

    /**
     * @return array{thinking: bool, structured_output: bool, temperature: bool}
     */
    private function features(string $model): array
    {
        $default = (array) config('services.ollama.features.default', []);

        return [
            ...['thinking' => false, 'structured_output' => false, 'temperature' => false],
            ...$default,
            ...(array) config("services.ollama.features.{$model}", []),
        ];
    }

    /**
     * additionalProperties:false on every object in the schema, so the
     * constrained decoder cannot pad the output with invented keys. Adding
     * it here rather than in every seeded prompt keeps the stored schemas
     * readable and makes the requirement one thing to fix if the provider's
     * rule changes.
     */
    private function strictSchema(array $schema): array
    {
        if (($schema['type'] ?? null) === 'object') {
            $schema['additionalProperties'] = false;

            foreach ((array) ($schema['properties'] ?? []) as $name => $property) {
                $schema['properties'][$name] = $this->strictSchema((array) $property);
            }
        }

        if (($schema['type'] ?? null) === 'array' && isset($schema['items'])) {
            $schema['items'] = $this->strictSchema((array) $schema['items']);
        }

        return $schema;
    }

    private function textFrom(array $body): string
    {
        return (string) ($body['message']['content'] ?? '');
    }

    /** @param array<int, string> $errors */
    private function repairInstruction(array $errors): string
    {
        return "Your previous response did not satisfy the required output schema:\n\n- "
            .implode("\n- ", array_slice($errors, 0, 12))
            ."\n\nReturn the corrected result as a single JSON object matching the schema exactly. "
            .'Output only the JSON, with no surrounding prose and no code fence.';
    }

    /**
     * Which model actually runs: the tenant's configured override, then the
     * prompt's hint, then the capability's tier default. Every layer is data
     * (R1) — there is no model identifier hard-coded in a class.
     */
    private function modelFor(AiConfiguration $configuration, AiPrompt $prompt): string
    {
        if ($configuration->model) {
            return $configuration->model;
        }

        if ($prompt->model_hint) {
            return $prompt->model_hint;
        }

        $tier = CapabilityRegistry::tier($configuration->capability_key);

        return (string) config("services.ollama.{$tier}_model", config('services.ollama.default_model'));
    }

    private function assertCapabilityEnabled(int $tenantId, string $key): AiConfiguration
    {
        if (! CapabilityRegistry::exists($key)) {
            throw new CapabilityDisabledException("Unknown AI capability '{$key}'.");
        }

        $configuration = AiConfiguration::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('capability_key', $key)
            ->first();

        // A missing row means off. A fresh installation ships with AI
        // dormant, and enabling it is a deliberate administrative act.
        if (! $configuration || ! $configuration->is_enabled) {
            throw new CapabilityDisabledException(
                'This AI capability is not enabled for your organisation.',
            );
        }

        return $configuration;
    }

    /**
     * AI never widens anyone's reach. A capability requires the same domain
     * permission the manual equivalent does — drafting a control needs
     * 'create controls' exactly as writing one by hand does — and the
     * subject, where there is one, is checked through the existing policy.
     */
    private function assertAuthorised(CapabilityRequest $request): void
    {
        $permission = CapabilityRegistry::permission($request->capabilityKey);

        // Fail closed. A capability whose permission does not resolve is an
        // unknown or malformed key, and the only safe reading of that is
        // "refuse" — never "no permission required".
        if (! $permission || ! $request->user->can($permission)) {
            throw new CapabilityDisabledException(
                'You do not have permission to use this assistant.',
            );
        }

        if ($request->subject && $request->user->cannot('view', $request->subject)) {
            throw new CapabilityDisabledException(
                'You do not have access to the record this assistant was asked about.',
            );
        }
    }

    /**
     * @param  Collection<int, array{chunk: AiKnowledgeChunk, score: float}>  $hits
     */
    private function openInteraction(
        CapabilityRequest $request,
        AiPrompt $prompt,
        string $model,
        array $redactedVariables,
        Collection $hits,
    ): AiInteraction {
        return AiInteraction::withoutGlobalScopes()->create([
            'tenant_id' => $request->user->tenant_id,
            'user_id' => $request->user->getKey(),
            'capability_key' => $request->capabilityKey,
            'prompt_id' => $prompt->getKey(),
            'prompt_version' => $prompt->version,
            'model' => $model,
            'subject_type' => $request->subject?->getMorphClass(),
            'subject_id' => $request->subject?->getKey(),
            // Hashed over the redacted payload, so identical questions are
            // recognisable without the original ever being stored.
            'input_hash' => hash('sha256', json_encode($redactedVariables)),
            'input_redacted' => $redactedVariables,
            'retrieved_context' => $this->retrieval->toContextIds($hits),
            'status' => 'Pending',
            'currency' => (string) config('services.ollama.pricing_currency', 'USD'),
        ]);
    }

    /**
     * A gate refused before an interaction existed. Record it anyway — a
     * budget stop that leaves no trace is a budget stop nobody can audit.
     */
    private function recordFailure(CapabilityRequest $request, ?AiPrompt $prompt, AiException $e, ?string $model = null): AiException
    {
        AiInteraction::withoutGlobalScopes()->create([
            'tenant_id' => $request->user->tenant_id,
            'user_id' => $request->user->getKey(),
            'capability_key' => $request->capabilityKey,
            'prompt_id' => $prompt?->getKey(),
            'prompt_version' => $prompt?->version,
            'model' => $model,
            'subject_type' => $request->subject?->getMorphClass(),
            'subject_id' => $request->subject?->getKey(),
            'status' => $e->status(),
            'error_message' => $this->client->scrub($e->getMessage()),
            'currency' => (string) config('services.ollama.pricing_currency', 'USD'),
        ]);

        return $e;
    }

    private function closeFailedInteraction(AiInteraction $interaction, AiException $e): void
    {
        $interaction->update([
            'status' => $e->status(),
            'error_message' => $this->client->scrub($e->getMessage()),
        ]);
    }

    /**
     * Tokens burned on a call that ultimately failed still cost money and
     * still count against the budget. Charging only for successes would let
     * a badly-tuned prompt loop for free.
     */
    private function chargeUsage(AiInteraction $interaction, string $model, int $input, int $output, int $latency, string $raw): void
    {
        $costMinor = $this->budget->costMinor($model, $input, $output);

        $interaction->update([
            'input_tokens' => $input,
            'output_tokens' => $output,
            'cost_minor' => $costMinor,
            'latency_ms' => $latency,
            'raw_output' => $raw,
        ]);

        $this->budget->recordUsage((int) $interaction->tenant_id, $input + $output, $costMinor);
    }
}
