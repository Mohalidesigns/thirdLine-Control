<?php

namespace App\Services;

use App\Models\Tenant;
use App\Models\User;
use App\Notifications\MfaEmailOtpNotification;
use App\Notifications\MfaPolicyChangedNotification;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PragmaRX\Google2FA\Google2FA;

class MfaService
{
    public const RECOVERY_CODE_COUNT = 8;

    private const EMAIL_OTP_TTL_MINUTES = 10;

    public function __construct(
        private Google2FA $google2fa,
        private NotificationDispatcher $dispatcher,
    ) {}

    /**
     * Begin enrolment: store a fresh (unconfirmed) secret and return the
     * otpauth URI + inline QR SVG for the authenticator app.
     */
    public function beginEnrolment(User $user): array
    {
        // Reuse a pending (unconfirmed) secret so refreshing the page
        // doesn't invalidate the QR the user already scanned.
        $secret = ($user->mfa_secret && ! $user->mfa_confirmed_at)
            ? $user->mfa_secret
            : $this->google2fa->generateSecretKey(32);

        $user->forceFill(['mfa_secret' => $secret, 'mfa_confirmed_at' => null])->save();

        $uri = $this->google2fa->getQRCodeUrl(
            config('app.name', 'SecondLine'),
            $user->email,
            $secret,
        );

        return ['secret' => $secret, 'otpauth_uri' => $uri, 'qr_svg' => $this->qrSvg($uri)];
    }

    /**
     * Confirm enrolment with a live TOTP code. Returns the plain-text
     * recovery codes — shown exactly once, stored hashed.
     */
    public function confirmEnrolment(User $user, string $code): array
    {
        if (! $user->mfa_secret || ! $this->verifyTotp($user, $code)) {
            throw ValidationException::withMessages(['code' => 'That code is not valid. Check your authenticator app and try again.']);
        }

        $plainCodes = collect(range(1, self::RECOVERY_CODE_COUNT))
            ->map(fn () => Str::upper(Str::random(5).'-'.Str::random(5)));

        $user->forceFill([
            'mfa_confirmed_at' => now(),
            'mfa_recovery_codes' => $plainCodes->map(fn ($c) => Hash::make($c))->all(),
        ])->save();

        $user->auditAction('mfa_enabled');

        return $plainCodes->all();
    }

    /**
     * Verify a challenge: a TOTP code, a single-use recovery code, or an
     * email OTP requested as backup.
     */
    public function verifyChallenge(User $user, string $code): bool
    {
        if ($this->verifyTotp($user, $code)) {
            return true;
        }

        if ($this->consumeRecoveryCode($user, $code)) {
            $user->auditAction('mfa_recovery_code_used');

            return true;
        }

        return $this->consumeEmailOtp($user, $code);
    }

    public function sendEmailOtp(User $user): void
    {
        $otp = (string) random_int(100000, 999999);

        Cache::put($this->emailOtpKey($user), Hash::make($otp), now()->addMinutes(self::EMAIL_OTP_TTL_MINUTES));

        $user->notify(new MfaEmailOtpNotification($otp, self::EMAIL_OTP_TTL_MINUTES));
    }

    public function disable(User $user): void
    {
        $user->forceFill([
            'mfa_secret' => null,
            'mfa_confirmed_at' => null,
            'mfa_recovery_codes' => null,
        ])->save();

        $user->auditAction('mfa_disabled');
    }

    /** Whether the tenant's policy makes MFA mandatory for this user. */
    public function isEnforcedFor(User $user): bool
    {
        $roles = $user->tenant?->mfa_enforced_roles ?? [];

        return $roles !== [] && $user->hasAnyRole($roles);
    }

    /**
     * Past the grace period an enforced user without MFA is hard-blocked.
     * Grace runs from the later of policy activation and account creation.
     */
    public function isPastGracePeriod(User $user): bool
    {
        $tenant = $user->tenant;

        if (! $tenant || ! $this->isEnforcedFor($user)) {
            return false;
        }

        $anchor = collect([$tenant->mfa_enforced_at, $user->created_at])->filter()->max();

        return $anchor !== null
            && now()->greaterThan($anchor->copy()->addDays($tenant->mfa_grace_period_days ?? 7));
    }

    /**
     * Update the tenant MFA policy. Removing a role writes a high-severity
     * audit event and notifies every System Administrator (Phase 7 rule).
     */
    public function updatePolicy(Tenant $tenant, array $enforcedRoles, int $gracePeriodDays, bool $smsOptIn): void
    {
        $before = $tenant->mfa_enforced_roles ?? [];
        $removed = array_values(array_diff($before, $enforcedRoles));

        $tenant->forceFill([
            'mfa_enforced_roles' => array_values($enforcedRoles),
            'mfa_grace_period_days' => $gracePeriodDays,
            'mfa_sms_opt_in' => $smsOptIn,
            'mfa_enforced_at' => $tenant->mfa_enforced_at ?? ($enforcedRoles !== [] ? now() : null),
        ])->save();

        if ($removed !== []) {
            $tenant->auditAction('mfa_enforcement_removed', ['roles' => $before], [
                'roles' => $enforcedRoles, 'removed' => $removed, 'severity' => 'high',
            ]);

            $admins = User::withoutGlobalScope('tenant')
                ->where('tenant_id', $tenant->id)
                ->where('is_active', true)
                ->role('System Administrator')
                ->get();

            $this->dispatcher->send($admins, 'security.mfa-policy-changed',
                new MfaPolicyChangedNotification($removed, auth()->user()?->name ?? 'system'));
        }
    }

    private function verifyTotp(User $user, string $code): bool
    {
        if (! $user->mfa_secret || ! preg_match('/^\d{6}$/', $code)) {
            return false;
        }

        return (bool) $this->google2fa->verifyKey($user->mfa_secret, $code);
    }

    private function consumeRecoveryCode(User $user, string $code): bool
    {
        $codes = $user->mfa_recovery_codes ?? [];

        foreach ($codes as $index => $hash) {
            if (Hash::check(strtoupper(trim($code)), $hash)) {
                unset($codes[$index]);
                $user->forceFill(['mfa_recovery_codes' => array_values($codes)])->save();

                return true;
            }
        }

        return false;
    }

    private function consumeEmailOtp(User $user, string $code): bool
    {
        $hash = Cache::get($this->emailOtpKey($user));

        if ($hash && Hash::check($code, $hash)) {
            Cache::forget($this->emailOtpKey($user));

            return true;
        }

        return false;
    }

    private function emailOtpKey(User $user): string
    {
        return "mfa:email-otp:{$user->id}";
    }

    private function qrSvg(string $uri): string
    {
        $renderer = new ImageRenderer(new RendererStyle(220), new SvgImageBackEnd);

        return (new Writer($renderer))->writeString($uri);
    }
}
