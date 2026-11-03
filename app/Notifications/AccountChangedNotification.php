<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;

/**
 * Tells a user that an administrator changed their access: roles,
 * suspension, reactivation, sign-in email or MFA. If they did not expect
 * it, this is how they find out.
 */
class AccountChangedNotification extends PreferenceRoutedNotification
{
    public const ROLES_CHANGED = 'roles_changed';

    public const SUSPENDED = 'suspended';

    public const REACTIVATED = 'reactivated';

    public const EMAIL_CHANGED = 'email_changed';

    public const MFA_RESET = 'mfa_reset';

    /** @param  array<int, string>  $roles */
    public function __construct(
        public string $kind,
        public string $actorName,
        public array $roles = [],
        public ?string $detail = null,
    ) {}

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)->subject($this->headline());

        $mail = match ($this->kind) {
            self::ROLES_CHANGED => $mail
                ->line("{$this->actorName} changed your roles. You now hold: ".(implode(', ', $this->roles) ?: 'no role').'.'),
            self::SUSPENDED => $mail
                ->line("{$this->actorName} suspended your account. You can no longer sign in."),
            self::REACTIVATED => $mail
                ->line("{$this->actorName} reactivated your account. You can sign in again."),
            self::EMAIL_CHANGED => $mail
                ->line("{$this->actorName} changed the sign-in email on your account to {$this->detail}."),
            default => $mail
                ->line("{$this->actorName} reset multi-factor authentication on your account.")
                ->line('You will be asked to set it up again the next time you sign in.'),
        };

        $mail = $mail->line('If you did not expect this change, contact your System Administrator straight away.');

        return $this->kind === self::SUSPENDED
            ? $mail
            : $mail->action('Open '.config('app.name'), url(route('dashboard', [], false)));
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'account_'.$this->kind,
            'title' => $this->headline(),
            'url' => route('dashboard'),
            'summary' => $this->headline().' — by '.$this->actorName,
        ];
    }

    private function headline(): string
    {
        return match ($this->kind) {
            self::ROLES_CHANGED => 'Your roles have changed',
            self::SUSPENDED => 'Your account has been suspended',
            self::REACTIVATED => 'Your account has been reactivated',
            self::EMAIL_CHANGED => 'Your sign-in email has changed',
            default => 'Your multi-factor authentication was reset',
        };
    }
}
