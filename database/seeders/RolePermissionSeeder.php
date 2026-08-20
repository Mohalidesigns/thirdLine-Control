<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $permissions = [
            // Control library
            'view controls', 'create controls', 'edit controls', 'approve controls', 'retire controls',
            // Risk register
            'view risks', 'create risks', 'edit risks', 'map risks',
            // Testing
            'view tests', 'execute tests', 'review tests', 'build test-scripts', 'approve test-scripts',
            'rate controls', 'approve ratings',
            // Exceptions
            'view exceptions', 'create exceptions', 'remediate exceptions', 'close exceptions',
            'approve extensions', 'accept risk',
            // Compensating controls
            'view compensating-controls', 'create compensating-controls', 'approve compensating-controls',
            // Spot checks
            'view spot-checks', 'conduct spot-checks',
            // Reporting
            'view dashboards', 'export reports',
            // Administration
            'manage settings', 'manage users', 'manage escalations',
            // Platform foundations (Phase 7)
            'manage sso', 'view audit log', 'export audit log',
            // Frameworks & regulatory obligations (Phase 8)
            'view frameworks', 'manage frameworks', 'map controls', 'approve control-mappings',
            'view obligations', 'manage obligations', 'assign obligations', 'submit obligations',
            'approve obligation-submissions', 'waive obligations',
            'view regulatory-changes', 'assess regulatory-changes', 'action regulatory-changes',
            'install content-packs',
            // Control library v2, CSA & surveys (Phase 9)
            'view distributions', 'distribute controls', 'manage distributions',
            'view campaigns', 'manage campaigns', 'respond campaigns', 'review campaigns',
            'view attestations', 'manage attestations',
            'view documents', 'create documents', 'approve documents', 'manage document-folders',
            'view improvements', 'create improvements', 'approve improvements', 'verify improvements',
            'import data',
            // Risk management v2 (Phase 10)
            'assess risks', 'review risk-assessments',
            'view appetite', 'manage appetite', 'approve appetite',
            'view treatments', 'create treatments', 'approve treatments', 'verify treatments',
            'view metrics', 'manage metrics', 'capture metrics', 'acknowledge breaches',
            'manage linkage',
            // Policy, incident, complaints & case management (Phase 11)
            'view policies', 'create policies', 'approve policies', 'publish policies',
            'request policy-exceptions', 'approve policy-exceptions',
            'view incidents', 'create incidents', 'investigate incidents', 'close incidents',
            'notify regulators',
            'view complaints', 'create complaints', 'handle complaints', 'close complaints',
            'export complaint-returns',
            'view cases', 'create cases', 'investigate cases', 'close cases',
            'view privileged-notes', 'report cases',
            // 'view all cases' is the named oversight override of the 11.4
            // allowlist: read-only sight of every case, every view logged.
            // It never confers investigation, note or access-management
            // rights — those still require allowlist membership — and it is
            // deliberately granted to the System Administrator alone.
            'view all cases',
            // Continuous controls monitoring & connectors (Phase 12)
            'view data-sources', 'manage data-sources', 'approve data-sources',
            'authorise pii-retention',
            'view monitoring', 'manage monitoring-rules', 'approve monitoring-rules',
            'review monitoring-findings',
            'view sod', 'manage sod-rules', 'accept sod-violations',
            // Dashboards, reporting & regulatory submissions (Phase 13)
            'manage dashboards', 'publish dashboards',
            'design reports', 'approve report-definitions', 'schedule reports',
            'view submissions', 'generate submissions', 'review submissions',
            'approve submissions', 'file submissions',
            // AI layer (Phase 14). 'use ai' is the door; the capability's own
            // domain permission is the gate behind it, so AI never widens
            // anyone's reach — drafting a control still needs 'create
            // controls'. 'manage ai' is separate from 'manage settings'
            // because turning the models on is a named authority an
            // organisation has to be able to show its own regulator.
            'use ai', 'manage ai', 'view ai-log', 'export ai-log',
            // Mobile, offline & omnichannel (Phase 15). One permission: the
            // template catalogue, the delivery log and channel cost tracking
            // are a single governance surface.
            'manage messaging',
            // Multi-entity, data residency & enterprise readiness (Phase 16).
            // 'grant entity-access' is separate from 'manage entities'
            // because who may see a subsidiary is an access-control act, and
            // holding a permission never substitutes for a grant (R2).
            'view entities', 'manage entities', 'grant entity-access',
            'view group-dashboard',
            'view residency', 'manage residency',
            'record transfers', 'authorise transfers',
            'manage soa',
            // Extended GRC (Phase 17).
            //
            // 'approve objectives' is separate from 'manage objectives'
            // because an objective entering the board's scorecard is the
            // board's act, not the author's (R2).
            'view objectives', 'manage objectives', 'approve objectives',
            'manage initiatives',
            // Third-party risk. 'approve vendor-assessments' is the
            // second-person gate on a due-diligence conclusion, and
            // 'notify vendor-regulators' is separate again because a
            // material-outsourcing notification is a filing with a
            // regulator, not an internal record.
            'view vendors', 'manage vendors', 'assess vendors',
            'approve vendor-assessments', 'manage vendor-contracts',
            'screen vendors', 'notify vendor-regulators',
            // Sustainability (IFRS S1/S2). Verifying GHG data is held apart
            // from preparing it: the preparer of a Scope 1 figure cannot be
            // the person who signs it off (R2).
            'view sustainability', 'manage sustainability',
            'approve materiality', 'prepare ghg-data', 'verify ghg-data',
            'manage sustainability-filings',
            // Combined assurance. Recording a reliance decision is the
            // named act a King IV/NCCG combined-assurance report rests on.
            'view assurance', 'manage assurance', 'record reliance',
            // Exception Manager (CR-01). Issuing, answering, reviewing and
            // withdrawing are four different hands by design: the control
            // function escalates and reviews, the department responds, and
            // configuration confers no part in the loop itself.
            'escalate exceptions', 'respond exceptions', 'review exception-responses',
            'withdraw exceptions', 'configure exception-routing',
            // Internal control structure (CR2-A). Structure administration
            // ('manage control-structure') is deliberately separate from
            // control assignment ('attach control-entities') and from
            // naming stakeholders ('manage control-stakeholders') — holding
            // the platform keys is not the same as deciding which controls
            // a branch runs.
            'view control-structure', 'manage control-structure',
            'attach control-entities', 'manage control-stakeholders',
            // Speak Up reporter metadata (CR). Dotted names on purpose —
            // they mirror the CR's specification and read as one family.
            // Tier 1 ('view_basic') is device/geo-coarse signals only;
            // Tier 2 opens on a break-glass request ('request_reveal')
            // approved by a second person ('approve_reveal'); the
            // immutable access log has its own read permission
            // ('audit_log') because who watched the watchers is an
            // assurance question, not an investigation one.
            'speak_up.metadata.view_basic', 'speak_up.metadata.request_reveal',
            'speak_up.metadata.approve_reveal', 'speak_up.metadata.audit_log',
        ];

        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        $all = Permission::pluck('name')->toArray();

        // BRD §4 roles. Two second-line tiers, first-line owner/manager,
        // read-only executive, and the system administrator.
        //
        // CR-01: the System Administrator configures exception routing and
        // SLA but takes NO part in the loop — no issuing, answering,
        // reviewing or withdrawing. Holding the platform keys must never
        // put anyone inside a segregation-of-duties chain (R2).
        Role::findOrCreate('System Administrator', 'web')->syncPermissions(array_diff($all, [
            'escalate exceptions', 'respond exceptions',
            'review exception-responses', 'withdraw exceptions',
            // CR2-A: structure admin, yes; control assignment, no.
            'attach control-entities', 'manage control-stakeholders',
            // CR (Speak Up metadata): holding the platform keys never opens
            // a reporter's identity. The administrator keeps sight of the
            // access log — oversight is sight — but takes no part in the
            // reveal loop and sees no metadata tier.
            'speak_up.metadata.view_basic', 'speak_up.metadata.request_reveal',
            'speak_up.metadata.approve_reveal',
        ]));

        // Installing regulatory content packs changes platform-wide data, so
        // it stays with the System Administrator alongside the other
        // platform-level permissions.
        //
        // 'authorise pii-retention' sits here because in most Nigerian
        // institutions the control function head is the officer closest to
        // the DPO mandate. Where a tenant has a separate Data Protection
        // Officer, create a role holding only that permission and remove it
        // from this list — the platform enforces the permission, not the job
        // title (R1).
        Role::findOrCreate('Control Function Head', 'web')->syncPermissions(array_diff($all, [
            'manage users', 'manage settings', 'manage sso', 'install content-packs',
            // Case oversight stays with the System Administrator; the head
            // of control reaches a case the same way everyone else does —
            // by being named on it (11.4).
            'view all cases',
        ]));

        Role::findOrCreate('Control Officer', 'web')->syncPermissions([
            'view controls', 'create controls', 'edit controls',
            'view risks', 'create risks', 'edit risks', 'map risks',
            'view tests', 'execute tests', 'build test-scripts', 'rate controls',
            'view exceptions', 'create exceptions', 'remediate exceptions',
            // CR-01: second line issues, reviews and withdraws departmental
            // escalations. Accepting a response is a closure act and needs
            // 'close exceptions' (R-C), which this role does not hold — an
            // officer can reject, never accept.
            'escalate exceptions', 'review exception-responses', 'withdraw exceptions',
            'view compensating-controls', 'create compensating-controls',
            'view spot-checks', 'conduct spot-checks',
            'view dashboards', 'export reports',
            'view frameworks', 'map controls',
            'view obligations', 'submit obligations',
            'view regulatory-changes', 'assess regulatory-changes',
            'view distributions', 'distribute controls',
            'view campaigns', 'manage campaigns', 'respond campaigns', 'review campaigns',
            'view attestations',
            'view documents', 'create documents', 'manage document-folders',
            'view improvements', 'create improvements',
            // Second line assesses risk and runs the KRI engine; publishing a
            // high-scoring assessment stays with the Control Function Head.
            'assess risks', 'view appetite',
            'view treatments', 'create treatments',
            'view metrics', 'manage metrics', 'capture metrics', 'acknowledge breaches',
            'manage linkage',
            // Second line authors policy and runs incident, complaint and
            // case handling; publishing a policy and closing an incident or
            // complaint stay with the Control Function Head. Case permissions
            // are necessary but never sufficient — the allowlist still gates
            // every individual case (11.4).
            'view policies', 'create policies', 'request policy-exceptions',
            'view incidents', 'create incidents', 'investigate incidents', 'notify regulators',
            'view complaints', 'create complaints', 'handle complaints', 'export complaint-returns',
            'view cases', 'create cases', 'investigate cases',
            // CR (Speak Up metadata): an officer on a case reads Tier 1
            // signals and may ask for a Tier 2 reveal; approving one is a
            // second-person act that stays with the Control Function Head
            // (and any tenant-defined approver role) — an officer can
            // request, never grant (R2).
            'speak_up.metadata.view_basic', 'speak_up.metadata.request_reveal',
            // Second line builds and tunes continuous monitoring rules and
            // maintains the toxic-combination matrix; approving a rule into
            // production and accepting a live SoD conflict both stay with
            // the Control Function Head (12.3 business rule, R2).
            'view data-sources',
            'view monitoring', 'manage monitoring-rules', 'review monitoring-findings',
            'view sod', 'manage sod-rules',
            // Second line composes dashboards and designs reports, and both
            // generates and reviews regulatory submission packs — but never
            // the same pack twice: approving and filing stay with the Control
            // Function Head, and the service refuses a second act by the same
            // person regardless of role (R2).
            'manage dashboards', 'design reports', 'schedule reports',
            'view submissions', 'generate submissions', 'review submissions',
            // Second line is the heaviest AI user: drafting controls,
            // triaging exceptions, parsing circulars. Every one of those is
            // still gated on the domain permission listed above — 'use ai'
            // opens the door, it does not widen the room. Governance of the
            // models themselves stays with the Control Function Head.
            'use ai',
            // Second line reads the entity register and the group position
            // (still gated per entity by explicit grants), records
            // cross-border transfers and maintains SoA decisions.
            // Authorising a transfer and granting entity access stay with
            // the Control Function Head (R2).
            'view entities', 'view group-dashboard',
            'view residency', 'record transfers',
            'manage soa',
            // Phase 17. Second line authors the objective register and runs
            // third-party due diligence, sustainability preparation and the
            // assurance map. Every second-person gate above it stays with
            // the Control Function Head: approving an objective onto the
            // scorecard, approving a due-diligence conclusion, notifying a
            // regulator of a material outsourcing, approving a materiality
            // determination, verifying GHG data it prepared, and recording
            // a reliance decision (R2).
            'view objectives', 'manage objectives', 'manage initiatives',
            'view vendors', 'manage vendors', 'assess vendors',
            'manage vendor-contracts', 'screen vendors',
            'view sustainability', 'manage sustainability', 'prepare ghg-data',
            'manage sustainability-filings',
            'view assurance', 'manage assurance',
            // CR2-A: the second line runs the control universe end to end.
            'view control-structure', 'manage control-structure',
            'attach control-entities', 'manage control-stakeholders',
        ]);

        Role::findOrCreate('Control Owner', 'web')->syncPermissions([
            'view controls', 'view exceptions', 'remediate exceptions',
            // CR-01: first line answers what is addressed to it.
            'respond exceptions',
            'view compensating-controls', 'create compensating-controls',
            'view dashboards',
            'view frameworks', 'view obligations', 'submit obligations',
            'view distributions', 'respond campaigns',
            'view documents', 'view improvements', 'create improvements',
            'view treatments', 'create treatments',
            'view metrics', 'capture metrics',
            // First line reads policy, asks for waivers, and reports what
            // went wrong — reporting an incident is never gated on seniority.
            'view policies', 'request policy-exceptions',
            'view incidents', 'create incidents',
            'view complaints', 'create complaints',
            // A control owner sees the continuous results on their own
            // controls; reviewing a finding is a second-line decision.
            'view monitoring',
            // A private board of one's own controls and actions. Sharing it
            // any wider needs 'publish dashboards', which this role lacks.
            'manage dashboards',
            // First line gets Atlas and exception triage. Because retrieval
            // is filtered to the user's own permissions, an owner asking
            // Atlas a question can only ever be answered from records they
            // could already open.
            'use ai',
            // First line owns initiatives and vendor relationships in
            // practice, so it reads the strategy register and the third
            // party register and progresses the initiatives it owns. The
            // policies additionally allow an owner to report on their own
            // objective without holding 'manage objectives'.
            'view objectives', 'manage initiatives',
            'view vendors',
            // Preparing a GHG figure is a first-line act — the business
            // unit holds the meter readings. Verifying it is not.
            'view sustainability', 'prepare ghg-data',
            // CR2-A: first line sees where its controls sit in the
            // structure; it never reshapes the universe.
            'view control-structure',
        ]);

        Role::findOrCreate('Line Manager', 'web')->syncPermissions([
            'view controls', 'view risks', 'view tests', 'view exceptions',
            // CR-01: a line manager answers an escalation addressed to their
            // department.
            'respond exceptions',
            'view compensating-controls', 'view spot-checks', 'view dashboards',
            'view frameworks', 'view obligations', 'view regulatory-changes',
            'view distributions', 'respond campaigns',
            'view documents', 'view improvements',
            'view appetite', 'view treatments', 'view metrics',
            'view policies', 'request policy-exceptions',
            'view incidents', 'create incidents', 'view complaints',
            'view monitoring', 'view sod',
            'manage dashboards',
            'use ai',
            // A line manager sees the entity register and, where granted,
            // the group position for their own entities.
            'view entities', 'view group-dashboard',
            // Read across the extended-GRC registers; authors none of them.
            'view objectives', 'view vendors', 'view sustainability', 'view assurance',
            // CR2-A: a line manager reads the structure their department
            // appears in.
            'view control-structure',
        ]);

        Role::findOrCreate('Executive Viewer', 'web')->syncPermissions([
            'view controls', 'view risks', 'view tests', 'view exceptions',
            'view compensating-controls', 'view spot-checks', 'view dashboards', 'export reports',
            'view frameworks', 'view obligations', 'view regulatory-changes',
            'view distributions', 'view campaigns', 'view attestations',
            'view documents', 'view improvements',
            // Appetite is a board artefact: the executive tier reads the
            // register and approves the statement, but authors nothing.
            'view appetite', 'approve appetite',
            'view treatments', 'view metrics',
            // The board tier reads policy and the incident/complaint position,
            // and takes the aggregate case extract. It never gets case detail:
            // 'report cases' returns counts and outcomes only, and 'view cases'
            // still yields nothing without allowlist membership (11.4).
            'view policies', 'view incidents', 'view complaints',
            'view cases', 'report cases',
            // The board tier reads the monitoring position — coverage, open
            // findings, SoD exposure — and authors none of it.
            'view monitoring', 'view sod',
            // The board reads what was filed with the regulator and keeps a
            // private board of its own. It never generates, approves or
            // files a submission — that is management's act, not the board's.
            'manage dashboards', 'view submissions',
            // The board tier holds 'use ai', which in practice means Atlas
            // and nothing else: every other capability additionally requires
            // a create or edit permission this role does not have, so those
            // assist affordances never appear for it. And because retrieval
            // is permission-filtered per user, an Atlas answer for this role
            // is built only from records it could already open.
            'use ai',
            // The board reads the group position and the residency
            // attestation register — the artefacts it is asked to sign off
            // — and authors none of it. Sight of a subsidiary still needs
            // an explicit per-entity grant.
            'view entities', 'view group-dashboard', 'view residency',
            // Phase 17: the strategy map, the scorecard and the combined
            // assurance map are board artefacts. The executive tier reads
            // them and approves an objective onto the scorecard — which it
            // can only ever do for an objective somebody else drafted (R2)
            // — but authors nothing and never runs due diligence.
            'view objectives', 'approve objectives',
            'view vendors', 'view sustainability', 'view assurance',
        ]);

        // CR (Speak Up metadata): a standalone reveal-approver role, so a
        // tenant can name second-person approvers — a DPO, legal counsel —
        // without handing them the Control Function Head's reach. The
        // service still refuses a self-approval whatever roles someone
        // holds; and note the role carries no case permissions at all:
        // deciding a reveal is an authority over the metadata vault, not a
        // seat on the investigation.
        Role::findOrCreate('Speak Up Reveal Approver', 'web')->syncPermissions([
            'speak_up.metadata.view_basic',
            'speak_up.metadata.approve_reveal',
            'speak_up.metadata.audit_log',
        ]);
    }
}
