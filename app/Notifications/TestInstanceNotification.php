<?php

namespace App\Notifications;

use App\Models\TestInstance;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Hand-offs in a control test (FR-3.7/3.8, FR-7.6): the tester submits it
 * (to the reviewer), the reviewer returns or signs it off (back to the
 * tester), and a proposed effectiveness rating goes to the control
 * function heads to approve and, once published, back to its proposer.
 */
class TestInstanceNotification extends PreferenceRoutedNotification
{
    public const SUBMITTED = 'submitted';

    public const RETURNED = 'returned';

    public const SIGNED_OFF = 'signed_off';

    public const RATING_PENDING = 'rating_pending';

    public const RATING_PUBLISHED = 'rating_published';

    public function __construct(
        public TestInstance $instance,
        public string $kind,
        public string $actorName,
        public ?string $note = null,
    ) {}

    public function toMail(object $notifiable): MailMessage
    {
        $ref = $this->instance->reference;
        $rating = $this->instance->effectivenessRating?->overall_rating;

        $mail = (new MailMessage)->subject($this->headline());

        $mail = match ($this->kind) {
            self::SUBMITTED => $mail
                ->line("{$this->actorName} has submitted test {$ref} for your review.")
                ->line('Review the results and evidence, then sign it off or return it to the tester.'),
            self::RETURNED => $mail
                ->line("{$this->actorName} has returned test {$ref} to you.")
                ->line('Review notes: '.str($this->note)->limit(500))
                ->line('Make the changes and submit it again.'),
            self::SIGNED_OFF => $mail
                ->line("{$this->actorName} has signed off test {$ref}. It is now locked."),
            self::RATING_PENDING => $mail
                ->line("{$this->actorName} has proposed an effectiveness rating of {$rating} for test {$ref}.")
                ->line('Review the rationale, then approve it to publish the rating.'),
            default => $mail
                ->line("{$this->actorName} has approved the {$rating} effectiveness rating for test {$ref}. It is now published."),
        };

        return $mail
            ->line('Control: '.str($this->instance->control?->title)->limit(200))
            ->action('Open the test', url(route('test-instances.show', $this->instance->id, false)));
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'test_'.$this->kind,
            'title' => $this->headline(),
            'test_instance_id' => $this->instance->id,
            'reference' => $this->instance->reference,
            'url' => route('test-instances.show', $this->instance->id),
            'summary' => $this->headline().': '.str($this->instance->control?->title)->limit(120),
        ];
    }

    private function headline(): string
    {
        $ref = $this->instance->reference;

        return match ($this->kind) {
            self::SUBMITTED => "Test awaiting your review — {$ref}",
            self::RETURNED => "Test returned to you — {$ref}",
            self::SIGNED_OFF => "Test signed off — {$ref}",
            self::RATING_PENDING => "Effectiveness rating awaiting your approval — {$ref}",
            default => "Effectiveness rating published — {$ref}",
        };
    }
}
