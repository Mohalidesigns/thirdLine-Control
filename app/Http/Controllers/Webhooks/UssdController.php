<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Models\CsaCampaign;
use App\Models\User;
use App\Services\AttestationService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

/**
 * USSD attestation flow (15.4) — the one high-volume use case worth a
 * USSD menu: confirm an outstanding attestation from any handset, no
 * data plan required. Behind the `ussd` feature flag AND a shared token;
 * Africa's Talking request/response conventions (CON/END plain text).
 *
 * Menu: dial in → outstanding attestations listed → pick one → confirm.
 * Only signing happens here — approvals and SoD-gated transitions are
 * never reachable from this channel, the same rule the offline outbox
 * enforces (15.1).
 */
class UssdController extends Controller
{
    public function __construct(private AttestationService $attestations) {}

    public function handle(Request $request): Response
    {
        $token = config('messaging.ussd.webhook_token');

        if (! $token || ! hash_equals($token, (string) $request->query('token'))) {
            return response('Forbidden', 403);
        }

        $phone = ltrim((string) $request->input('phoneNumber'), '+');
        $text = (string) $request->input('text', '');

        $user = User::withoutGlobalScopes()
            ->where('is_active', true)
            ->where(fn ($q) => $q->where('phone', $phone)->orWhere('phone', '+'.$phone))
            ->first();

        if (! $user) {
            return $this->reply('END This number is not registered on SecondLine.');
        }

        $outstanding = $this->outstanding($user);
        $steps = $text === '' ? [] : explode('*', $text);

        if ($steps === []) {
            if ($outstanding->isEmpty()) {
                return $this->reply('END Hello '.$user->name.'. You have no outstanding attestations.');
            }

            $menu = $outstanding->values()->map(
                fn ($c, $i) => ($i + 1).'. '.mb_substr($c->reference.' '.$c->name, 0, 60)
            )->implode("\n");

            return $this->reply("CON Outstanding attestations — reply with a number:\n".$menu);
        }

        $campaign = $outstanding->values()->get(((int) $steps[0]) - 1);

        if (! $campaign) {
            return $this->reply('END Invalid selection.');
        }

        if (count($steps) === 1) {
            return $this->reply('CON Attest to "'.mb_substr($campaign->name, 0, 80)."\"?\n1. Confirm\n2. Cancel");
        }

        if ($steps[1] !== '1') {
            return $this->reply('END Cancelled. Nothing was recorded.');
        }

        $subject = $this->attestations->resolveSubject($campaign);

        if (! $subject) {
            return $this->reply('END This campaign is not configured for USSD attestation.');
        }

        try {
            $this->attestations->attest($campaign, $user, $subject, 'ussd');
        } catch (ValidationException $e) {
            return $this->reply('END '.collect($e->errors())->flatten()->first());
        }

        return $this->reply('END Attestation recorded for '.$campaign->reference.'. Thank you.');
    }

    private function outstanding(User $user)
    {
        return CsaCampaign::withoutGlobalScopes()
            ->where('tenant_id', $user->tenant_id)
            ->where('campaign_type', 'attestation')
            ->where('status', 'Open')
            ->whereHas('responses', fn ($q) => $q
                ->where('respondent_id', $user->id)
                ->whereNotIn('status', ['Submitted', 'Accepted']))
            ->orderBy('closes_at')
            ->limit(5)
            ->get(['id', 'reference', 'name', 'scope_definition', 'tenant_id', 'campaign_type', 'status', 'opens_at', 'closes_at']);
    }

    private function reply(string $body): Response
    {
        return response($body, 200, ['Content-Type' => 'text/plain']);
    }
}
