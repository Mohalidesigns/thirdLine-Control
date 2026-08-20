<?php

namespace App\Services\Ai\Capabilities;

use App\Models\User;
use App\Services\Ai\CapabilityRequest;
use Illuminate\Database\Eloquent\Model;

/**
 * §C.6 #9 — Atlas, the conversational assistant.
 *
 * RAG-grounded over the tenant's own controls, risks, obligations, policies,
 * incidents and test results, permission-filtered at retrieval time, always
 * citing what it used, and saying "I don't have that" when retrieval returns
 * nothing.
 *
 * That last behaviour is the whole design. A general-purpose model asked
 * "what is our BVN verification control?" will happily produce a plausible
 * answer about BVN verification controls in general. Here, if the tenant's
 * index holds nothing the user is allowed to read on the subject, the prompt
 * gets an empty context block and the contract requires insufficient_context
 * — which the review panel renders as an explicit "I don't have enough
 * information in your data to answer that", not as prose.
 *
 * The permission filter is per-user and applied in SQL by RetrievalService,
 * so two people asking the same question get answers built from different
 * records, and neither ever sees the other's.
 *
 * No apply(): Atlas answers questions. Anything it suggests doing is done
 * through the module that owns it, by a person, with that module's own gates.
 */
class AtlasCapability extends Capability
{
    public function key(): string
    {
        return 'atlas.chat';
    }

    public function request(User $user, array $input, ?Model $subject = null): CapabilityRequest
    {
        $question = trim((string) ($input['question'] ?? ''));

        return new CapabilityRequest(
            capabilityKey: $this->key(),
            user: $user,
            variables: [
                'question' => $question,
                // Trimmed to the last few turns: Atlas is a lookup
                // assistant, and a long history mostly buys context cost
                // while making stale retrieval more likely to be reused.
                'conversation' => $this->conversation($input),
                'user_role' => $user->getRoleNames()->implode(', '),
                'today' => now()->toDateString(),
            ],
            subject: $subject,
            retrievalQuery: $this->searchQuery($question, $input),
        );
    }

    /**
     * Retrieval searches the question plus the immediately preceding one, so
     * a follow-up like "and which of those are overdue?" still retrieves
     * against the subject it refers to rather than against nothing.
     *
     * @param  array<string, mixed>  $input
     */
    private function searchQuery(string $question, array $input): string
    {
        $previous = collect((array) ($input['conversation'] ?? []))
            ->filter(fn ($turn) => ($turn['role'] ?? null) === 'user')
            ->pluck('content')
            ->last();

        return trim($question.' '.(string) $previous);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<int, array{role: string, content: string}>
     */
    private function conversation(array $input): array
    {
        return collect((array) ($input['conversation'] ?? []))
            ->filter(fn ($turn) => is_array($turn) && filled($turn['content'] ?? null))
            ->map(fn ($turn) => [
                'role' => ($turn['role'] ?? 'user') === 'assistant' ? 'assistant' : 'user',
                'content' => mb_substr((string) $turn['content'], 0, 2000),
            ])
            ->take(-6)
            ->values()
            ->all();
    }
}
