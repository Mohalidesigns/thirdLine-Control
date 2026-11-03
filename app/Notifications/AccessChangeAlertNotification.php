<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;

/**
 * Security alert to the other System Administrators when someone is made
 * an administrator or a role's permissions change — access changes that
 * should never go unseen.
 */
class AccessChangeAlertNotification extends PreferenceRoutedNotification
{
    public const ADMIN_GRANTED = 'admin_granted';

    public const ROLE_PERMISSIONS_CHANGED = 'role_permissions_changed';

    /** @param  array<int, string>  $permissions */
    public function __construct(
        public string $kind,
        public string $actorName,
        public string $subject,
        public array $permissions = [],
    ) {}

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)->subject($this->headline())->error();

        $mail = $this->kind === self::ADMIN_GRANTED
            ? $mail->line("{$this->actorName} made {$this->subject} a System Administrator.")
            : $mail->line("{$this->actorName} changed the permissions of the '{$this->subject}' role. It now holds "
                .count($this->permissions).' permission(s).');

        return $mail
            ->line('If this change was not expected, reverse it and review the audit log.')
            ->action('Open roles & permissions', url(route('admin.roles', [], false)));
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'access_'.$this->kind,
            'severity' => 'high',
            'title' => $this->headline(),
            'url' => route('admin.roles'),
            'summary' => $this->headline().' — by '.$this->actorName,
        ];
    }

    private function headline(): string
    {
        return $this->kind === self::ADMIN_GRANTED
            ? "Security alert — {$this->subject} made a System Administrator"
            : "Security alert — '{$this->subject}' role permissions changed";
    }
}
