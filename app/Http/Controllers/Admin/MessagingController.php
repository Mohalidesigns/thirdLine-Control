<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\MessageLog;
use App\Models\SmsTemplate;
use App\Models\WhatsappTemplate;
use App\Services\Messaging\WebPushService;
use App\Services\Messaging\WhatsAppService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Omnichannel administration (15.3/15.4): template catalogue with Meta
 * approval states, the message delivery log, and per-channel cost
 * tracking. Read/manage gated by `manage messaging`.
 */
class MessagingController extends Controller
{
    public function __construct(
        private WhatsAppService $whatsApp,
        private WebPushService $push,
    ) {}

    public function index(Request $request): Response
    {
        abort_unless($request->user()->can('manage messaging'), 403);

        $tenantId = $request->user()->tenant_id;

        return Inertia::render('Admin/Messaging/Index', [
            'whatsappTemplates' => WhatsappTemplate::query()
                ->where(fn ($q) => $q->whereNull('tenant_id')->orWhere('tenant_id', $tenantId))
                ->orderBy('key')
                ->get(),
            'smsTemplates' => SmsTemplate::query()
                ->where(fn ($q) => $q->whereNull('tenant_id')->orWhere('tenant_id', $tenantId))
                ->orderBy('key')
                ->get(),
            'logs' => MessageLog::query()
                ->with('user:id,name')
                ->when($request->channel, fn ($q, $c) => $q->where('channel', $c))
                ->when($request->status, fn ($q, $s) => $q->where('status', $s))
                ->orderByDesc('id')
                ->paginate(20)
                ->withQueryString(),
            'costs' => MessageLog::query()
                ->where('direction', 'out')
                ->whereNotNull('cost_currency')
                ->groupBy('channel', 'cost_currency')
                ->select('channel', 'cost_currency',
                    DB::raw('sum(cost_minor) as total_minor'),
                    DB::raw('count(*) as messages'))
                ->get(),
            'channels' => [
                'whatsapp' => $this->whatsApp->isConfigured(),
                'sms' => config('messaging.sms.driver') !== 'log',
                'push' => $this->push->isConfigured(),
                'ussd' => (bool) config('messaging.ussd.webhook_token'),
            ],
            'filters' => $request->only(['channel', 'status']),
        ]);
    }

    /** Pull each template's review state from Meta. */
    public function syncTemplates(Request $request): RedirectResponse
    {
        abort_unless($request->user()->can('manage messaging'), 403);

        $updated = $this->whatsApp->syncTemplateStatuses();

        return back()->with('success', $updated > 0
            ? "{$updated} template(s) synced from Meta."
            : 'No templates synced — check the WhatsApp credentials and that templates exist in Meta Business Manager.');
    }
}
