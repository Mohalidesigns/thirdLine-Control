<?php

namespace App\Console\Commands;

use App\Models\CompensatingControl;
use Illuminate\Console\Command;

class ExpireCompensatingControls extends Command
{
    protected $signature = 'secondline:expire-compensating-controls';

    protected $description = 'Expire temporary compensating controls at their end date and re-escalate unremediated parent exceptions (FR-6.5)';

    public function handle(): int
    {
        $expired = 0;

        CompensatingControl::withoutGlobalScopes()
            ->where('is_temporary', true)
            ->whereIn('status', ['Approved', 'Active'])
            ->whereDate('effective_to', '<', now())
            ->each(function (CompensatingControl $compensating) use (&$expired) {
                $compensating->update(['status' => 'Expired']);
                $expired++;

                $exception = $compensating->exception;

                if ($exception && $exception->isOpen()) {
                    $exception->logActivity(
                        'Escalation',
                        null,
                        null,
                        "Compensating control expired on {$compensating->effective_to->format('d M Y')} while the primary control remains unremediated.",
                    );
                    // Reset updated_at so the inactivity trigger re-fires promptly.
                    $exception->touch();
                }
            });

        $this->info("Expired {$expired} compensating control(s).");

        return self::SUCCESS;
    }
}
