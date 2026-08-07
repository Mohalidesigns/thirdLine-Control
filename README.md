# SecondLine — Atheris Control Solution

A second-line-of-defence platform for banks and fintechs: define, test, rate, and monitor internal controls, and track exceptions through to **verified closure**. Sibling product to ThirdLine (Internal Audit), built on the same [ThirdLine Development Standard](DEVELOPMENT_STANDARD.md).

- **Stack:** Laravel 13 · Inertia.js · React 18 · Tailwind 3 · Spatie Permission · Ziggy · MySQL
- **Design system:** AEGIS — Navy `#0B1F3A`, Gold `#C9A227` (tokens in `resources/css/app.css`)
- **BRD:** [Control-Solution-BRD-v1.0.md](Control-Solution-BRD-v1.0.md)

## Getting started

```bash
composer setup          # install, .env, key, migrate, npm install, build
php artisan migrate:fresh --seed
composer dev            # server + queue + logs + Vite
```

Demo logins (password: `password`):

| Email | Role |
|---|---|
| `admin@secondline.test` | System Administrator |
| `cfh@secondline.test` | Control Function Head |
| `officer1@secondline.test`, `officer2@secondline.test` | Control Officer |
| `owner1@secondline.test`, `owner2@secondline.test` | Control Owner |
| `manager@secondline.test` | Line Manager |
| `exec@secondline.test` | Executive Viewer |

## Tenancy model

