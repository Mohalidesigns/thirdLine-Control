<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Backup email OTP for an MFA challenge. Deliberately NOT preference-routed
 * or queued lazily — a login is waiting on it.
 */
class MfaEmailOtpNotification extends Notification
{
    use Queueable;

    public function __construct(
        public string $otp,
        public int $ttlMinutes,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Your sign-in code')
            ->line("Your one-time sign-in code is: **{$this->otp}**")
            ->line("It expires in {$this->ttlMinutes} minutes. If you did not request it, ignore this email and report it to your administrator.");
    }
}
