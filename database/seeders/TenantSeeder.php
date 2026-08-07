<?php

namespace Database\Seeders;

use App\Models\BusinessProcess;
use App\Models\EscalationMatrix;
use App\Models\OrganisationUnit;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Seeder;

class TenantSeeder extends Seeder
{
    public function run(): void
    {
        $tenant = Tenant::firstOrCreate(
            ['name' => 'Demo Bank Plc'],
            ['data_residency' => 'NG', 'status' => 'active'],
        );

        // ── Organisational hierarchy ─────────────────────────────────
        $headOffice = OrganisationUnit::withoutGlobalScopes()->firstOrCreate(
            ['tenant_id' => $tenant->id, 'name' => 'Head Office', 'type' => 'Head Office'],
            ['code' => 'HO'],
        );

        $units = [];
        foreach ([
            ['Operations', 'Department', 'OPS'],
            ['Internal Control', 'Department', 'ICU'],
            ['Information Technology', 'Department', 'IT'],
            ['Treasury', 'Department', 'TRS'],
            ['Marina Branch', 'Branch', 'BR-001'],
            ['Ikeja Branch', 'Branch', 'BR-002'],
        ] as [$name, $type, $code]) {
            $units[$name] = OrganisationUnit::withoutGlobalScopes()->firstOrCreate(
                ['tenant_id' => $tenant->id, 'name' => $name, 'type' => $type],
                ['code' => $code, 'parent_id' => $headOffice->id],
            );
        }

        // ── Users (roles must already exist) ─────────────────────────
        $users = [
            ['Adaeze Okafor', 'admin@secondline.test', 'System Administrator', 'Head, IT Governance', 'Internal Control'],
            ['Babatunde Adewale', 'cfh@secondline.test', 'Control Function Head', 'Chief Control Officer', 'Internal Control'],
            ['Chiamaka Eze', 'officer1@secondline.test', 'Control Officer', 'Senior Control Officer', 'Internal Control'],
            ['Damilola Ajayi', 'officer2@secondline.test', 'Control Officer', 'Control Officer', 'Internal Control'],
            ['Emeka Nwosu', 'owner1@secondline.test', 'Control Owner', 'Head of Operations', 'Operations'],
            ['Folake Balogun', 'owner2@secondline.test', 'Control Owner', 'Branch Manager, Marina', 'Marina Branch'],
            ['Gbenga Oyelaran', 'manager@secondline.test', 'Line Manager', 'Divisional Head, Retail', 'Head Office'],
            ['Hauwa Ibrahim', 'exec@secondline.test', 'Executive Viewer', 'Executive Director, Risk', 'Head Office'],
        ];

        $created = [];
        foreach ($users as [$name, $email, $role, $position, $unitName]) {
            $user = User::withoutGlobalScopes()->firstOrCreate(
                ['email' => $email],
                [
                    'tenant_id' => $tenant->id,
                    'name' => $name,
                    'password' => 'password',
                    'position' => $position,
                    'unit_id' => ($units[$unitName] ?? $headOffice)->id,
                    'email_verified_at' => now(),
                    'is_active' => true,
                ],
            );
            $user->syncRoles([$role]);
            $created[$email] = $user;
        }

        // Reporting lines: owners and officers report to their function heads.
        $created['owner1@secondline.test']->update(['reports_to' => $created['manager@secondline.test']->id]);
        $created['owner2@secondline.test']->update(['reports_to' => $created['manager@secondline.test']->id]);
        $created['officer1@secondline.test']->update(['reports_to' => $created['cfh@secondline.test']->id]);
        $created['officer2@secondline.test']->update(['reports_to' => $created['cfh@secondline.test']->id]);

        // Unit heads.
        $units['Operations']->update(['head_user_id' => $created['owner1@secondline.test']->id]);
        $units['Internal Control']->update(['head_user_id' => $created['cfh@secondline.test']->id]);

        // ── Business processes ───────────────────────────────────────
        foreach ([
            ['CASH-MGT', 'Cash Management', 'Operations'],
            ['PAY-PROC', 'Payment Processing', 'Operations'],
            ['ACC-OPEN', 'Account Opening & KYC', 'Operations'],
            ['IT-CHG', 'IT Change Management', 'Information Technology'],
            ['TRS-DEAL', 'Treasury Dealing', 'Treasury'],
            ['RECON', 'Reconciliations', 'Operations'],
        ] as [$code, $name, $unitName]) {
            BusinessProcess::withoutGlobalScopes()->firstOrCreate(
                ['tenant_id' => $tenant->id, 'code' => $code],
                ['name' => $name, 'unit_id' => $units[$unitName]->id],
            );
        }

        // ── Default escalation matrix (FR-8.1, FR-8.4) ───────────────
        $matrix = [
            // severity, tier, trigger, days, recipient role
            ['Critical', 1, 'exception_overdue', 0, 'Control Owner'],
            ['Critical', 2, 'exception_overdue', 2, 'Line Manager'],
            ['Critical', 3, 'exception_overdue', 5, 'Control Function Head'],
            ['Critical', 4, 'exception_overdue', 10, 'Executive Viewer'],
            ['Critical', 1, 'exception_unassigned', 1, 'Control Function Head'],
            ['High', 1, 'exception_overdue', 0, 'Control Owner'],
            ['High', 2, 'exception_overdue', 7, 'Line Manager'],
            ['High', 3, 'exception_overdue', 14, 'Control Function Head'],
            ['High', 1, 'exception_unassigned', 3, 'Control Function Head'],
            ['Medium', 1, 'exception_overdue', 7, 'Control Owner'],
            ['Medium', 2, 'exception_overdue', 21, 'Line Manager'],
            ['Low', 1, 'exception_overdue', 14, 'Control Owner'],
            ['Critical', 1, 'test_overdue', 1, 'Control Function Head'],
            ['High', 1, 'test_overdue', 3, 'Control Function Head'],
            ['Medium', 1, 'test_overdue', 7, 'Control Function Head'],
            ['Low', 1, 'test_overdue', 14, 'Control Function Head'],
        ];

        foreach ($matrix as [$severity, $tier, $trigger, $days, $role]) {
            EscalationMatrix::withoutGlobalScopes()->firstOrCreate([
                'tenant_id' => $tenant->id,
                'severity' => $severity,
                'tier_no' => $tier,
                'trigger_condition' => $trigger,
                'recipient_role' => $role,
            ], [
                'days_threshold' => $days,
                'channel' => 'both',
                'is_active' => true,
            ]);
        }
    }
}