Deployment is **branch-per-client**: each installation serves one tenant, but every
domain table carries `tenant_id` as defence in depth. The
`App\Models\Concerns\BelongsToTenant` trait adds a global query scope (rows are
filtered to the authenticated user's tenant) and auto-fills `tenant_id` on create.
The `Tenant` model holds licence key, data-residency, and settings. Reference data
shared across tenants (system control categories, the default rating matrix) uses
`tenant_id = NULL`.

## RBAC matrix

Roles follow BRD §4, seeded in `RolePermissionSeeder` with `"<verb> <resource>"`
permissions. Enforcement is layered: **route middleware → controller authorize →
policy → query scoping**, mirrored to the UI via shared Inertia props.

| Capability | Sys Admin | CF Head | Control Officer | Control Owner | Line Mgr | Exec Viewer |
|---|:-:|:-:|:-:|:-:|:-:|:-:|
| Define controls / build scripts | ✓ | ✓ | ✓ | — | — | — |
| Approve controls / scripts / ratings (maker≠checker) | ✓ | ✓ | — | — | — | — |
| Execute tests, raise exceptions | ✓ | ✓ | ✓ | — | — | — |
| Review tests (tester ≠ reviewer) | ✓ | ✓ | — | — | — | — |
| Remediate exceptions | ✓ | ✓ | ✓ | own only | — | — |
| **Verify & close exceptions** | see note | ✓ | — | — | — | — |
| Approve extensions / accept risk | ✓ | ✓ | — | — | — | — |
| Spot checks | ✓ | ✓ | ✓ | — | — | — |
| Dashboards / reports | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| Manage users, settings, escalation matrices | ✓ | matrices only | — | — | — | — |
| Manage SSO (maker ≠ checker, second admin approves) | ✓ | — | — | — | — | — |
| View / export audit log | ✓ | ✓ | — | — | — | — |
| Feature flags, security policy, branding | ✓ | — | — | — | — | — |
| Own MFA, notification preferences, saved views, search | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |

**Segregation of duties (FR-12.3), enforced in `ExceptionPolicy` + `ExceptionService`
with no admin bypass:** only a Control Function Head may move an exception to
Verified-Closed; the tester whose test raised it can never close it; a control owner
can never close an exception on their own control; owners can reach *Remediated* and
no further. Covered by explicit failing-path tests in
`tests/Feature/SegregationOfDutiesTest.php`.

## Audit trail

Immutable, append-only `audit_trails` table (updates/deletes throw). Opt any model
in with one trait:

```php
use App\Models\Concerns\Auditable;

class Control extends Model
{
    use Auditable;   // logs created / updated (with before+after) / deleted
}
```

Named domain events are recorded with
`$model->auditAction('approved', $before, $after)` — used for approvals, closures,
reopens, report issuance, and escalations. Every row carries user, IP, user agent,
and before/after JSON. A logging failure never breaks the business operation
(`AuditTrailService` catches and reports).

## Scheduled commands

| Command | Schedule | Purpose |
|---|---|---|
| `secondline:generate-test-instances` | daily 01:00 | Create test instances from each active control's frequency (idempotent) |
| `secondline:refresh-ageing` | daily 01:15 | Recompute `age_days` / `is_overdue`; reopen expired risk acceptances |
| `secondline:expire-compensating-controls` | daily 01:30 | Expire temporary compensating controls; re-flag parent exceptions |
| `secondline:queue-evidence-disposal` | daily 02:00 | Queue expired evidence for dual-approval disposal (legal holds excluded) |
| `secondline:run-escalations` | daily 07:00 | Evaluate escalation matrices, notify by in-app + queued email |
| `secondline:send-owner-digests` | Mondays 07:30 | Open/overdue digest per control owner |

## Reports & exports

Report layouts are **configurable templates** (`report_templates.sections`), never
hard-coded; three defaults ship (Spot Check Report, Exception Register, Control
Testing Summary). PDF via dompdf, Excel via PhpSpreadsheet:
exceptions/controls/test-instance list exports, spot check report download, a
testing summary, and a **board pack** combining selected reports into one PDF.

## Evidence & NDPA (Phase 5)

Evidence attaches polymorphically to test instances, exceptions, and findings.
Upload is **blocked until the uploader declares whether customer personal data is
present** (with data categories when yes) and shows a data-minimisation prompt.
Files live on a non-public disk, stream only through an authorised, access-logged
download route, and carry a SHA-256 checksum. Retention policies (tenant-set, DPO
decides the values) compute an expiry per item; expired items enter a **dual-approval
disposal queue** — two different Control Function approvers, immutable disposal log.
**Legal hold overrides disposal absolutely**, enforced in the workflow, the daily
job, and the model layer itself.

## Integration layer (Phase 6)

Per-tenant configuration for ThirdLine/NexusRisk with three modes (SecondLine
master / ThirdLine master / bidirectional, conflict rule `last_approved_at` wins).
Outbound publication fires on control approval/retire, test review sign-off, and
exception closure — every attempt logged in `integration_sync_logs` with replay
for failures. Inbound `/api/v1` (hashed per-tenant API keys via `X-Api-Key`,
`Idempotency-Key` on writes, strict tenant scoping): pull controls / test results /
exceptions, push control definitions. Spec: [docs/openapi.yaml](docs/openapi.yaml).

## Platform foundations v2 (Phase 7)

Every Phase 7 module ships behind a **feature flag** (`feature_flags`, seeded
enabled; tenant overrides win — `Admin → Feature Flags`).

- **SSO (SAML 2.0 + OIDC)** — `sso_configurations`, encrypted secrets, JIT
  provisioning with IdP-group→role mapping, allowed email domains, SP metadata
  endpoint, admin test-connection (no session created). **Maker-checker on
  authentication config**: a configuration or change takes effect only after a
  *second* System Administrator approves (`SsoConfigurationPolicy`, no admin
  bypass). Once SSO is active, password login is **break-glass only**
  (`users.is_break_glass`): capped per tenant per day, always audited, every
  use alerts all System Administrators.
- **MFA** — TOTP (`pragmarx/google2fa`) with QR enrolment, hashed single-use
  recovery codes, email OTP backup (SMS is opt-in with a SIM-swap warning).
  Per-role enforcement via `tenants.mfa_enforced_roles` with a grace period,
  then a hard block (`EnforceMfa` middleware). Removing enforcement from a
  role writes a high-severity audit event and notifies all System Administrators.
- **Tenant branding** — `tenant_brandings`: logos, favicon, colours (primary
  must pass a 4.5:1 WCAG contrast check or is rejected), login background,
  PDF report header/footer HTML, email sender name. AEGIS tokens remain the
  defaults; overrides inject as CSS custom properties.
- **Notification dispatcher** — seeded `notification_events` catalogue (R1) +
  per-user `notification_preferences` (channel on/off, digest frequency,
  quiet hours in tenant timezone). `NotificationDispatcher` routes every
  notification; whatsapp/sms/push are registered no-op drivers until Phase 15.
- **Audit log UI** — `Admin → Audit Log`: server-side filters, field-level
  before/after diff viewer, cursor pagination, CSV export behind
  `export audit log` (the export is itself audited). "Audit trail" panels on
  Control / Exception / Test Instance pages via a permission-mirrored endpoint.
- **Localisation & money** — tenant locale/timezone/currency/date-format/
  fiscal-year settings shared to the frontend; `App\Support\Money` value
  object (integer minor units + ISO-4217, floats rejected — R7);
  `exchange_rates` reference table with tenant overrides.
- **Global search** — Cmd/Ctrl-K palette over controls, risks, exceptions,
  test instances, spot checks, findings. MySQL FULLTEXT (LIKE fallback on
  SQLite). Results are tenant- **and** permission-scoped — a user never sees
  a title they cannot open.
- **Saved views** — save FilterBar filter sets per list, default per resource,
  share with colleagues in the same role.
- **PWA + low bandwidth** — installable manifest (tenant-branded), service
  worker with cache-first shell and an offline fallback page, per-user
  low-bandwidth mode (kills animations; later phases honour it for charts and
  images), connection-quality banner.

## Key business rules (where they live)

- **Maker–checker** on controls, test scripts, ratings, compensating controls — policies + services
- **Auto-exception from every failed check item** — `TestingService::submit()` (FR-3.6)
- **Lifecycle state machines** — `ControlService::TRANSITIONS`, `ExceptionService::TRANSITIONS`
- **Configurable rating matrix** — `rating_matrix_entries` (seeded BRD §7.3 defaults, tenant-overridable), resolved by `RatingMatrixEntry::resolve()` — never hard-coded
- **Residual risk** — `ResidualRiskService`: inherent × weighted control effectiveness + approved compensating controls; floored so no risk falls below 20% of inherent without effective controls
- **Recurrence detection** — same control failing across periods links and flags (FR-5.9)

## Quality gate

```bash
composer test     # 29 feature/unit tests incl. SoD, legal-hold, dual-approval, and API-auth bypass attempts
composer lint     # Pint
npm run build
```

## Roadmap position

BRD Phases 0–6 are implemented: foundation, control library, testing, exceptions &
escalation, spot checks & dashboard (MVP), reports & exports, evidence retention /
NDPA safeguards, and the ThirdLine/NexusRisk integration layer with OpenAPI spec.

**v2.0 Phase 7 (Platform Foundations v2) is implemented**: SSO (SAML 2.0/OIDC with
maker-checker and break-glass), MFA with role enforcement, tenant branding,
notification preferences + multi-channel dispatcher, audit log UI, localisation +
multi-currency Money, global search, saved views, feature flags, and the PWA shell
with low-bandwidth mode. Next per the v2 master plan: Phase 8 — Framework &
Regulatory Obligation Engine. Still pending from v1 backlog: Excel bulk control
import, Word-format report export, webhook subscriptions, NexusRisk risk-register
pull, in-place evidence redaction and data-subject-request tooling, continuous
controls monitoring (v2 Phase 12).
