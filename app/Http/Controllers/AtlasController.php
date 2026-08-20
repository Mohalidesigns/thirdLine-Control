<?php

namespace App\Http\Controllers;

use App\Http\Requests\AiAssistRequest;
use App\Services\Ai\AiService;
use App\Services\Ai\Exceptions\AiException;
use Illuminate\Http\JsonResponse;

/**
 * Atlas, the conversational assistant (§C.6 #9).
 *
 * A separate controller from AiAssistController because the drawer is a
 * different interaction: no subject, no accept/reject, and the conversation
 * lives in the browser rather than in a table. Deliberately so — persisting
 * chat history server-side would create a second store of tenant questions
 * alongside ai_interactions, with its own retention question and no
 * governance benefit. Each interaction is already audited individually.
 */
class AtlasController extends Controller
{
    public function __construct(private AiService $ai) {}

    public function ask(AiAssistRequest $request): JsonResponse
    {
        try {
            $draft = $this->ai->run('atlas.chat', $request->user(), $request->validated());
        } catch (AiException $e) {
            return response()->json([
                'message' => $e->userMessage(),
                'status' => $e->status(),
            ], $e->status() === 'Rate Limited' ? 429 : 422);
        }

        $preview = $draft->preview();
        $content = (array) ($preview['content'] ?? []);

        return response()->json([
            'interaction_id' => $preview['interaction_id'],
            // The explicit "I don't have that". Rendered as its own state in
            // the drawer, never as prose the user might read as an answer.
            'insufficient_context' => (bool) ($preview['insufficient_context']
                || ($content['insufficient_context'] ?? false)),
            'answer' => (string) ($content['answer'] ?? ''),
            'citations' => $preview['citations'],
            'confidence' => $preview['confidence'],
            'low_confidence' => $preview['low_confidence'],
            'suggested_actions' => array_values(array_filter(
                (array) ($content['suggested_actions'] ?? []),
                'is_string',
            )),
        ]);
    }
}
