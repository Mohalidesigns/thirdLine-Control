<?php

namespace App\Console\Commands;

use App\Models\Document;
use App\Notifications\DocumentReviewDueNotification;
use App\Services\NotificationDispatcher;
use Illuminate\Console\Command;

class RemindDocumentReviews extends Command
{
    protected $signature = 'atheris:remind-document-reviews {--window=30 : Days ahead to remind}';

    protected $description = 'Notify document owners when a governing document is due for review (Phase 9.6)';

    public function handle(NotificationDispatcher $dispatcher): int
    {
        $count = 0;

        Document::withoutGlobalScopes()
            ->reviewDue((int) $this->option('window'))
            ->whereNotNull('owner_id')
            ->with('owner')
            ->each(function (Document $document) use ($dispatcher, &$count) {
                if (! $document->owner) {
                    return;
                }

                $dispatcher->send(
                    $document->owner,
                    'document.review-due',
                    new DocumentReviewDueNotification($document),
                );

                $count++;
            });

        $this->info("{$count} review reminders dispatched.");

        return self::SUCCESS;
    }
}
