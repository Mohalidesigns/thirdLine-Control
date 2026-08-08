<?php

namespace App\Services\Ai\Capabilities;

use App\Models\User;
use App\Services\Ai\AcceptedDraft;
use App\Services\Ai\AiDraft;
use App\Services\Ai\AiGateway;
use App\Services\Ai\CapabilityRegistry;
use App\Services\Ai\CapabilityRequest;
use App\Services\Ai\Exceptions\AiDecisionRequiredException;
use Illuminate\Database\Eloquent\Model;

/**
 * One of the nine capabilities (Part C §C.6).
 *
 * A capability owns three things: what to retrieve, what variables the
 * prompt needs, and — where the output can become a domain record — what
 * accepting it does. It owns nothing about calling the model: that is the
 * gateway's pipeline, and routing around it would route around redaction,
 * budget and audit at the same time.
 *
 * apply() takes an AcceptedDraft, never an AiDraft and never an array. That
 * signature is R4: there is no way to hand a capability raw model output,
 * because the only object carrying a payload cannot exist without a
 * recorded human decision.
 */
abstract class Capability
{
    public function __construct(protected AiGateway $gateway) {}

    abstract public function key(): string;

    /**
     * Build the request. $input is the validated controller payload.
     *
     * @param  array<string, mixed>  $input
     */
    abstract public function request(User $user, array $input, ?Model $subject = null): CapabilityRequest;

    /**
     * @param  array<string, mixed>  $input
     */
    public function run(User $user, array $input, ?Model $subject = null): AiDraft
    {
        return $this->gateway->execute($this->request($user, $input, $subject));
    }

    /**
     * Turn an accepted draft into a domain record.
     *
     * The default is to do nothing and say so. Several capabilities are
     * advisory by design — evidence review never sets a test result, and
     * Atlas answers a question rather than creating anything — so "there is
     * nothing to apply" is a correct outcome for them, not a gap.
     */
    public function apply(AcceptedDraft $draft, User $approver): ?Model
    {
        throw new AiDecisionRequiredException(
            'This assistant produces advice for a person to act on; there is nothing to apply automatically.',
        );
    }

    /** Whether accepting this capability's draft creates or changes a record. */
    public function isApplicable(): bool
    {
        return false;
    }

    public function label(): string
    {
        return CapabilityRegistry::label($this->key());
    }

    /**
     * Trim a value to a column width without a hard truncate mid-word where
     * it can be avoided. Model output routinely runs slightly over a
     * varchar, and failing an accepted draft on a 260-character title would
     * be an absurd place to lose a reviewer's work.
     */
    protected function fit(?string $value, int $length): ?string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        return mb_strlen($value) <= $length ? $value : mb_substr($value, 0, $length);
    }

    /**
     * Constrain model output to a known set, falling back rather than
     * writing an invalid enum. The schema already restricts these, but the
     * database is the thing that has to stay consistent.
     *
     * @param  array<int, string>  $allowed
     */
    protected function oneOf(mixed $value, array $allowed, string $fallback): string
    {
        $value = is_string($value) ? trim($value) : '';

        foreach ($allowed as $option) {
            if (strcasecmp($value, $option) === 0) {
                return $option;
            }
        }

        return $fallback;
    }
}
