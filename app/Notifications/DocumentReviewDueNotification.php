<?php

namespace App\Notifications;

use App\Models\Document;
use Illuminate\Notifications\Messages\MailMessage;

/** A governing document is approaching (or past) its review date (9.6). */
class DocumentReviewDueNotification extends PreferenceRoutedNotification
{
    public array $resolvedChannels = ['database', 'mail'];

    public function __construct(public Document $document) {}

    public function toMail(object $notifiable): MailMessage
    {
        $due = $this->document->review_due_at?->format('d M Y') ?? '—';

        return (new MailMessage)
            ->subject("Document review due: {$this->document->title}")
            ->line("{$this->document->reference} — {$this->document->title} is due for review on {$due}.")
            ->line('Review the document, refresh its content if needed, and set the next review date.')
            ->action('Open document', url("/documents/{$this->document->id}"));
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'document_review_due',
            'document_id' => $this->document->id,
            'reference' => $this->document->reference,
            'title' => $this->document->title,
            'review_due_at' => $this->document->review_due_at?->toDateString(),
        ];
    }
}
