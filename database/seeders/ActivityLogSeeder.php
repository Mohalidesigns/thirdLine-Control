<?php

namespace Database\Seeders;

use App\Jobs\WriteAuditRecord;
use App\Models\Control;
use App\Models\ControlException;
use App\Models\Tenant;
use App\Models\User;
use App\Support\AuditEventCatalog;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Demo rows for Settings → Activity Log (CR3): at least one entry per
 * event class (auth success/failure, denied, CRUD, workflow, escalation,
 * evidence, Speak Up screening, integration, export) so the page can be
 * reviewed with realistic data. Writes run through WriteAuditRecord
 * synchronously so the hash chain is built exactly as in production.
 *
 * Note the Speak Up rows: deliberately anonymous — no user, IP, agent or
 * device — because the activity log must never be the back door that
 * identifies a reporter.
 */
class ActivityLogSeeder extends Seeder
{
    public function run(): void
    {
        $tenant = Tenant::first();
        if (! $tenant) {
            $this->command?->warn('ActivityLogSeeder skipped — no tenant seeded.');

            return;
        }

        $users = User::where('tenant_id', $tenant->id)->limit(4)->get();
        if ($users->isEmpty()) {
            $this->command?->warn('ActivityLogSeeder skipped — no users seeded.');

            return;
        }

        $control = Control::where('tenant_id', $tenant->id)->first();
        $exception = ControlException::where('tenant_id', $tenant->id)->first();

        $ua = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0 Safari/537.36';
        $admin = $users->first();
        $officer = $users->count() > 1 ? $users[1] : $admin;

        $when = now()->subDays(7);
        $rows = [];

        $base = fn (User $actor, string $event, array $extra) => array_merge([
            'tenant_id' => $tenant->id,
            'user_id' => $actor->id,
            'actor_name' => $actor->name,
            'actor_email' => $actor->email,
            'action' => $event,
            'event_label' => AuditEventCatalog::label($event),
            'entity_type' => 'system',
            'entity_id' => 0,
            'ip_address' => '10.40.12.'.rand(2, 250),
            'user_agent' => $ua,
            'device_name' => 'Chrome on macOS',
            'method' => 'POST',
            'batch_id' => (string) Str::uuid(),
        ], $extra);

        // ── Auth ────────────────────────────────────────────────────────
        $rows[] = $base($admin, 'login', [
            'entity_type' => 'auth', 'description' => 'User signed in',
            'url' => url('/login'), 'route_name' => 'login', 'status_code' => 302,
        ]);
        $rows[] = [
            'tenant_id' => $tenant->id, 'action' => 'login_failed',
            'event_label' => AuditEventCatalog::label('login_failed'),
            'actor_email' => 'unknown.person@example.com',
            'entity_type' => 'auth', 'entity_id' => 0,
            'description' => 'Login attempt failed for unknown.person@example.com',
            'after' => ['identifier' => 'unknown.person@example.com'],
            'ip_address' => '197.210.55.13', 'user_agent' => $ua,
            'device_name' => 'Chrome on macOS', 'method' => 'POST',
            'url' => url('/login'), 'status_code' => 302,
            'batch_id' => (string) Str::uuid(),
        ];
        $rows[] = $base($officer, 'logout', [
            'entity_type' => 'auth', 'description' => 'User signed out', 'status_code' => 302,
        ]);
        $rows[] = $base($officer, 'denied', [
            'entity_type' => 'route', 'description' => 'Access denied: POST /admin/users',
            'after' => ['method' => 'POST', 'path' => 'admin/users'], 'status_code' => 403,
        ]);

        // ── CRUD & workflow on real records where present ───────────────
        if ($control) {
            $rows[] = $base($officer, 'updated', [
                'entity_type' => $control->getMorphClass(), 'entity_id' => $control->id,
                'subject_label' => $control->name ?? $control->control_ref,
                'description' => 'Control updated',
                'before' => ['frequency' => 'Monthly'], 'after' => ['frequency' => 'Weekly'],
                'url' => url("/controls/{$control->id}"), 'method' => 'PUT', 'status_code' => 302,
            ]);
            $rows[] = $base($admin, 'approved', [
                'entity_type' => $control->getMorphClass(), 'entity_id' => $control->id,
                'subject_label' => $control->name ?? $control->control_ref,
                'description' => 'Control approved', 'status_code' => 302,
            ]);
            $rows[] = $base($admin, 'owner_reassigned', [
                'entity_type' => $control->getMorphClass(), 'entity_id' => $control->id,
                'subject_label' => $control->name ?? $control->control_ref,
                'description' => 'Control owner reassigned',
                'before' => ['owner_id' => $officer->id], 'after' => ['owner_id' => $admin->id],
                'status_code' => 302,
            ]);
        }

        if ($exception) {
            $rows[] = $base($admin, 'closed', [
                'entity_type' => $exception->getMorphClass(), 'entity_id' => $exception->id,
                'subject_label' => $exception->reference ?? "Exception #{$exception->id}",
                'description' => 'Control Exception closed',
                'after' => [
                    'verification_method' => 'Re-performance',
                    'verified_by' => $admin->id,
                    'verified_by_name' => $admin->name,
                ],
                'status_code' => 302,
            ]);
            $rows[] = $base($admin, 'issued', [
                'entity_type' => 'App\\Models\\FindingEscalation', 'entity_id' => 1,
                'subject_label' => 'Overdue remediation — round 2',
                'description' => 'Escalation issued to department head',
                'after' => ['round' => 2], 'status_code' => 302,
            ]);
        }

        // ── Evidence, Speak Up, integration, platform ───────────────────
        $rows[] = $base($officer, 'uploaded_chunked', [
            'entity_type' => 'App\\Models\\Evidence', 'entity_id' => 1,
            'subject_label' => 'Q2-cash-recon.xlsx',
            'description' => 'Evidence uploaded',
            'after' => ['file' => 'Q2-cash-recon.xlsx', 'size_bytes' => 148_202], 'status_code' => 302,
        ]);
        // Anonymous Speak Up screening — no actor, IP or device, ever.
        $rows[] = [
            'tenant_id' => $tenant->id, 'action' => 'created',
            'event_label' => AuditEventCatalog::label('created'),
            'entity_type' => 'App\\Models\\SpeakUpCase', 'entity_id' => 999,
            'subject_label' => 'Speak Up submission',
            'description' => 'Investigation Case created',
            'batch_id' => (string) Str::uuid(),
        ];
        $rows[] = $base($admin, 'received-from-integration', [
            'entity_type' => 'App\\Models\\AssuranceActivity', 'entity_id' => 1,
            'subject_label' => 'ThirdLine finding TL-2026-014',
            'description' => 'Received from ThirdLine',
            'after' => ['correlation_id' => (string) Str::uuid(), 'result' => 'success'],
            'status_code' => 200,
        ]);
        $rows[] = $base($admin, 'audit_log_exported', [
            'entity_type' => $admin->getMorphClass(), 'entity_id' => $admin->id,
            'subject_label' => $admin->name,
            'description' => 'Activity log exported',
            'after' => ['format' => 'csv', 'filters' => ['event' => 'login']],
            'method' => 'GET', 'status_code' => 200,
        ]);

        foreach ($rows as $row) {
            $row['created_at'] = $when->addMinutes(rand(30, 600))->format('Y-m-d H:i:s');
            (new WriteAuditRecord($row))->handle();
        }

        $this->command?->info('Activity log demo rows written: '.count($rows));
    }
}
