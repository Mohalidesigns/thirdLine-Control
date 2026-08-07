<?php

namespace App\Console\Commands;

use App\Models\ControlException;
use App\Models\User;
use App\Notifications\OwnerDigestNotification;
use App\Services\NotificationDispatcher;
use Illuminate\Console\Command;

class SendOwnerDigests extends Command
{
    protected $signature = 'secondline:send-owner-digests';

    protected $description = 'Send each control owner a digest of their open and overdue exceptions (FR-8.6)';

    public function handle(): int
    {
        $sent = 0;

        ControlException::withoutGlobalScopes()
            ->whereIn('status', ControlException::OPEN_STATUSES)
            ->whereNotNull('owner_id')
            ->get()
            ->groupBy('owner_id')
            ->each(function ($exceptions, $ownerId) use (&$sent) {
                $owner = User::find($ownerId);

                if ($owner && $owner->is_active) {
                    app(NotificationDispatcher::class)
                        ->send($owner, 'owner.digest', new OwnerDigestNotification($exceptions->values()));
                    $sent++;
                }
            });

        $this->info("Sent {$sent} digest(s).");

        return self::SUCCESS;
    }
}
