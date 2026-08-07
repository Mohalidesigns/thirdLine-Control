# Atheris Control v2.0 — Claude Code Prompt Pack

**How to use this document**

1. Open a fresh Claude Code session in the `atheris-control` repo.
2. Paste **Part A — Master Context Brief** first, every single time.
3. Then paste **exactly one** phase prompt from Part B.
4. Let it run to completion, verify the definition of done, commit, close the session.
5. Repeat for the next phase.

Never run two phases in one session. Context exhaustion is the main cause of half-finished modules.

Parts C and D are reference specifications that individual phase prompts tell Claude Code to read.

---

# PART A — MASTER CONTEXT BRIEF
### Paste this at the top of every session

```
You are working on ATHERIS CONTROL (internal working name "SecondLine") — a
second-line-of-defence Internal Control & GRC platform for banks, fintechs,
insurers, pension operators and listed companies in Nigeria and across Africa.

We are executing a v2.0 upgrade that takes the product from a control-testing
tool to a full GRC suite with Corporater-class capability plus Africa-first
differentiators that no global vendor offers.

═══════════════════════════════════════════════════════════════════════
1. READ THESE BEFORE WRITING ANY CODE
═══════════════════════════════════════════════════════════════════════
  - DEVELOPMENT_STANDARD.md      ← the ThirdLine Development Standard. Binding.
  - Control-Solution-BRD-v1.0.md ← original BRD, §5 functional requirements,
                                   §7 data model, §10 phase prompts 0-6
  - README.md                    ← current state of the system
  - app/Models/Control.php, app/Services/ControlService.php
  - app/Services/ExceptionService.php, app/Services/TestingService.php
  - app/Models/Concerns/{Auditable,BelongsToTenant,GeneratesReference}.php
  - routes/web.php
  - resources/css/app.css        ← AEGIS design tokens
  - resources/js/Layouts/AuthenticatedLayout.jsx
  - database/seeders/RolePermissionSeeder.php

Do not restate what you read. Read it, then build.

═══════════════════════════════════════════════════════════════════════
2. STACK AND CONVENTIONS — DO NOT DEVIATE
═══════════════════════════════════════════════════════════════════════
  Laravel 13 · PHP 8.3 · Inertia.js 3 · React 18 · Tailwind 3 ·
  Spatie Permission 8 · Ziggy 2 · MySQL · dompdf · PhpSpreadsheet

  - Controllers are THIN. Authorize, validate via Form Request, delegate to a
    Service, return Inertia::render or a redirect. No business logic.
  - Services own business logic. One service per domain area, in app/Services.
  - Form Requests own validation. Policies own model authorization.
  - Models: use the Auditable, BelongsToTenant and GeneratesReference traits on
    every new domain model. Every domain table carries tenant_id.
  - Frontend: pages in resources/js/Pages/<Domain>/, shared components in
    resources/js/Components/. Reuse the existing component library
    (DataTable, FilterBar, StatCard, Modal, StatusBadge, SeverityBadge,
    PageHeader, EmptyState, Pagination, ConfirmDialog, EvidencePanel,
    ProgressBar, Dropdown, FlashNotification) before creating anything new.
  - Routes are named and referenced with Ziggy route() in React. Never hardcode.
  - AEGIS design system: Navy #0B1F3A, Gold #C9A227. Use the CSS tokens in
    resources/css/app.css and the Tailwind component layer. Do not invent colours.
  - Migrations: one table per file, timestamped, with down(). Foreign keys named.
    Indexes on tenant_id and every foreign key and every status column.
  - Permissions follow "<verb> <resource>" and are seeded in RolePermissionSeeder.
  - Enforcement is four-layered: route middleware → controller authorize →
    policy → query scoping. All four, every time.

═══════════════════════════════════════════════════════════════════════
3. NON-NEGOTIABLE RULES
═══════════════════════════════════════════════════════════════════════
  R1. NOTHING REGULATORY IS HARD-CODED. Frameworks, obligations, principles,
      deadlines, penalties, rating matrices, workflow states, escalation rules
      and thresholds are DATA — seeded, versioned, effective-dated and
      tenant-overridable. If you find yourself writing a regulator's name or a
      deadline inside a PHP class, stop and make it a seeded record.

  R2. SEGREGATION OF DUTIES AND MAKER-CHECKER SURVIVE EVERY NEW MODULE. Any new
      approval gate must have an explicit failing-path test proving the wrong
      person cannot pass it. There is NO ADMIN BYPASS anywhere. Follow the
      pattern in ExceptionPolicy + ExceptionService and
      tests/Feature/SegregationOfDutiesTest.php.

  R3. THE AUDIT TRAIL IS APPEND-ONLY AND COVERS EVERYTHING, including every AI
      interaction. Named domain events use $model->auditAction('verb', $before,
      $after). An audit failure must never break the business operation.

  R4. AI NEVER DECIDES. Every AI output is a DRAFT requiring explicit human
      approval. AI must never auto-approve a control, close an exception, sign
      an attestation or submit a regulatory filing. Enforced architecturally in
      AiGateway, not by convention.

  R5. NO PERSONAL OR CUSTOMER DATA LEAVES THE TENANT BOUNDARY without an
      explicit, logged, authorised export. All AI payloads pass through PII
      redaction first. Data residency is a compliance requirement, not a setting.

  R6. AFRICA-FIRST PERFORMANCE BUDGET. Assume a mid-range Android on 4G with
      intermittent power. Every list is paginated and server-filtered. Initial
      JS payload for any page ≤ 250KB gzipped. Forms autosave. Uploads are
      resumable. Images are compressed client-side. No page requires more than
      one round-trip to become useful.

  R7. MULTI-CURRENCY AND LOCALE. Money is stored as integer minor units plus an
      ISO-4217 currency code, never a float. Support NGN, GHS, KES, ZAR, USD,
      EUR, GBP. Dates render per tenant locale and timezone (default
      Africa/Lagos). Never assume USD or UTC display.

  R8. BACKWARD COMPATIBILITY. Phases 0-6 are in production. Do not break
      existing routes, tests, API contracts (docs/openapi.yaml) or the
      ThirdLine/NexusRisk integration. Extend, additively. If a change to an
      existing table is unavoidable, write a data migration and update the
      OpenAPI spec in the same commit.

  R9. NO SECRETS IN CODE. All credentials live in .env, are read only through
      config/*.php, and get a placeholder line in .env.example. Never a key in a
      seeder, test, prompt, commit or client bundle.

  R10. UNVERIFIED REGULATORY FACTS SHIP AS DRAFT. Any obligation, deadline or
      penalty that has not been confirmed against the regulator's primary
      document carries verification_status = 'unverified' and is EXCLUDED from
      generated regulatory submissions until a human verifies it. Never invent a
      section number, a deadline or a fine amount. If you do not know, mark it
      unverified and say so in the seeder comment.

═══════════════════════════════════════════════════════════════════════
4. WHAT ALREADY EXISTS (do not rebuild)
═══════════════════════════════════════════════════════════════════════
  Models: Tenant, User, AuditTrail, OrganisationUnit, BusinessProcess,
  ControlCategory, Risk, Control, ControlVersion, ControlAssessment, TestScript,
  CheckItem, TestInstance, CheckResult, EffectivenessRating, RatingMatrixEntry,
  ControlException, ExceptionActivity, CompensatingControl, EscalationMatrix,
  EscalationEvent, SpotCheck, Finding, ReportTemplate, Evidence,
  EvidenceAccessLog, RetentionPolicy, IntegrationConfig, IntegrationSyncLog

  Services: ControlService, TestingService, ExceptionService, EscalationService,
  EvidenceService, ResidualRiskService, DashboardService, ReportService,
  IntegrationService, ExcelExportService, AuditTrailService

  Roles: System Administrator, Control Function Head, Control Officer,
  Control Owner, Line Manager, Executive Viewer (+1 per BRD §4)

  Working: maker-checker on controls/scripts/ratings/compensating controls;
  auto-exception from every failed check item; lifecycle state machines;
  configurable rating matrix; residual risk calculation; recurrence detection;
  6 scheduled commands; PDF/Excel export; board pack; NDPA evidence workflow
  with legal hold and dual-approval disposal; /api/v1 integration layer.

═══════════════════════════════════════════════════════════════════════
5. DEFINITION OF DONE — EVERY PHASE
═══════════════════════════════════════════════════════════════════════
  [ ] Migrations run clean on a fresh DB: php artisan migrate:fresh --seed
  [ ] Seeders produce a realistic, demoable Nigerian dataset
  [ ] All new permissions added to RolePermissionSeeder and the RBAC matrix
      table in README.md
  [ ] Every new approval gate has a passing test AND a failing-path test
  [ ] composer test — green, no skipped tests
  [ ] composer lint — clean (Pint)
  [ ] npm run build — no errors, no new warnings
  [ ] README.md updated: new modules, new commands, new routes, RBAC matrix
  [ ] docs/openapi.yaml updated if any API surface changed
  [ ] No N+1 queries on any index page (verify with the query log)
  [ ] Every new page is usable on a 375px viewport
  [ ] Conventional commit written, phase name in the subject

  If you cannot complete every item, STOP and report exactly what is incomplete
  and why. Do not mark a phase done with failing tests or stubbed methods.

═══════════════════════════════════════════════════════════════════════
6. HOW TO WORK
═══════════════════════════════════════════════════════════════════════
  - Plan first. Produce a file-by-file plan before writing code, and check it
    against the existing structure.
  - Build vertically: migration → model → policy → service → form request →
    controller → routes → permissions → React pages → tests → seeder.
  - Run the test suite after each vertical slice, not at the end.
  - Prefer extending an existing service over creating a parallel one.
  - When a requirement is ambiguous, choose the interpretation that is stricter
    from a compliance standpoint, implement it, and note the assumption in the
    commit body.
```

---
# PART B — PHASE PROMPTS

---

## PHASE 7 — Platform Foundations v2
**Duration: ~3 weeks · Unblocks enterprise procurement**

```
PHASE 7 — PLATFORM FOUNDATIONS v2

OBJECTIVE
Remove the enterprise-procurement blockers and lay the platform groundwork every
later phase depends on: SSO, MFA, tenant branding, a notification preference and
multi-channel dispatch system, an audit log UI, localisation and multi-currency,
global search, saved views, feature flags, and the PWA shell with a
low-bandwidth mode.

READ FIRST
  app/Models/{Tenant,User}.php, app/Http/Controllers/Auth/,
  app/Http/Middleware/HandleInertiaRequests.php, app/Models/AuditTrail.php,
  app/Notifications/, resources/js/Layouts/AuthenticatedLayout.jsx,
  resources/css/app.css, config/

BUILD

7.1 SSO — SAML 2.0 and OIDC
  - Add packages: socialiteproviders/microsoft-azure (or laravel/socialite +
    league/oauth2-client) for OIDC, and a SAML2 SP implementation.
  - Table sso_configurations: tenant_id, protocol enum(saml2,oidc),
    display_name, entity_id, sso_url, slo_url, x509_cert (encrypted),
    oidc_issuer, oidc_client_id, oidc_client_secret (encrypted),
    oidc_discovery_url, attribute_map json, jit_provisioning bool,
    default_role_id, allowed_email_domains json, is_enabled, verified_at.
  - JIT provisioning maps IdP group claims to Spatie roles via attribute_map.
  - Entra ID / Azure AD is the priority IdP (dominant in Nigerian banks); also
    support Okta and generic SAML.
  - Fallback local login stays available for break-glass admin accounts only,
    flagged on the user record as is_break_glass, and every break-glass login
    writes an audit event.
  - SP metadata endpoint + an admin "Test connection" action that validates the
    assertion without creating a session.

7.2 MFA
  - TOTP (laravel/fortify two-factor or pragmarx/google2fa), recovery codes
    (hashed, single-use), enforced per role via a tenant policy setting.
  - mfa_enforced_roles json on tenants. Grace period in days, then hard block.
  - Backup: email OTP. NOT SMS by default (SIM-swap risk in-market), but allow
    a tenant to opt in with a warning shown at enable time.

7.3 Tenant branding / white-label
  - Table tenant_brandings: tenant_id, logo_path, logo_dark_path, favicon_path,
    primary_colour, accent_colour, login_background_path, product_name,
    support_email, support_phone, report_header_html, report_footer_html,
    email_from_name.
  - Colours are injected as CSS custom properties on the app shell; the AEGIS
    tokens remain the defaults. Validate contrast ratio ≥ 4.5:1 against white
    and reject a brand colour that fails, with a clear error.
  - Branding flows into: app shell, login page, PDF report header/footer,
    email templates.

7.4 Notification preferences and multi-channel dispatch
  - Table notification_preferences: user_id, event_key, channel
    enum(in_app,email,whatsapp,sms,push), is_enabled, digest_frequency
    enum(immediate,daily,weekly), quiet_hours_start, quiet_hours_end.
  - Table notification_events: key, label, description, category, default_channels
    json, is_user_configurable — SEEDED, not hard-coded (rule R1).
  - Create App\Services\NotificationDispatcher: resolves preferences, respects
    quiet hours in the user's timezone, batches digests, and dispatches through
    channel drivers. Ship in_app + email drivers now; register whatsapp, sms and
    push as no-op drivers with a clear TODO — Phase 15 implements them.
  - Retrofit existing EscalationNotification and OwnerDigestNotification to route
    through the dispatcher. Do not change their content.

7.5 Audit log UI
  - Read-only admin page with server-side filters: date range, user, model type,
    model id, action, IP. Diff viewer rendering the before/after JSON as a
    field-level comparison. CSV export gated behind a new permission
    "export audit log". Cursor pagination — this table gets large.
  - Add an "Activity" tab to Control, Exception and TestInstance show pages
    rendering that record's audit history inline.

7.6 Localisation, timezone and multi-currency
  - Tenant settings: locale (default en-NG), timezone (default Africa/Lagos),
    currency (default NGN), date_format, fiscal_year_start_month.
  - Create app/Support/Money.php value object: integer minor units + ISO-4217
    code, with add/subtract/multiply/format/convert. Store as two columns
    (<field>_amount bigint, <field>_currency char(3)) — NEVER a float.
  - Table exchange_rates: base_currency, quote_currency, rate decimal(20,10),
    effective_date, source. Seed NGN/GHS/KES/ZAR/USD/EUR/GBP with a manual
    source; the rate provider integration comes in Phase 12.
  - Frontend: a formatMoney and formatDate helper in resources/js/utils.js
    driven by shared Inertia props. Replace every existing hardcoded date and
    number format.

7.7 Global search
  - Command-palette style (Cmd/Ctrl-K) search across controls, risks,
    exceptions, test instances, spot checks, findings, and — as later phases add
    them — policies, incidents, obligations.
  - MySQL FULLTEXT indexes; a SearchService with per-model searchable field
    definitions; results are tenant-scoped AND permission-scoped (a user must
    never see a title they cannot open).
  - Debounced, paginated, keyboard navigable.

7.8 Saved views
  - Table saved_views: user_id, tenant_id, resource, name, filters json,
    columns json, sort json, is_shared, is_default.
  - Wire into the existing FilterBar component. A user can save the current
    filter set, set a default view per resource, and share a view with their role.

7.9 Feature flags
  - Table feature_flags: tenant_id (nullable = global), key, is_enabled,
    rollout_percentage, description. A Features facade, exposed to the frontend
    through shared Inertia props. Every subsequent phase registers its module
    behind a flag so partial deployments are safe.

7.10 PWA shell and low-bandwidth mode
  - Web app manifest, installable, offline fallback page, service worker
    registering a cache-first strategy for the app shell and static assets.
  - A "Low bandwidth" tenant/user setting that: disables chart animations,
    lazy-loads charts below the fold, drops image quality, increases page size
    defaults, and prefers table over card layouts.
  - Add a lightweight client-side connection-quality probe and surface an
    "offline / poor connection" banner. The full offline queue is Phase 15.

BUSINESS RULES
  - SSO configuration changes require a second System Administrator to approve
    before taking effect (maker-checker on authentication configuration).
  - Disabling MFA for a role writes a high-severity audit event and notifies all
    System Administrators.
  - Break-glass local login is capped at N attempts per day per tenant
    (configurable) and always audited.

TESTS
  - SAML assertion parsing, signature validation, replay rejection
  - OIDC code exchange, nonce and state validation
  - JIT provisioning maps claims to the correct role; unmapped claim → default role
  - A user in an MFA-enforced role past the grace period is blocked
  - Recovery codes are single-use
  - Notification preferences suppress the correct channel; quiet hours defer
  - Audit log UI cannot be reached without "view audit log"; export needs
    "export audit log"
  - Global search never returns a record the user lacks permission to view
  - Money value object rejects float construction and cross-currency addition
  - Brand colour failing contrast is rejected

DELIVERABLES
  Migrations, models, policies, services, controllers, routes, React pages
  (Admin/Sso, Admin/Branding, Admin/AuditLog, Admin/FeatureFlags,
  Settings/Notifications, Settings/Profile), seeders, tests, README update.
```

---

## PHASE 8 — Framework & Regulatory Obligation Engine ⭐ THE WEDGE
**Duration: ~5 weeks · This is the phase that wins deals**

```
PHASE 8 — FRAMEWORK & REGULATORY OBLIGATION ENGINE

OBJECTIVE
Build the content engine that makes Atheris the only GRC platform with shipped,
maintained, regulator-shaped Nigerian and African compliance content. This is
the single most commercially valuable phase in the roadmap. Everything here is
DATA, not code.

Three things get built:
  (a) a generic framework/requirement/mapping engine
  (b) a regulatory obligation register with a compliance calendar
  (c) seeded content packs — international frameworks and Nigerian regulators

READ FIRST
  Part D of this prompt pack (Regulatory Content Pack Specification) —
  it contains the exact taxonomy, the COSO 17 principles, the COSO ERM 20
  principles, ISO 27001:2022 Annex A structure, NIST CSF 2.0 functions, and the
  Nigerian regulator inventory with sources and verification status.
  Also read: app/Models/Control.php (framework_refs, coso_component),
  database/seeders/StarterControlLibrarySeeder.php

DATA MODEL

  frameworks
    id, tenant_id NULL (NULL = system/global), code, name, issuing_body,
    jurisdiction (ISO-3166 or 'INTL'), version, category
    enum(internal_control, risk, security, privacy, governance, financial,
    sustainability, sector), effective_from date, effective_to date NULL,
    supersedes_framework_id NULL, is_certifiable bool, source_url,
    verification_status enum(verified,unverified,draft), description, is_active

  framework_requirements   (self-referencing tree: domain → principle → control objective)
    id, framework_id, parent_id NULL, ref_code, title, description,
    guidance text, level tinyint, sort_order, requirement_type
    enum(component,principle,domain,objective,control,clause,article,section),
    is_testable bool, suggested_frequency, suggested_evidence json,
    verification_status, source_reference

  framework_mappings       (the "test once, satisfy many" graph)
    id, source_requirement_id, target_requirement_id, relationship
    enum(equivalent,partial,supports,informs), coverage_percentage tinyint,
    rationale, created_by, verified_by NULL, verified_at NULL

  control_requirement_map
    id, tenant_id, control_id, requirement_id, coverage
    enum(full,partial,supporting), notes, mapped_by, mapped_at,
    approved_by NULL, approved_at NULL      -- maker-checker on mapping

  regulators
    id, code, name, jurisdiction, sector json, website, portal_url,
    contact_details json, is_active

  regulatory_obligations
    id, tenant_id NULL, regulator_id, framework_id NULL, requirement_id NULL,
    obligation_ref, title, description, obligation_type
    enum(filing,disclosure,notification,approval,registration,payment,
    assessment,training,retention,operational),
    applies_to json               -- entity types/licence categories
    trigger_type enum(calendar,event,threshold,on_demand),
    frequency enum(one_off,daily,weekly,monthly,quarterly,semi_annual,annual,
    biennial,triennial,event_driven),
    due_rule json                 -- e.g. {"type":"fixed_date","month":3,"day":31}
                                  -- or {"type":"relative","anchor":"fiscal_year_end","offset_days":60}
                                  -- or {"type":"event_relative","event":"breach_detected","offset_hours":72}
    grace_period_days, penalty_description, penalty_amount_minor bigint NULL,
    penalty_currency char(3) NULL, penalty_basis
    enum(fixed,per_day,per_week,per_instance,percentage) NULL,
    legal_reference, source_url, effective_from, effective_to NULL,
    verification_status, is_active

  obligation_assignments
    id, tenant_id, obligation_id, entity_id (organisation_unit), owner_id,
    reviewer_id NULL, is_applicable bool, non_applicability_reason,
    approved_by, approved_at

  obligation_instances     (the calendar — generated by a scheduled command)
    id, tenant_id, obligation_id, assignment_id, period_label, period_start,
    period_end, due_at, status enum(Not Started,In Progress,Submitted,
    Accepted,Rejected,Overdue,Waived), submitted_at, submitted_by,
    submission_reference, acknowledgement_ref, evidence_count,
    days_overdue, penalty_exposure_minor, notes

  regulatory_changes       (the circular feed)
    id, regulator_id, title, reference, published_at, effective_at,
    document_url, summary, impact_assessment, status
    enum(New,Under Review,Impact Assessed,Actioned,Not Applicable),
    assessed_by, assessed_at, affected_obligation_ids json,
    affected_control_ids json, source enum(manual,rss,ai_parsed)

  content_packs            (versioned, installable)
    id, code, name, jurisdiction, version, checksum, published_at,
    installed_at NULL, installed_by NULL, changelog, verification_summary json

BUILD

8.1 FrameworkService
  - Import/export a framework as JSON (this is how content packs ship).
  - Requirement tree CRUD with drag-reorder in the UI.
  - Mapping suggestion: given a requirement, suggest mappings by text similarity
    across other frameworks (deterministic — cosine over TF-IDF; Phase 14 adds
    the AI-assisted version).
  - Coverage calculation: for a given framework, what % of testable requirements
    have at least one mapped Active control with a current effective rating.

8.2 ObligationService
  - Due-date resolution from due_rule against the tenant's fiscal calendar,
    timezone and public holidays (seed Nigerian public holidays; make it a table).
  - Applicability engine: given a tenant's entity types and licence categories,
    determine which obligations apply. A tenant can override with a reasoned,
    approved non-applicability declaration.
  - Penalty exposure calculator: computes live financial exposure for overdue
    obligations from penalty_basis and penalty_amount. This drives the
    "cost of non-compliance" widget that sells the product.

8.3 Scheduled command: atheris:generate-obligation-instances
  - Daily. Idempotent. Generates upcoming instances within a rolling horizon
    (default 400 days). Recomputes overdue status and penalty exposure. Fires
    graduated reminders at T-90/-60/-30/-14/-7/-3/-1/0/overdue via the Phase 7
    NotificationDispatcher.

8.4 Compliance calendar UI
  - Month/quarter/year views, filter by regulator, entity, owner, status.
  - A heat strip showing filing density so a compliance officer sees crunch periods.
  - Per-instance drawer: obligation detail, evidence attach (reuse EvidencePanel),
    submission recording with reference number and acknowledgement upload,
    status workflow with maker-checker on "Submitted".

8.5 Framework explorer UI
  - Tree view of a framework's requirements with per-requirement coverage
    (mapped controls, latest test result, effectiveness).
  - "Coverage heatmap": framework requirements × entity, colour by control
    coverage and effectiveness. This is the screen executives screenshot.
  - Cross-framework view: pick a control, see every requirement across every
    framework it satisfies. This is the "test once, satisfy many" proof.

8.6 Regulatory change feed
  - Manual entry + RSS ingestion where a regulator publishes one. Impact
    assessment workflow: New → Under Review → Impact Assessed → Actioned,
    with maker-checker on Actioned, linking affected obligations and controls.
  - Phase 14 adds AI parsing of circular PDFs into draft change records.

8.7 Content pack installer
  - php artisan atheris:install-content-pack {code} --version= --dry-run
  - Idempotent, checksummed, produces a diff report before applying, never
    overwrites tenant customisations (tenant-scoped records win), records the
    installation in content_packs, and writes an audit event.

CONTENT PACKS TO SEED (see Part D for full detail)

  INTERNATIONAL — verified
    COSO-IC-2013     5 components, 17 principles (exact text in Part D)
    COSO-ERM-2017    5 components, 20 principles
    COSO-ICSR-2023   internal control over sustainability reporting
    COBIT-2019       5 domains, 40 governance/management objectives
                     (EDM 5, APO 14, BAI 11, DSS 6, MEA 4)
    ISO-27001-2022   clauses 4-10 + Annex A 93 controls in 4 themes
                     (A.5 Organizational 37, A.6 People 8, A.7 Physical 14,
                      A.8 Technological 34) + Amd.1:2024 climate
    ISO-31000-2018   8 principles, framework, process
    ISO-37301-2021   compliance management system clauses
    ISO-22301-2019   BCMS incl. MTPD/RTO/RPO/MBCO parameters
    NIST-CSF-2.0     6 functions GV/ID/PR/DE/RS/RC, categories, subcategories
    PCI-DSS-4.0.1    12 requirements, 6 goals, customised approach
    SOX              s.302 and s.404(a)/(b), PCAOB AS 2201 top-down approach

  NIGERIA — the wedge
    CBN-CG-2023      Corporate Governance Guidelines (banks + FHC variants):
                     board composition/tenure clocks, committee charters and the
                     30-day CBN "No Objection", internal audit non-outsourcing +
                     annual external assessment filed by 31 May, ERM framework
                     3-yearly review + annual effectiveness review, compliance
                     function, auditor rotation (partner 5 yrs, firm 10 + 10
                     cooling-off), board evaluation report by 31 May, external
                     auditor report by 31 March
    CBN-RBCF-2022    Risk-Based Cybersecurity Framework, 6 parts, DMB/PSP and
                     OFI variants, effective 1 Jan 2023, CISO accountability,
                     asset inventory, cyber resilience assessment, metrics
    CBN-AML-2022     AML/CFT/CPF Regulations 2022 + TFS Guidelines 2022,
                     anchored on s.66 BOFIA 2020 and s.54 TPPA 2022
    CBN-CPF-2016/19  Consumer Protection Framework + Regulations: 24-hour
                     acknowledgement, unique tracking ID, ₦500,000 per complaint
                     per week, ₦2m acknowledgement failure, ₦2m directive breach
    CBN-DATALOC-2026 Circular PSS/DIR/PUB/CIR/001/004 (15 Jun 2026): in-country
                     storage and processing by 1 Jan 2027; market-structure
                     compliance by 31 Dec 2026; UBO disclosure; monthly
                     market-share returns
    CBN-BASEL-III    Sept 2021 guidelines: regulatory capital, leverage, LCR,
                     NSFR, ICAAP/Pillar 2, stress testing, large exposures
    NDIC-DPAS        Differential Premium Assessment System: base rate + add-ons
                     capped 0.30%; capital adequacy 0.01-0.05%, asset quality
                     0.02-0.04%, liquidity 0.02-0.04%; MANAGEMENT add-ons —
                     poor internal controls +0.02%, late returns +0.01%,
                     financial misreporting +0.03%, weak risk management +0.02%,
                     non-compliance with examiners' recommendations +0.02%
    FRC-NCCG-2018    28 principles across board, remuneration, risk management,
                     internal audit, whistleblowing, external audit, shareholder
                     relations, business conduct, sustainability, disclosure.
                     Apply-and-explain. "N/A" PROHIBITED. Return FRC/CG/001
                     sections A-E. Four signatories.
    FRC-ICFR-2024    Guidance on Management Report on ICFR — Nigeria's SOX-404.
                     Scope: PIEs except listed (they use SEC guidance), CAMA
                     small companies, unit MFBs, insurance brokers,
                     non-tertiary education/health. Requires: management
                     responsibility statement, framework identification (COSO
                     2013 highly recommended), effectiveness assessment at FYE
                     disclosing material weaknesses, external auditor
                     attestation statement. Top-down risk-based. Three-tier
                     deficiency taxonomy: control deficiency / significant
                     deficiency / material weakness.
    FRC-PIE          PIE definition and filing calendar: annual report + AFS
                     within 60 days of board approval; qualified reports within
                     30 days; copies of other-regulator filings within 30 days;
                     turnover ≥ ₦30bn threshold
    FRC-IFRS-S1-S2   Amended roadmap (Feb 2026): Phase 3A all PIEs from periods
                     beginning 1 Jan 2028; Phase 3B SMEs 1 Jan 2030; Phase 4
                     public sector 1 Jan 2028. Three-stage pre-reporting filings
                     at -3 months, +3 months, +6 months.
    SEC-NG-ICFR      Guidance on ISA ss.60-63: CEO/CFO certification, annual
                     board ICFR report, framework identification, external
                     auditor attestation near the audit opinion.
                     ⚠ verification_status = 'unverified' — confirm whether ISA
                     2025 renumbers ss.60-63 and whether SEC reissued guidance.
    SEC-NG-CG-2020   Corporate Governance Guideline + Form 01, 14 principles,
                     ₦500,000 + ₦5,000/day penalty
    SEC-NG-RETURNS   Annual report within 3 months of year end; quarterly
                     financials within 30 days of quarter end; corporate
                     governance report within 30 days of year end
    NDPA-GAID-2025   NDPA 2023 + GAID (issued 20 Mar 2025, effective 19 Sep
                     2025). Tiers UHL/EHL/OHL (Arts. 8-9). Compliance Audit
                     Return by 31 March annually, new entities within 15 months
                     (Art. 10), fees ₦100,000-₦1,000,000 by data volume, 50%
                     late penalty, UHL/EHL must engage a licensed DPCO. DPO
                     independence (Arts. 11-12). DPIA mandatory for high-risk
                     processing (Art. 28) — before processing; within 4 months
                     for post-GAID sensitive-data software; within 6 months for
                     pre-existing. Breach notification to NDPC within 72 hours
                     (Art. 33). Cross-border per Part VIII + Schedule 5.
    NAICOM-NIIRA-25  NIIRA 2025 (assented 6 Aug 2025): risk-based supervision,
                     actuarial independence and direct NAICOM reporting, audited
                     FS + investment statements + revenue accounts by 30 June,
                     quarterly returns within 10 days of quarter end, dividend
                     declarations need prior NAICOM approval.
                     ⚠ capital by class, claims SLAs, penalties = unverified
    PENCOM           PRA 2014, Regulations for Compliance Officers
                     (RR/P&R/09/03), Risk Management Framework guidelines,
                     Investment Regulation (amended Feb 2019).
                     ⚠ RM guideline contents = unverified
    NCC              Annual audited financials within 180 days of FYE (penalty
                     ₦3m + ₦300,000/day), Annual Operating Levy 1% of net
                     revenue within 30 days of submission, annual ownership
                     report by 1 March, 90-day notice for >10% share transfers,
                     Year End Questionnaire, address change within 7 days,
                     type approval, Consumer Code of Practice
    FIRS-EINV-2025   e-invoicing via Merchant Buyer Solution, pre-clearance
                     model, mandatory for turnover ≥ ₦5bn from 1 Aug 2025.
                     ⚠ later taxpayer-band dates = unverified
    CAMA-CAC-TAX     CAC annual returns (first within 18 months, then annually),
                     PSC filing, first AGM within 18 months then within 15
                     months, CIT within 6 months of FYE, VAT by the 21st, PAYE
                     monthly by the 10th and annual by 31 January, WHT within 30
                     days, NSITF 1% of payroll, pension 8%+10% within 7 days of
                     salary payment, NDPC registration, NIPC, NOTAP, SCUML

  PAN-AFRICAN — ship as draft, verify before sale in-market
    ZA-KING-IV (17 principles) and ZA-KING-V-DRAFT (incl. the new AI-governance
    provisions, 2-year executive cooling-off, 9-year independence tenure),
    ZA-POPIA, ZA-COMPANIES-ACT (s.94 audit committee, s.72(4) social & ethics),
    ZA-JSE-3.84, ZA-JOINT-STANDARD-2-2024 (cyber, effective 1 Jun 2025)
    KE-CBK-PG (⚠ guideline numbers unverified), KE-DPA-2019 (72-hour breach),
    KE-CMA-2015 (biennial independent governance audit — distinctive)
    GH-BOG-CGD-2018, GH-BOG-CYBER-2026 (data localisation, AI/ML governance),
    GH-DPA-2012
    AU-MALABO (in force 8 Jun 2023; 16 ratifications incl. Nigeria, South
    Africa, Kenya, Rwanda, Senegal, Uganda)

CROSS-FRAMEWORK MAPPINGS TO SEED (minimum viable set)
  COSO-IC-2013 P11 ↔ ISO-27001-2022 A.8.* ↔ NIST-CSF-2.0 PR.* ↔ COBIT DSS05
  COSO-IC-2013 P10/P12 ↔ CBN-RBCF Part 2 ↔ NCCG P17
  ISO-27001-2022 A.5.7 ↔ NIST-CSF-2.0 ID.RA / GV.OC (threat intelligence)
  NDPA-GAID Art.28 (DPIA) ↔ ISO-27001-2022 A.5.34 ↔ POPIA s.
  FRC-ICFR ↔ SOX 404 ↔ COSO-IC-2013 (all 17)
  NDIC-DPAS management factors ↔ CBN-CG-2023 internal audit + risk sections
  CBN-RBCF ↔ ISO-27001-2022 ↔ NIST-CSF-2.0 ↔ PCI-DSS-4.0.1 (for switches/PTSPs)

BUSINESS RULES
  - Mapping a control to a requirement is maker-checker: an Officer maps, a
    Control Function Head approves. Unapproved mappings do not count toward
    coverage and never appear in a generated submission.
  - An obligation instance can only move to Submitted with a submission
    reference AND at least one evidence item attached.
  - Content-pack install never overwrites a tenant-customised record.
  - Any requirement or obligation with verification_status != 'verified' is
    excluded from generated regulatory submissions and is badged "Unverified"
    everywhere it appears.

TESTS
  - Due-date resolution for all three due_rule types across fiscal calendars,
    leap years, and Nigerian public holidays
  - Penalty exposure for each penalty_basis, including the CBN CPF per-week case
  - Applicability engine with entity type + licence category combinations
  - Idempotency: running the instance generator twice creates no duplicates
  - Coverage calculation excludes unapproved mappings and retired controls
  - Content pack install is idempotent and preserves tenant overrides
  - An unverified obligation cannot enter a submission pack
  - Cross-framework query: one control returns all satisfied requirements

DELIVERABLES
  ~12 migrations, 10 models, FrameworkService, ObligationService,
  MappingService, ContentPackInstaller, 2 scheduled commands, controllers,
  routes, React pages (Frameworks/Index, Frameworks/Show tree, Frameworks/
  Coverage, Obligations/Index, Obligations/Calendar, Obligations/Show,
  RegulatoryChanges/Index, Admin/ContentPacks), 20+ content pack seeders,
  tests, README + openapi.yaml updates.
```

---

## PHASE 9 — Control Library v2, CSA & Surveys
**Duration: ~4 weeks · Corporater parity on the control library**

```
PHASE 9 — CONTROL LIBRARY v2, CONTROL SELF-ASSESSMENT & SURVEYS

OBJECTIVE
Bring the control library to Corporater parity and beyond: group-level and
entity-level libraries, control distribution to entities with implementation
progress tracking, Control Self-Assessment campaigns, a general survey engine,
policy/control attestation, document management, bulk Excel import/export, and
version control across every versioned object.

Reference the Corporater screenshots supplied by the client: the "Control
distribution overview" tree, "Control implementation progress %", "Entities
distributed to / Tasks completed / Distributed tasks not completed" stat tiles,
"Progress by relevant business units" grouped bars, "Implementation progress by
country" horizontal bars, the "Control library" list with control category /
level / function grouping / frequency / number of entities columns, and the
"Measure categories library" nested tree. Match or beat that information density.

READ FIRST
  app/Models/{Control,ControlCategory,OrganisationUnit,ControlVersion}.php,
  app/Services/ControlService.php, resources/js/Pages/Controls/,
  database/seeders/StarterControlLibrarySeeder.php

DATA MODEL ADDITIONS

  controls — add columns:
    library_level enum(group,entity) default 'entity',
    parent_control_id NULL           -- entity instance of a group control
    function_grouping enum(Identify,Protect,Detect,Respond,Recover,Govern) NULL,
    control_level enum(Strategic,Operational,Transactional) NULL,
    implementation_status enum(Not Started,In Progress,Implemented,
      Partially Implemented,Not Applicable) default 'Not Started',
    implementation_progress tinyint default 0,
    is_distributable bool default false

  control_distributions
    id, tenant_id, control_id, entity_id, assigned_owner_id, distributed_by,
    distributed_at, due_at, status enum(Pending,Acknowledged,In Progress,
    Completed,Declined), acknowledged_at, completed_at, local_adaptations text,
    decline_reason, progress tinyint

  distribution_tasks
    id, distribution_id, title, description, sequence, owner_id, due_at,
    status enum(Open,In Progress,Complete,Blocked), completed_at, completed_by,
    evidence_required bool, blocker_reason

  csa_campaigns
    id, tenant_id, name, description, campaign_type enum(csa,attestation,survey),
    scope_definition json, period_label, opens_at, closes_at,
    reminder_schedule json, status enum(Draft,Scheduled,Open,Closed,Archived),
    created_by, approved_by, approved_at, response_rate_target

  csa_questionnaires
    id, campaign_id, name, version_no, is_active, instructions,
    scoring_method enum(none,weighted,maturity), pass_threshold

  csa_questions
    id, questionnaire_id, section, sequence, question_text, help_text,
    response_type enum(yes_no,yes_no_na,scale_1_5,maturity_0_5,single_select,
    multi_select,free_text,numeric,date,file_upload),
    options json, is_required, evidence_required bool, weight,
    control_id NULL, requirement_id NULL, triggers_exception_on json

  csa_responses
    id, campaign_id, questionnaire_id, respondent_id, entity_id, control_id NULL,
    status enum(Not Started,In Progress,Submitted,Under Review,Accepted,
    Returned), submitted_at, reviewed_by, reviewed_at, review_notes, score,
    self_rating, reviewer_rating, variance_flag bool

  csa_answers
    id, response_id, question_id, answer_value json, comment,
    evidence_id NULL, answered_at

  attestations
    id, tenant_id, attestable_type, attestable_id   -- polymorphic: policy,
        control, code of conduct, framework
    user_id, campaign_id NULL, attested_at, attestation_text_snapshot,
    ip_address, user_agent, method enum(web,mobile,whatsapp,email),
    version_attested

  documents
    id, tenant_id, folder_id NULL, title, description, document_type
    enum(policy,procedure,standard,guideline,form,template,charter,report,
    certificate,contract,other), reference, version_no, status
    enum(Draft,Under Review,Approved,Published,Superseded,Archived),
    file_path, file_hash, mime_type, size_bytes, owner_id, approver_id,
    approved_at, published_at, review_due_at, next_review_at, is_confidential,
    access_role_ids json, tags json, supersedes_document_id NULL,
    download_count

  document_folders
    id, tenant_id, parent_id NULL, name, description, sort_order, path

  document_versions
    id, document_id, version_no, file_path, file_hash, change_summary,
    created_by, created_at, approved_by, approved_at

  improvement_actions          -- the "improvement database"
    id, tenant_id, source_type enum(test,csa,spot_check,incident,audit,
    exception,survey,manual), source_id, title, description, category,
    priority enum(Low,Medium,High,Critical), owner_id, due_at,
    status enum(Proposed,Approved,In Progress,Implemented,Verified,Rejected),
    approved_by, verified_by, verified_at, benefit_description,
    effort_estimate, control_id NULL, risk_id NULL

BUILD

9.1 Group vs entity libraries
  - A group control is the master definition. Distributing it to entities
    creates child controls (parent_control_id) that inherit the definition but
    carry their own owner, frequency, testing and rating.
  - Changing a group control opens a change-propagation workflow: preview the
    affected entity controls, choose propagate/notify-only, entity owners
    acknowledge. Never silently overwrite an entity's local adaptation.
  - Control library index gains the Corporater columns: category, level,
    function grouping, frequency, number of entities, implementation progress.

9.2 Distribution overview
  - Org-tree table (reuse OrganisationUnit hierarchy) showing per entity: the
    distributed control, its risk, its owner, status and progress — matching the
    supplied screenshot.
  - Stat tiles: Entities distributed to · Tasks completed · Distributed tasks
    not completed · Control implementation progress %.
  - "Open distribution settings" panel: select entities by unit, region, entity
    type or licence category; set due dates; auto-assign owners by role.

9.3 CSA engine
  - Questionnaire builder with sections, conditional logic (show question B if
    answer A = X), weighting and maturity scoring.
  - Campaign scoping by entity, process, control category, framework or risk.
  - Distribution, reminder ladder, response tracking with a live response-rate
    gauge, reviewer workflow (self-rating vs reviewer rating with variance
    flagging), and automatic exception creation when an answer matches
    triggers_exception_on.
  - CSA results feed control design_effectiveness as a *proposed* rating that a
    Control Function Head must approve — CSA never auto-rates.

9.4 Survey engine
  - The same questionnaire machinery, campaign_type = 'survey', with anonymous
    response support (respondent_id nullable, no IP stored when anonymous) for
    culture, ethics and risk-perception surveys. Anonymity must be real: if
    anonymous, never write an identifying column, and prove it in a test.

9.5 Attestation
  - Policy and code-of-conduct attestation campaigns, capturing the exact text
    version attested with a snapshot, timestamp, IP and method.
  - Non-attestation escalates through the existing EscalationService.

9.6 Document management
  - Folder tree, versioning, approval workflow (maker-checker), publish,
    review-due scheduling with reminders, supersession chain, confidential
    documents gated by role, download logging, full-text search integration.
  - Distinct from Evidence: documents are governing artefacts, evidence is proof.
    Link a document to controls, policies, obligations and frameworks.

9.7 Bulk Excel import/export
  - Controls, risks, obligations, org units and users.
  - Download a template with data-validated dropdowns; upload; a dry-run
    validation report showing row-level errors before anything is written;
    then a transactional import with a rollback on any failure and a full
    audit entry. This is a hard requirement — every bank arrives with a
    spreadsheet.

9.8 Version control everywhere
  - Extend the ControlVersion pattern to test scripts, policies, documents,
    questionnaires, frameworks and report templates. A shared HasVersions trait.
  - Version comparison UI: side-by-side field diff with change attribution.

9.9 Improvement database
  - Register of improvement actions from any source, with approval, ownership,
    due dates, verification, and a link back to the originating record. Surfaces
    on control and entity pages as "known improvements".

BUSINESS RULES
  - Distribution requires the control to be Active and approved.
  - An entity may decline a distributed control only with a reason and Control
    Function Head approval; a declined control still appears in coverage
    reporting as a gap.
  - CSA self-rating differing from reviewer rating by more than a configurable
    threshold flags a variance and notifies the Control Function Head.
  - Anonymous survey responses can never be de-anonymised, by any role.
  - Document approval is maker-checker; the approver cannot be the owner.

TESTS
  - Distribution creates one child control per entity, idempotently
  - Group control change does not overwrite a local adaptation
  - Conditional question logic shows/hides correctly
  - Anonymous survey stores no identifying data (assert on the row)
  - CSA answer matching triggers_exception_on creates an exception linked to the
    control and the campaign
  - CSA proposed rating does not change the control rating without approval
  - Bulk import dry-run reports errors and writes nothing
  - Bulk import rolls back completely on a mid-file failure
  - Document approver cannot be the document owner
  - Version diff returns correct field-level changes

DELIVERABLES
  ~14 migrations, 12 models, DistributionService, CsaService, SurveyService,
  AttestationService, DocumentService, ImportService, VersioningService,
  controllers, routes, React pages (Controls/Distribution, Controls/Library
  upgrade, Csa/*, Surveys/*, Documents/*, Improvements/*, Admin/Import),
  seeders, tests, README update.
```

---

## PHASE 10 — Risk Management v2
**Duration: ~4 weeks**

```
PHASE 10 — RISK MANAGEMENT v2

OBJECTIVE
Turn the basic risk register into a full enterprise risk management module:
qualitative and quantitative assessment, risk appetite and tolerance, heatmaps,
treatment plans with status and alerts, a KRI/KPI engine with thresholds and
breach alerting, and the linkage graph that connects risks, controls, KRIs,
incidents, policies, obligations and objectives.

READ FIRST
  app/Models/Risk.php, app/Services/ResidualRiskService.php,
  resources/js/Pages/Risks/, database/migrations/*_create_risks_table.php,
  and the dataviz skill before building any chart.

DATA MODEL

  risks — add:
    risk_category_id, risk_type enum(qualitative,quantitative,both),
    risk_source enum(internal,external,both), taxonomy_ref,
    basel_event_type enum(...)          -- for operational risk mapping
    velocity enum(Slow,Medium,Fast), is_emerging bool, horizon,
    appetite_id NULL, parent_risk_id NULL, owner_id, second_line_reviewer_id

  risk_categories        (hierarchical taxonomy: credit, market, liquidity,
                          operational, compliance, strategic, reputational,
                          cyber, climate, model, third-party, conduct)
    id, tenant_id NULL, parent_id, code, name, description, sort_order

  risk_assessment_scales  (fully configurable — R1)
    id, tenant_id, scale_type enum(likelihood,impact,velocity,control_effect),
    dimension NULL, level tinyint, label, description,
    lower_bound_minor bigint NULL, upper_bound_minor bigint NULL,
    currency char(3) NULL, colour_hex, sort_order

  risk_assessments
    id, tenant_id, risk_id, assessment_type enum(inherent,residual,target),
    assessed_at, assessed_by, approved_by NULL, approved_at NULL,
    likelihood_level, impact_level, likelihood_rationale, impact_rationale,
    score decimal, rating enum(Low,Moderate,High,Critical),
    financial_impact_minor bigint NULL, currency char(3) NULL,
    impact_dimensions json     -- financial, regulatory, reputational,
                               -- operational, customer, people, each scored
    confidence enum(Low,Medium,High), method
    enum(workshop,interview,data_driven,scenario,monte_carlo),
    period_label, notes

  risk_appetites
    id, tenant_id, entity_id NULL, risk_category_id NULL, statement,
    appetite_level enum(Averse,Minimal,Cautious,Open,Hungry),
    tolerance_upper decimal, tolerance_lower decimal, capacity decimal,
    metric_definition, approved_by, approved_at, effective_from, effective_to,
    review_due_at, status enum(Draft,Pending Approval,Active,Superseded)

  risk_treatments
    id, tenant_id, risk_id, strategy enum(Avoid,Reduce,Transfer,Accept,Exploit),
    title, description, owner_id, approver_id, target_rating,
    cost_minor bigint, currency, benefit_description, start_at, due_at,
    status enum(Proposed,Approved,In Progress,Implemented,Verified,Overdue,
    Cancelled), progress tinyint, last_update_at, verification_notes,
    verified_by, verified_at, control_id NULL

  treatment_milestones
    id, treatment_id, title, due_at, status, completed_at, owner_id, sequence

  metrics                  -- the KRI/KPI engine
    id, tenant_id, metric_type enum(KRI,KPI,KCI,KPI_control),
    code, name, description, category_id, formula, unit,
    data_type enum(number,percentage,currency,ratio,count,duration),
    currency char(3) NULL, direction enum(higher_is_better,lower_is_better,
    target_is_best), frequency, owner_id, source
    enum(manual,integration,calculated), source_config json,
    calculation_expression, entity_id NULL, is_active, first_period, aggregation
    enum(sum,average,last,max,min)

  metric_thresholds
    id, metric_id, level enum(Green,Amber,Red,Critical), operator
    enum(gt,gte,lt,lte,between,outside), value_from decimal, value_to decimal,
    colour_hex, action_required, escalate_to_role_id, sort_order

  metric_values
    id, tenant_id, metric_id, period_label, period_start, period_end,
    value decimal(20,6), target decimal NULL, threshold_level_hit,
    breach_flag bool, variance_from_target, source enum(manual,integration,
    calculated), captured_by, captured_at, approved_by NULL, comment,
    supporting_evidence_id NULL

  metric_breaches
    id, metric_id, metric_value_id, level, detected_at, acknowledged_by,
    acknowledged_at, root_cause, action_taken, resolved_at,
    exception_id NULL, incident_id NULL, escalation_event_id NULL

  entity_links             -- the universal linkage graph
    id, tenant_id, source_type, source_id, target_type, target_id,
    relationship enum(mitigates,causes,indicates,governs,evidences,relates_to,
    escalates_to,depends_on), strength tinyint, notes, created_by, created_at

BUILD

10.1 RiskAssessmentService
  - Inherent → control effectiveness → residual, extending the existing
    ResidualRiskService rather than replacing it. Keep the current floor rule
    (no risk falls below 20% of inherent without effective controls).
  - Multi-dimensional impact: score each dimension, aggregate by a configurable
    weighting, keep the driving dimension visible.
  - Quantitative path: expected loss = likelihood % × financial impact; simple
    Monte Carlo (10,000 iterations, triangular or PERT distribution from
    min/likely/max) producing a loss-exceedance curve and VaR at 95/99%.
    Pure PHP, queued, cached — no external service.
  - Target risk and the gap between residual and target drives treatment
    prioritisation.

10.2 Heatmaps
  - Configurable N×N grid (default 5×5) driven by risk_assessment_scales, never
    hard-coded. Cell colours from the scale records.
  - Views: inherent, residual, target, and a residual-vs-target movement view
    with arrows.
  - Filter by category, entity, owner, process, framework, appetite breach.
  - Click a cell → the risk list behind it. Bubble size = financial impact.
  - Read the dataviz skill first; keep the palette accessible in light and dark
    and never encode meaning in colour alone (add a level label).

10.3 Risk appetite
  - Appetite statements per category and entity, with tolerance bands.
  - A live "appetite breach" indicator when residual score or a linked KRI
    exceeds tolerance, escalating through EscalationService.
  - Board-facing appetite dashboard: category × appetite × current position.

10.4 Treatment plans
  - Full lifecycle with milestones, cost/benefit, progress, overdue alerting,
    and verification by someone other than the owner (maker-checker).
  - "Status and alerts for risk treatment activities" is an explicit Corporater
    feature — make the alerting visible and configurable.

10.5 KRI/KPI engine
  - Definition, thresholds, manual and calculated capture, breach detection,
    trend charts (sparkline in tables, full chart on the metric page), and
    linkage to risks, controls, incidents and objectives.
  - A calculation expression evaluator over other metrics (safe, whitelisted
    functions only — never eval()).
  - Scheduled command atheris:evaluate-metrics — daily: computes calculated
    metrics, evaluates thresholds, opens breaches, escalates.

10.6 Linkage graph
  - entity_links is polymorphic across risk, control, metric, incident, policy,
    obligation, objective, document, exception.
  - A "Relationships" panel on every major record and a force-directed graph
    view for a selected risk showing everything connected to it, two hops out.
  - Keep it fast: cap nodes, paginate, render server-computed adjacency.

BUSINESS RULES
  - A risk assessment above a configurable threshold requires second-line review
    before it is published.
  - Risk acceptance (treatment strategy = Accept) requires Control Function Head
    approval with an expiry date, reusing the existing accept-risk pattern from
    ExceptionService.
  - A KRI breach at Red or Critical auto-creates an exception or an incident per
    tenant configuration, linked both ways.
  - Appetite statements are versioned and approved; changing one supersedes
    rather than edits.

TESTS
  - Residual calculation matches the existing ResidualRiskService for legacy data
  - The 20%-of-inherent floor still holds
  - Monte Carlo is deterministic under a seeded RNG
  - Heatmap respects a custom 4×4 scale without code change
  - Threshold evaluation for every operator including between and outside
  - Calculated metric expression rejects unsafe input
  - KRI Red breach creates a linked exception
  - Appetite breach fires the escalation
  - Treatment verifier cannot be the treatment owner

DELIVERABLES
  ~12 migrations, 11 models, RiskAssessmentService, HeatmapService,
  AppetiteService, TreatmentService, MetricService, LinkageService,
  1 scheduled command, controllers, routes, React pages (Risks/Register,
  Risks/Heatmap, Risks/Show v2, Risks/Appetite, Treatments/*, Metrics/*,
  Metrics/Show with charts, Linkage graph component), seeders, tests.
```

---

## PHASE 11 — Policy, Incident, Complaints & Case Management
**Duration: ~4 weeks**

```
PHASE 11 — POLICY, INCIDENT, COMPLAINTS & CASE MANAGEMENT

OBJECTIVE
Add the three governance modules Corporater has and Atheris does not — policy
management, incident management connected to controls, and case/investigation
management — plus a Nigeria-specific complaints module built around CBN's
Consumer Protection Regulations, which no competitor has.

READ FIRST
  Phase 9's documents and attestations tables, app/Services/EscalationService.php,
  app/Services/ExceptionService.php (the state-machine and SoD patterns),
  the CBN-CPF content pack from Phase 8.

DATA MODEL

  policies
    id, tenant_id, policy_ref, title, description, policy_type
    enum(policy,procedure,standard,guideline,charter,code),
    category_id, document_id NULL, owner_id, approver_id, version_no,
    status enum(Draft,Under Review,Pending Approval,Approved,Published,
    Under Revision,Superseded,Withdrawn),
    effective_from, effective_to NULL, review_frequency, next_review_at,
    applies_to json, requires_attestation bool, attestation_frequency,
    supersedes_policy_id NULL, approved_at, published_at, withdrawal_reason,
    mandatory_training bool

  policy_sections
    id, policy_id, parent_id NULL, sequence, heading, body longtext,
    is_mandatory, control_ids json, requirement_ids json

  policy_exceptions       -- someone needs to deviate from a policy
    id, tenant_id, policy_id, requested_by, entity_id, justification,
    risk_assessment, compensating_measures, requested_from, requested_to,
    status enum(Requested,Under Review,Approved,Rejected,Expired,Revoked),
    approved_by, approved_at, review_at, revoked_reason

  incidents
    id, tenant_id, incident_ref, title, description, incident_type
    enum(operational,security,cyber,fraud,compliance,data_breach,
    health_safety,conduct,third_party,continuity,other),
    basel_event_type enum(Internal Fraud,External Fraud,Employment Practices,
    Clients Products & Business Practices,Damage to Physical Assets,
    Business Disruption & System Failures,Execution Delivery & Process Mgmt) NULL,
    severity enum(Low,Medium,High,Critical), occurred_at, detected_at,
    detection_method, reported_at, reported_by, entity_id, process_id,
    status enum(Reported,Triaged,Under Investigation,Contained,Remediated,
    Closed,Reopened),
    gross_loss_minor bigint, recovery_minor bigint, net_loss_minor bigint,
    currency char(3), near_miss bool,
    is_reportable bool, regulator_ids json, notification_due_at,
    notified_at, notification_reference,
    root_cause, root_cause_category, contributing_factors json,
    lessons_learned, owner_id, investigator_id, closed_by, closed_at,
    control_failure_ids json, risk_ids json

  incident_actions
    id, incident_id, action_type enum(containment,corrective,preventive,
    communication,regulatory), title, description, owner_id, due_at,
    status, completed_at, verified_by, verified_at, evidence_id NULL

  incident_timeline
    id, incident_id, occurred_at, actor_id, event_type, description,
    is_material bool

  complaints                -- CBN Consumer Protection Regulations
    id, tenant_id, complaint_ref, tracking_id, channel
    enum(letter,email,phone,social_media,branch,app,ussd,web,whatsapp,
    regulator_referral),
    received_at, acknowledged_at, acknowledgement_due_at,
    customer_name, customer_reference, customer_contact json,
    category_id, subject, description, product, amount_disputed_minor,
    currency, entity_id, branch, assigned_to,
    status enum(Received,Acknowledged,Under Investigation,Awaiting Customer,
    Resolved,Closed,Escalated to Regulator,Reopened),
    resolution_due_at, resolved_at, resolution_summary, resolution_type
    enum(upheld,partially_upheld,not_upheld,withdrawn),
    redress_amount_minor, redress_currency, customer_satisfied bool NULL,
    sla_breached bool, days_open, penalty_exposure_minor,
    escalated_to_regulator bool, regulator_reference, root_cause_id,
    linked_incident_id NULL, linked_control_id NULL

  complaint_activities
    id, complaint_id, occurred_at, actor_id, activity_type, description,
    channel, is_customer_visible bool

  cases                     -- investigations, whistleblowing, disciplinary
    id, tenant_id, case_ref, case_type enum(investigation,whistleblowing,
    disciplinary,fraud,conflict_of_interest,regulatory_enquiry,litigation),
    title, description, confidentiality enum(Standard,Restricted,Highly
    Restricted), is_anonymous bool, reporter_id NULL, reporter_contact_token,
    received_at, channel, entity_id, subject_persons json,
    status enum(Received,Assessed,Under Investigation,Substantiated,
    Unsubstantiated,Referred,Closed), severity, lead_investigator_id,
    investigation_plan, findings, conclusion, actions_taken,
    referred_to, closed_by, closed_at, related_incident_id NULL,
    related_complaint_id NULL, access_user_ids json

  case_notes
    id, case_id, author_id, note, is_privileged bool, created_at

BUILD

11.1 Policy management
  - Full lifecycle with maker-checker approval, section-level structure,
    control and requirement linkage, publication, scheduled review, supersession.
  - Attestation campaigns reusing Phase 9's machinery; a policy that requires
    attestation automatically opens a campaign on publication.
  - Policy exception (waiver) workflow with expiry, review and revocation.
  - Policy gap analysis: framework requirements with no governing policy.

11.2 Incident management
  - Intake from multiple channels, triage by severity, investigation with a
    timeline, containment and corrective actions, root-cause analysis,
    loss capture (gross/recovery/net, Basel event type for operational risk
    capital), lessons learned.
  - Control failure linkage: an incident names the control(s) that failed,
    which flags those controls for re-testing and can auto-open an exception.
  - Regulatory notification engine: given incident_type, severity and the
    tenant's regulators, compute notification obligations and due times from the
    Phase 8 obligation records — e.g. NDPC breach notification within 72 hours
    under GAID Art. 33. Show a live countdown. Do not hard-code the window;
    read it from the obligation record.
  - Near-miss capture, because near misses are the leading indicator.

11.3 Complaints (CBN Consumer Protection)
  - Omni-channel intake with automatic unique tracking-ID generation.
  - A 24-hour acknowledgement SLA clock, computed in the tenant's timezone,
    with an escalation ladder before breach.
  - Live penalty exposure: ₦500,000 per complaint per week unresolved,
    ₦2,000,000 for acknowledgement failure — read from the CBN-CPF obligation
    record's penalty fields, never hard-coded.
  - Root-cause categorisation feeding a "complaints by root cause" analysis that
    links back to the failing control or process.
  - CBN CPD returns export.

11.4 Case and whistleblowing management
  - Strict confidentiality: case access is an explicit allowlist
    (access_user_ids), enforced in the policy AND the query scope. A System
    Administrator does NOT get automatic access — this is a deliberate
    exception to normal admin reach, required for whistleblowing integrity.
  - Genuine anonymity: an anonymous report stores no reporter identity. Provide
    a one-way reporter token so the reporter can check status and respond
    without being identified.
  - Note: WhatsApp intake conflicts with anonymity requirements under NCCG
    Recommended Practice 19 and CBN whistleblowing guidance. If a case arrives
    via WhatsApp (Phase 15), route it through an anonymising bridge that strips
    the phone number before persistence, and record that it was anonymised.
  - Investigation plan, privileged notes, findings, substantiation outcome,
    referral, board reporting extract.

11.5 Cross-module linkage
  - Incident ↔ control ↔ risk ↔ KRI ↔ policy ↔ obligation ↔ complaint ↔ case,
    all through Phase 10's entity_links.
  - "Linking KRIs, incidents, risks, policies" is an explicit Corporater
    feature — make it a first-class, navigable graph, not a foreign key.

BUSINESS RULES
  - An incident cannot close with open mandatory actions or an unmet regulatory
    notification.
  - Complaint acknowledgement recording is timestamped server-side only; the
    client cannot supply the time.
  - Case access is allowlist-only with no admin bypass; every access is logged.
  - Anonymous cases and anonymous survey responses can never be de-anonymised.
  - Policy publication requires an approver different from the owner.

TESTS
  - The 24-hour acknowledgement clock across timezone and DST edges
  - Penalty exposure accrual per week, partial weeks rounded per the regulation
  - A System Administrator not on the case allowlist gets 403 (explicit test)
  - Anonymous case stores no reporter identity (assert on the row)
  - Incident cannot close with an open mandatory action
  - Regulatory notification due time is read from the obligation record, and
    changing the obligation changes the countdown
  - Policy attestation campaign auto-opens on publication
  - Policy approver ≠ policy owner

DELIVERABLES
  ~14 migrations, 12 models, PolicyService, IncidentService, ComplaintService,
  CaseService, NotificationObligationResolver, controllers, routes, React pages
  (Policies/*, Incidents/*, Complaints/*, Cases/*, plus a public/authenticated
  whistleblowing intake page), seeders, tests, README update.
```
---

## PHASE 12 — Continuous Controls Monitoring & Connectors
**Duration: ~5 weeks · Corporater's "continuous and ad-hoc monitoring of controls"**

```
PHASE 12 — CONTINUOUS CONTROLS MONITORING & DATA INTEGRATION

OBJECTIVE
Move control testing from periodic-manual to continuous-automated. Build a
connector framework, a rule engine that tests controls against real data on a
schedule, and connectors for the systems Nigerian and African institutions
actually run — Finacle and FLEXCUBE (together ~67% of Nigerian banks), T24,
BankOne, NIBSS, SAP, Dynamics 365, Sage, Microsoft 365 / Entra ID and FIRS.

READ FIRST
  app/Services/IntegrationService.php, app/Models/{IntegrationConfig,
  IntegrationSyncLog}.php, app/Http/Controllers/Api/IntegrationApiController.php,
  app/Http/Middleware/AuthenticateIntegration.php, docs/openapi.yaml,
  app/Services/TestingService.php

DATA MODEL

  data_sources
    id, tenant_id, name, source_type enum(rest_api,soap,database,sftp,
    file_upload,webhook,odata,graphql,jdbc), system_category
    enum(core_banking,erp,hrms,itsm,identity,payments,tax,crm,ticketing,
    log,spreadsheet,other), vendor_key
    enum(finacle,flexcube,t24,bankone,imal,bancs,nibss,interswitch,remita,
    sap,dynamics365,sage,odoo,m365,entra,servicenow,freshservice,firs_mbs,
    custom),
    connection_config json (encrypted), auth_type enum(none,basic,api_key,
    oauth2,certificate,jwt), credentials_ref (points at the encrypted store),
    schedule_cron, timezone, is_active, last_sync_at, last_sync_status,
    consecutive_failures, health_status enum(Healthy,Degraded,Failed,Unknown),
    data_residency_note, owner_id, approved_by, approved_at

  data_source_datasets
    id, data_source_id, name, description, extraction_config json
    (query/endpoint/path/pagination), schema_definition json,
    primary_key_fields json, incremental_field, retention_days, row_count_last,
    pii_classification enum(none,internal,personal,sensitive_personal),
    is_active

  data_snapshots
    id, tenant_id, dataset_id, snapshot_ref, period_label, captured_at,
    row_count, checksum, storage_path, status enum(Capturing,Ready,Failed,
    Expired,Purged), error_message, size_bytes

  monitoring_rules
    id, tenant_id, control_id, name, description, rule_type
    enum(threshold,reconciliation,duplicate,gap,sod_conflict,exception_list,
    completeness,timeliness,trend,pattern,custom_sql),
    dataset_ids json, rule_definition json, severity, frequency, schedule_cron,
    sample_config json, tolerance json,
    exception_template json, auto_create_exception bool,
    auto_create_incident bool, owner_id, approved_by, approved_at,
    status enum(Draft,Pending Approval,Active,Paused,Retired),
    version_no, last_run_at, next_run_at

  monitoring_runs
    id, tenant_id, rule_id, run_ref, started_at, completed_at,
    status enum(Queued,Running,Completed,Failed,Partial),
    records_evaluated, records_passed, records_failed, exception_rate,
    result_summary json, error_message, snapshot_ids json,
    test_instance_id NULL, duration_ms

  monitoring_findings
    id, run_id, record_identifier, record_data json (PII-redacted per the
    dataset's classification), failure_reason, severity, status
    enum(Open,Under Review,Confirmed,False Positive,Remediated,Accepted),
    reviewed_by, reviewed_at, review_notes, exception_id NULL, incident_id NULL

  sod_conflict_rules
    id, tenant_id, name, description, system_key, function_a, function_b,
    conflict_type enum(create_approve,create_pay,maintain_reconcile,
    request_authorise,custom), risk_level, mitigating_control_id NULL,
    is_active

  sod_violations
    id, tenant_id, rule_id, subject_identifier, subject_name, entity_id,
    detected_at, snapshot_id, status enum(Open,Mitigated,Accepted,Remediated,
    False Positive), mitigation_notes, accepted_by, accepted_until,
    remediated_at, exception_id NULL

BUILD

12.1 Connector framework
  - Abstract App\Integrations\Contracts\Connector with authenticate(),
    testConnection(), listDatasets(), extract(dataset, since), and a capability
    descriptor. One class per vendor in app/Integrations/{Vendor}/.
  - Credentials encrypted at rest with Laravel's encrypter, never logged, never
    returned to the frontend (write-only fields, masked display).
  - Every extraction writes an IntegrationSyncLog entry (reuse the existing
    table and replay mechanism).
  - Circuit breaker: N consecutive failures pauses the source, notifies the
    owner and marks health Failed. Exponential backoff on retry.
  - Rate limiting per source. Timeouts. Partial-extract resumption.

12.2 Connectors to implement
  Priority 1 (build fully):
    - Generic REST/JSON with configurable auth, pagination and JSONPath mapping
    - Generic SQL (MySQL/PostgreSQL/SQL Server/Oracle) read-only
    - SFTP + CSV/fixed-width with a schema definition
    - Microsoft 365 / Entra ID (Graph API): users, groups, privileged roles,
      sign-in logs, MFA registration — feeds access controls and SoD
    - Manual/spreadsheet upload with the Phase 9 import validation pipeline
  Priority 2 (build with a documented mapping, stub the transport if no test
  environment is available — mark clearly as untested):
    - Finacle (Infosys) — the largest Nigerian footprint (~37%, 11 banks)
    - Oracle FLEXCUBE (~30%, 9 banks)
    - Temenos T24 (4 banks, and the CBN itself)
    - BankOne (Appzone) for the MFB/OFI tier
    - NIBSS (NIP, BVN, GSI, NQR)
    - SAP (OData/RFC), Microsoft Dynamics 365 (Dataverse), Sage (300/X3/Evolution)
    - FIRS Merchant Buyer Solution for e-invoicing controls
  Every connector ships with: a capability matrix, a field-mapping template, a
  connection test, and clear documentation of what it does and does not cover.

12.3 Rule engine
  - Rule types, each with a JSON schema for rule_definition:
      threshold        — aggregate vs limit
      reconciliation   — dataset A vs dataset B on key, tolerance
      duplicate        — fuzzy or exact key duplication
      gap              — missing sequence numbers, missing days
      sod_conflict     — evaluated against sod_conflict_rules
      exception_list   — rows matching a filter are exceptions
      completeness     — required fields populated, expected row count
      timeliness       — records within an expected latency
      trend            — period-over-period movement beyond tolerance
      pattern          — Benford, round-number bias, off-hours activity,
                         same-day create-and-approve
      custom_sql       — parameterised, read-only, whitelisted, sandboxed
  - Sampling: full population, random n, stratified, monetary-unit sampling —
    with a reproducible seed recorded on the run.
  - Rules are maker-checker approved before activation, and versioned.

12.4 Continuous testing
  - A monitoring rule linked to a control produces TestInstance records
    automatically, so continuous results flow into the existing effectiveness
    rating machinery rather than a parallel universe.
  - Failed findings create exceptions through the existing ExceptionService,
    preserving auto-exception behaviour and SoD.
  - A false-positive workflow feeds back into rule tuning, with the tuning
    change itself maker-checker approved.

12.5 Scheduled commands
  - atheris:sync-data-sources     — per-source cron, queued, Horizon-backed
  - atheris:run-monitoring-rules  — per-rule cron
  - atheris:purge-snapshots       — retention enforcement, respecting legal hold

12.6 CCM dashboard
  - Source health, last sync, rows ingested, rule pass rates, open findings by
    severity, exception rate trend, top failing rules, coverage (what % of key
    controls have an automated rule).

12.7 Data protection
  - Every dataset declares a pii_classification. Sensitive personal data is
    hashed or tokenised at ingestion unless the tenant's DPO has explicitly
    authorised retention, recorded as an approval.
  - Snapshots inherit the Phase 5 retention and legal-hold machinery.
  - No extracted data leaves the tenant's country data plane. Assert this in a
    test.

BUSINESS RULES
  - A monitoring rule cannot activate without approval by someone other than
    its author.
  - custom_sql is read-only, parameterised, statement-timeout-bounded, and
    blocked from system tables. Validate with a parser, not a regex denylist.
  - Automated results never override a human rating without approval.
  - Purging a snapshot under legal hold is impossible at the model layer.

TESTS
  - Each rule type against fixture datasets with known expected outcomes
  - Sampling reproducibility with a fixed seed
  - Circuit breaker opens after N failures and pauses the source
  - Credentials never appear in logs, exceptions, API responses or Inertia props
  - custom_sql rejects writes, DDL, system tables and cross-database references
  - Findings create exceptions with correct control linkage
  - PII classification triggers hashing at ingestion
  - Legal hold blocks snapshot purge

DELIVERABLES
  ~10 migrations, 9 models, the connector framework and connector classes,
  RuleEngineService, MonitoringService, SamplingService, SnapshotService,
  3 scheduled commands, controllers, routes, React pages (Admin/DataSources,
  Monitoring/Rules, Monitoring/Runs, Monitoring/Findings, Monitoring/Dashboard,
  Sod/Conflicts, Sod/Violations), seeders with realistic fixture data, tests,
  README + openapi.yaml updates.
```

---

## PHASE 13 — Dashboards, Analytics & Reporting v2 ⭐
**Duration: ~4 weeks · Includes the regulator submission generator**

```
PHASE 13 — DASHBOARDS, ANALYTICS & REPORTING v2

OBJECTIVE
Replace the single fixed dashboard with a configurable dashboard builder, add a
proper chart layer, build a report designer that outputs Word, Excel, PowerPoint
and PDF, and — the commercially decisive piece — generate the actual regulatory
submissions Nigerian institutions must file.

READ FIRST
  app/Services/{DashboardService,ReportService,ExcelExportService}.php,
  resources/js/Pages/Dashboard.jsx, resources/views/reports/,
  app/Models/ReportTemplate.php,
  AND the dataviz skill — read it before writing a single line of chart code.

  Match the information density of the client's Corporater screenshots:
  stat tiles with a single large number, a distribution tree with inline
  progress, grouped bar charts by business unit, horizontal bar charts by
  country, and tabbed record pages (Overview / Implementation details / Tests).

DATA MODEL

  dashboards
    id, tenant_id, name, description, slug, owner_id, visibility
    enum(private,role,tenant,public_link), role_ids json, layout json,
    refresh_interval_seconds, is_default_for_role_id NULL, is_system bool,
    sort_order

  dashboard_widgets
    id, dashboard_id, widget_type enum(stat,line,bar,stacked_bar,horizontal_bar,
    pie,donut,heatmap,gauge,table,tree,timeline,progress,sparkline,map,
    scorecard,text), title, subtitle, data_source_key, query_config json,
    display_config json, drill_down_config json, position json
    (x,y,w,h), refresh_seconds, permission_required, is_visible

  report_definitions        -- extends, does not replace, report_templates
    id, tenant_id, code, name, description, report_type
    enum(operational,management,board,regulatory,ad_hoc),
    output_formats json (pdf,docx,xlsx,pptx,html,csv),
    sections json, parameters json, styling json, cover_page_config json,
    header_config, footer_config, owner_id, approved_by, approved_at,
    version_no, is_system, regulator_id NULL, obligation_id NULL

  report_schedules
    id, tenant_id, report_definition_id, name, cron, timezone, parameters json,
    output_format, recipients json, delivery_method enum(email,in_app,sftp,
    api,download_only), is_active, last_run_at, next_run_at, last_status

  report_runs
    id, tenant_id, report_definition_id, schedule_id NULL, run_ref,
    parameters json, requested_by, started_at, completed_at, status,
    output_path, output_format, file_size, page_count, checksum,
    distributed_to json, error_message, expires_at

  submission_packs          -- the regulator-shaped output
    id, tenant_id, obligation_instance_id, pack_type, period_label,
    status enum(Draft,Under Review,Approved,Submitted,Acknowledged,
    Rejected,Resubmitted), generated_at, generated_by,
    content json, evidence_ids json, completeness_score,
    unverified_items json, validation_errors json,
    reviewed_by, reviewed_at, approved_by, approved_at,
    submitted_at, submitted_by, submission_reference,
    acknowledgement_path, acknowledgement_ref, rejection_reason

BUILD

13.1 Chart layer
  - Choose ONE chart library and use it everywhere: Recharts (React-native,
    reasonable bundle) unless the dataviz skill's guidance indicates otherwise.
  - Build wrapper components in resources/js/Components/Charts/: StatTile,
    LineChart, BarChart, StackedBarChart, HorizontalBarChart, DonutChart,
    HeatmapChart, GaugeChart, Sparkline, ProgressRing, TreeTable.
  - One design system across all of them, per the dataviz skill: a validated
    categorical palette, accessible in light and dark, never colour-alone
    encoding, consistent axis and tooltip behaviour, and empty/loading/error
    states on every chart.
  - Lazy-load charts below the fold and skip animations in low-bandwidth mode
    (Phase 7 setting). Chart bundle must not blow the 250KB budget — code-split.

13.2 Dashboard builder
  - Drag-and-drop grid layout (react-grid-layout), widget palette, per-widget
    configuration (data source, filters, grouping, time range, thresholds,
    drill-down target).
  - A registry of data source keys in App\Dashboards\Sources — each returns a
    typed, permission-scoped, tenant-scoped, cached dataset. Widgets can only
    reference registered keys; no arbitrary queries from the frontend.
  - Ship pre-built system dashboards, one per role:
      Executive / Board       — control health, top risks, appetite position,
                                open critical exceptions, regulatory calendar
                                pressure, penalty exposure, incident losses
      Control Function Head   — testing pipeline, overdue tests, exception
                                ageing, CSA response rates, coverage by framework
      Control Officer         — my tests, my exceptions, my findings, due today
      Control Owner           — my controls, my attestations, my actions
      Compliance Officer      — obligation calendar, submissions, changes,
                                complaints SLA, penalty exposure
      Risk Officer            — heatmaps, appetite breaches, KRI status,
                                treatment progress, incident trends
      Internal Audit / Third line — coverage, reliance map, open issues
  - Design and Operating Effectiveness dashboards as an explicit pair, since
    Corporater lists them as a named feature.
  - Drill-down: every number is clickable and lands on a filtered list.
  - Org-tree rollup: any widget can aggregate up the OrganisationUnit hierarchy,
    with a breadcrumb to drill down — this is the "by individual, team,
    department, business unit, entire organisation" capability.

13.3 Report designer
  - Section types: cover, table of contents, narrative text (with variable
    interpolation), table, chart, KPI row, page break, appendix, signature block.
  - Parameterised: period, entity, framework, risk category, owner.
  - Output engines:
      PDF   — dompdf (already present); a Blade layout per report
      Excel — PhpSpreadsheet (already present), with formatting and charts
      Word  — add phpoffice/phpword
      PPT   — add phpoffice/phppresentation
    Read the docx, xlsx and pptx skills before implementing those three.
  - Branding from Phase 7 flows into headers, footers and cover pages.
  - Scheduled generation and distribution; secure expiring download links.

13.4 Regulatory submission packs ⭐ THE DIFFERENTIATOR
  Build a generator per submission type. Each pulls live data, validates
  completeness, flags unverified content, and produces a reviewable, approvable,
  filable document.

  (a) FRC/CG/001 — NCCG Corporate Governance Return
      Sections A–E. Section E walks all 28 principles with a Yes/No plus a
      narrative explanation of application or deviation. "N/A" is PROHIBITED —
      the generator must block submission if any principle is unanswered or
      answered N/A. Collects board composition, appointment dates, per-director
      and per-committee meeting attendance, senior management with positions and
      gender, incorporation and RC details, auditors and registrars.
      Four signature blocks: Chairman, MD/CEO, Governance Committee Chair,
      Company Secretary.

  (b) NDPC Compliance Audit Return (GAID 2025 Art. 10)
      Due 31 March annually; new entities within 15 months of commencing
      business. Tier determination (UHL/EHL/OHL) drives whether a return is
      required and the fee band (₦100,000–₦1,000,000 by data volume). Pulls
      RoPA, DPIA register, breach register with 72-hour compliance evidence,
      DPO details and independence attestation, cross-border transfer register,
      processor/DPA register, training records. Flags the licensed DPCO
      requirement for UHL/EHL. Shows the 50% late-filing penalty exposure.

  (c) FRC ICFR Management Report (2024 guidance) — Nigeria's SOX-404
      Statement of management responsibility; identification of the evaluation
      framework (COSO 2013); the effectiveness assessment as at fiscal year end;
      disclosure of any material weaknesses; and the statement that the external
      auditor issued an attestation report. Driven by the top-down scoping:
      significant accounts → processes → risks → controls → test results →
      deficiency evaluation and aggregation → conclusion. Implement the
      three-tier taxonomy (control deficiency / significant deficiency /
      material weakness) with an aggregation rule that escalates multiple
      significant deficiencies in one area to a material weakness for
      human review.

  (d) CBN Internal Audit External Assessment pack (due 31 May)
      Audit universe, risk-based plan and its execution, issue tracking and
      closure rates, QAIP evidence, independence and reporting-line evidence,
      the external assessor's report.

  (e) NDIC DPAS management-factor pack
      Return-submission timeliness record, examiner-recommendation closure
      status, internal control effectiveness summary, risk management framework
      status — and a computed basis-point impact against the DPAS add-on
      schedule, so the board sees the premium consequence in money.

  (f) SEC Nigeria corporate governance report + quarterly/annual returns tracker

  Cross-cutting rules for every pack:
    - completeness_score computed before submission; below a configurable
      threshold blocks the "Submit" action
    - any content sourced from an obligation or requirement with
      verification_status != 'verified' is listed in unverified_items and
      excluded from the generated document
    - maker-checker: generated → reviewed → approved → submitted, with
      different people at review and approve
    - the submitted pack, its evidence, its checksum and its acknowledgement are
      retained under the Phase 5 retention machinery, immutably

13.5 Analytics
  - Trend analysis on control effectiveness, exception ageing, incident
    frequency and severity, complaint SLA performance, KRI movement.
  - Comparative: entity vs entity, period vs period, actual vs appetite.
  - Predictive-lite (deterministic, not AI): controls most likely to fail next
    period based on history, exception recurrence probability, obligations at
    risk of being missed based on current progress.

BUSINESS RULES
  - A dashboard widget can never expose data the viewer lacks permission to see;
    filter at the query layer, not the render layer, and test it.
  - Report distribution respects confidentiality classification.
  - A submission pack cannot be submitted without approval by someone other than
    the generator and the reviewer.
  - Generated documents are immutable once approved; a change requires a new
    version.

TESTS
  - Widget queries are tenant- and permission-scoped (explicit negative test per
    system widget)
  - Drill-down filters match the aggregate they came from (no drift)
  - FRC/CG/001 generation blocks on an unanswered or N/A principle
  - NDPC CAR includes every required register and computes the correct fee band
  - ICFR deficiency aggregation escalates correctly
  - Submission with an unverified item excludes it and lists it
  - Approver ≠ reviewer ≠ generator
  - Each output format renders without error for every system report
  - No N+1 on any dashboard (assert query count)

DELIVERABLES
  ~6 migrations, 5 models, chart component library, DashboardBuilderService,
  WidgetRegistry, ReportDesignerService, 4 output engines, 6 submission pack
  generators, scheduled report command, controllers, routes, React pages
  (Dashboards/*, Dashboards/Builder, Reports/Designer, Reports/Library,
  Reports/Schedules, Submissions/*), seeders for system dashboards and reports,
  tests, README update.
```

---

## PHASE 14 — AI Layer (Anthropic) ⭐
**Duration: ~5 weeks · Read Part C in full before starting**

```
PHASE 14 — ARTIFICIAL INTELLIGENCE LAYER

OBJECTIVE
Add a native, governed, auditable AI layer built on the Anthropic API that makes
scarce second-line expertise scale — matching and exceeding Corporater's AI
capability set (AI-Powered Risk Intelligence, Automated Compliance & Audit,
Data-Driven Decision Intelligence, AI-Assisted Configuration, Intelligent
Incident Management, Automated Vendor Screening, and the Tiva assistant).

READ PART C OF THIS PROMPT PACK IN FULL BEFORE WRITING ANY CODE.
It specifies the gateway architecture, the governance model, the prompt
registry, the RAG design and every capability's contract.

ABSOLUTE RULES (architectural, not advisory)
  1. AI NEVER DECIDES. Every output is a draft requiring explicit human
     approval. AI must never approve a control, close an exception, sign an
     attestation, rate effectiveness or submit a filing. Enforce this in
     AiGateway — make it structurally impossible, not merely undone.
  2. EVERY AI INTERACTION IS AUDITED like a user action: prompt version, model,
     input hash, retrieved context ids, output, tokens, cost, latency, the
     reviewing human and their decision.
  3. PII IS REDACTED BEFORE EGRESS. No customer name, account number, BVN, NIN,
     phone, email or address leaves the tenant boundary. Redaction is a
     pipeline stage that cannot be skipped, and it is tested.
  4. THE API KEY LIVES IN .env ONLY, read via config/services.php. Never in
     code, seeders, tests, prompts, commits or the client bundle. Add
     ANTHROPIC_API_KEY= to .env.example with no value.
  5. COST IS BOUNDED. Per-tenant monthly token budget, per-user rate limits,
     per-capability caps, and a hard stop with a clear message at the limit.

BUILD (summary — Part C has the detail)

14.1 Infrastructure
  ai_configurations, ai_prompts (versioned registry), ai_interactions (the audit
  log), ai_feedback, ai_knowledge_chunks (RAG index), ai_budgets.
  App\Services\Ai\AiGateway with the pipeline:
    capability check → budget check → rate limit → context assembly →
    PII redaction → prompt render → Anthropic call (retry, backoff, timeout) →
    response parse and schema validation → confidence scoring →
    citation resolution → audit write → return as DRAFT

14.2 The nine capabilities
  1. Control drafting & RCM generation — from a risk, process or framework
     requirement, draft control objective, description, type, nature, frequency,
     evidence requirements and a test script with check items
  2. Regulatory intelligence — parse a circular/PDF into a draft
     regulatory_change with proposed obligation additions and diffs against
     existing obligations, and an impact list of affected controls
  3. Risk intelligence — draft risk statements from incidents, complaints,
     losses and external signals; suggest causes, consequences and KRIs;
     identify emerging risk themes across the register
  4. Test evidence review — assess whether attached evidence supports the check
     item's assertion; flag missing, stale, illegible or contradictory evidence.
     Advisory only; never sets a result
  5. Exception triage & root cause — classify, cluster recurring exceptions,
     propose root cause and remediation, draft the remediation plan
  6. Narrative generation — draft the management commentary sections of reports
     and submission packs, grounded strictly in retrieved data with citations
  7. Framework mapping assistant — propose cross-framework mappings with
     rationale and coverage; every proposal goes to the maker-checker queue
  8. Vendor/third-party screening — summarise a vendor's risk profile from
     supplied documents and structured data; draft due-diligence questions
  9. Atlas — the conversational assistant (our Tiva equivalent), RAG-grounded
     over the tenant's own controls, risks, obligations, policies, incidents and
     test results, permission-filtered at retrieval time, always citing the
     records it used, with an explicit "I don't have that" when retrieval
     returns nothing

14.3 RAG
  Chunk and embed controls, risks, policies, obligations, framework
  requirements, incidents, exceptions and documents. Store embeddings per
  tenant. Retrieval is permission-filtered BEFORE the model sees anything —
  a user must never receive a synthesised answer built from records they cannot
  open. Re-index on change via a queued listener.

14.4 AI governance
  A dedicated admin section: model registry, prompt version history with diffs,
  per-capability enable/disable, budget and usage, human-override rate,
  acceptance rate per capability, and an exportable AI activity log.
  This exists partly to serve the product's own compliance obligations — draft
  King V's technology chapter explicitly covers AI governance (human oversight,
  ethics, transparency), and Ghana's 2026 BoG directive requires AI/ML
  governance for fraud and credit models. Being able to demonstrate our own AI
  governance is a sales asset.

14.5 UX
  AI actions appear as an "Assist" affordance next to the field or record they
  help with — never a separate AI area users must remember to visit. Every
  suggestion renders in a review panel with: the draft, its confidence, its
  citations, Accept / Edit / Reject, and a required reason on reject that feeds
  ai_feedback.

TESTS
  - PII redaction removes every configured pattern; a test asserts no raw PII in
    the outbound payload (mock the HTTP client and inspect the body)
  - An AI response cannot transition any record's status (attempt it, assert 403
    or an exception)
  - Budget exhaustion returns a clear error and writes no interaction
  - Retrieval never returns a record the user lacks permission to view
  - Prompt version is recorded on every interaction
  - Malformed model output is rejected by schema validation, retried, then fails
    gracefully
  - Rate limiting per user and per tenant
  - The API key never appears in any log, response or serialised prop

DELIVERABLES
  6 migrations, 6 models, AiGateway + 9 capability services, RedactionService,
  RetrievalService, PromptRegistry, EmbeddingService, queued indexing listener,
  controllers, routes, React components (AiAssistButton, AiReviewPanel,
  AtlasChat drawer) and pages (Admin/Ai/*), seeded prompts, tests, README +
  .env.example updates.
```

---

## PHASE 15 — Mobile, Offline & Omnichannel
**Duration: ~3 weeks · The Africa-reality phase**

```
PHASE 15 — MOBILE, OFFLINE & OMNICHANNEL

OBJECTIVE
Make Atheris genuinely usable by a control owner in a branch on a mid-range
Android over 4G with unreliable power — and reachable on the channel Nigerian
business actually runs on. This is a capability no global GRC vendor offers and
it directly drives the adoption rate that determines renewal.

READ FIRST
  Phase 7's PWA shell and NotificationDispatcher, Phase 9's attestations and
  CSA responses, Phase 11's complaints and cases (note the anonymity
  constraint), app/Services/EvidenceService.php

BUILD

15.1 Offline-capable PWA
  - IndexedDB local store for: assigned tasks, my controls, open test instances,
    attestation requests, CSA questionnaires, and draft evidence.
  - A durable outbox queue with idempotency keys; background sync when
    connectivity returns; conflict resolution with a clear "server changed this"
    prompt rather than a silent overwrite.
  - Offline-capable actions: complete an attestation, answer a CSA
    questionnaire, record a check result, capture evidence (photo/file), add a
    comment. Explicitly NOT offline-capable: any approval, any status
    transition requiring SoD validation, any submission. Those require the
    server, and the UI must say so.
  - A visible sync status indicator with pending count and last-sync time.

15.2 Mobile-optimised interfaces
  - A dedicated mobile task view: "what do I owe, by when", one card per item,
    one tap to act.
  - Camera evidence capture with client-side compression (target ≤ 500KB per
    image), EXIF stripping for privacy, optional geotag only where the tenant
    has enabled it for spot checks.
  - Resumable chunked upload for anything over 2MB.
  - Every form autosaves to local storage on change — power cuts must not cost
    work.
  - Touch targets ≥ 44px, thumb-reachable primary actions, no hover-dependent UI.

15.3 WhatsApp Business Cloud API
  - Table whatsapp_templates: key, name, language, category, body, variables,
    meta_template_id, approval_status. Templates must be pre-approved by Meta —
    build the sync and status display.
  - Use cases: attestation reminder with a one-tap confirmation deep link;
    overdue task nudge; approval request notification; evidence request;
    incident and complaint acknowledgement to a customer; policy acknowledgement.
  - Handle the 24-hour session window correctly: template message outside it,
    free-form inside it.
  - Inbound webhook for replies, with signature verification.
  - HARD CONSTRAINT: WhatsApp must never be a whistleblowing intake channel that
    exposes identity. If a case arrives via WhatsApp, an anonymising bridge
    strips the phone number before persistence and records that anonymisation
    occurred. NCCG Recommended Practice 19 and CBN whistleblowing guidance
    require genuine anonymity.
  - NDPA note: message content traverses Meta infrastructure. Never send
    customer personal data or confidential case content over WhatsApp — send a
    notification and a link, never the substance. Enforce in the dispatcher.

15.4 SMS and USSD fallback
  - SMS via a local aggregator (Termii, Africa's Talking or similar) for
    reminders where WhatsApp is unavailable. Templates, delivery receipts,
    cost tracking per tenant.
  - USSD is optional and only worth it for a specific high-volume attestation
    use case — build the interface behind a feature flag; do not block the phase
    on it.

15.5 Push notifications
  - Web Push (VAPID) for PWA users. Preference-driven through the Phase 7
    dispatcher.

15.6 Low-bandwidth mode (completing Phase 7's stub)
  - Server-side: smaller page sizes, no eager relations beyond what renders,
    optional field trimming on list endpoints.
  - Client-side: no chart animation, deferred chart loading, system fonts only,
    reduced image quality, table-over-card layouts.
  - Measure it: add a build-time bundle budget check that fails CI if any route
    chunk exceeds 250KB gzipped.

BUSINESS RULES
  - No approval or SoD-gated transition may be performed offline.
  - Queued offline actions are validated server-side on sync exactly as if
    submitted online; a rejected action surfaces to the user with the reason.
  - WhatsApp and SMS carry notifications and links only, never confidential
    content.
  - Anonymous channels stay anonymous end to end.

TESTS
  - Outbox replays in order and is idempotent under duplicate submission
  - A conflicting server change surfaces a prompt and does not overwrite
  - An offline-queued approval is rejected on sync with a clear reason
  - WhatsApp payloads contain no personal data (assert on the outbound body)
  - The anonymising bridge strips identity before persistence
  - Template messages are used outside the 24-hour window
  - Route chunks stay within budget (build-time assertion)

DELIVERABLES
  Service worker and offline store, sync engine, WhatsApp/SMS/Push channel
  drivers completing Phase 7's stubs, whatsapp_templates and message-log
  migrations, mobile page variants, camera and upload components, feature-flagged
  USSD interface, bundle budget CI check, tests, README update.
```

---

## PHASE 16 — Multi-Entity, Data Residency & Enterprise Readiness
**Duration: ~4 weeks · Makes the CBN 2027 deadline a selling point**

```
PHASE 16 — MULTI-ENTITY, DATA RESIDENCY & ENTERPRISE READINESS

OBJECTIVE
Support banking groups and holding companies with consolidated oversight across
subsidiaries, formalise per-country data residency into an auditable capability
we can sell against CBN's 1 January 2027 deadline and BoG's 2026 directive, and
harden the platform for tier-1 procurement.

READ FIRST
  app/Models/{Tenant,OrganisationUnit}.php,
  app/Models/Concerns/BelongsToTenant.php, README.md §Tenancy model,
  app/Services/IntegrationService.php

BUILD

16.1 Group and entity hierarchy
  - entities table (promoting OrganisationUnit for legal entities):
    tenant_id, parent_entity_id, legal_name, trading_name, entity_type
    enum(holding,bank,merchant_bank,microfinance_bank,payment_service_bank,
    pssp,ptsp,switching,mmo,super_agent,insurer,broker,pfa,pfc,cmo,telco,
    corporate,subsidiary,branch,representative_office),
    jurisdiction, registration_number, tax_id, licence_categories json,
    regulators json, fiscal_year_end, functional_currency,
    consolidation_method enum(full,proportional,equity,none),
    ownership_percentage, is_regulated, data_residency_country, status
  - Obligations, controls, risks and metrics all scope to an entity.
  - Consolidated rollup: group-level dashboards aggregate across entities with a
    drill-down to any subsidiary, respecting per-entity permissions.
  - Inter-entity comparison and benchmarking (entity vs group average).

16.2 Data residency
  - Per-tenant, per-entity data_residency_country with an enforcement layer:
    a residency guard that blocks any storage or transfer operation targeting a
    region outside the declared country, at the filesystem, queue, backup and
    integration layers.
  - Residency attestation report: a generated, signed document stating where
    each data category is stored and processed, with infrastructure evidence —
    the artefact a customer hands to CBN, BoG or NDPC.
  - Cross-border transfer register (feeds the Phase 13 NDPC submission pack):
    what left, to where, under which lawful basis (NDPA Part VIII, Schedule 5
    adequacy note), authorised by whom.
  - Deployment documentation for a Nigerian data plane (Rack Centre,
    MainOne/Equinix LG1, Galaxy Backbone, Open Access Data Centres) and for
    GH/KE/ZA, with a control-plane vs data-plane split: shared metadata,
    country-pinned tenant data, regional key management.

16.3 Enterprise hardening
  - Performance: index review across all phases, query budget assertions in
    tests, Redis caching with tenant-scoped keys and explicit invalidation,
    Horizon for queues, eager-loading audit. Target: any list page under 500ms
    server time at 100k rows.
  - Observability: structured JSON logging with a request correlation id,
    health check endpoints, queue depth and failure metrics, slow query log,
    an error budget dashboard.
  - Reliability: documented backup and restore with a tested RPO/RTO, a DR
    runbook, and a restore drill script.
  - Security: rate limiting, security headers (CSP, HSTS, X-Frame-Options),
    session security, an account-lockout policy, a dependency vulnerability scan
    in CI, and a documented responsible-disclosure process.
  - Tenant provisioning: a command that stands up a new tenant with chosen
    content packs, roles, dashboards and demo data.

16.4 Our own ISO 27001:2022 evidence
  - Use Atheris to run Atheris's own ISMS: seed a tenant with the ISO
    27001:2022 content pack (93 Annex A controls across the 4 themes), map our
    own controls, and generate the Statement of Applicability. This is both
    dogfooding and a sales prerequisite — bank vendor due diligence will demand
    ISO 27001 certification and an NDPA-compliant DPA.

BUSINESS RULES
  - The residency guard cannot be disabled at runtime; changing a residency
    declaration requires re-provisioning and is audited.
  - A cross-border transfer without a recorded lawful basis is blocked, not
    warned about.
  - Group-level users see subsidiary data only where explicitly granted.

TESTS
  - Residency guard blocks a write to a non-compliant region (all four layers)
  - Cross-border transfer without lawful basis is blocked
  - Group rollup respects per-entity permissions (negative test)
  - Consolidation methods produce correct aggregates
  - Cache keys are tenant-scoped; no cross-tenant leakage
  - List page query counts stay within budget at scale (seeded 100k rows)

DELIVERABLES
  ~5 migrations, entity model and services, ResidencyGuard, ConsolidationService,
  ProvisioningCommand, observability wiring, DR runbook, deployment
  documentation per country, residency attestation generator, ISMS seed, tests,
  README update.
```

---

## PHASE 17 — Extended GRC: Strategy, Third-Party Risk, ESG & Combined Assurance
**Duration: ~4 weeks · The "grow into the future" phase**

```
PHASE 17 — EXTENDED GRC

OBJECTIVE
Complete the Corporater parity picture and open adjacent budget: strategic
objective and performance alignment, third-party/vendor risk management,
IFRS S1/S2 sustainability controls, and a combined assurance map.

BUILD

17.1 Strategy and performance alignment
  ("Alignment with strategic initiatives, objectives and performance goals" is
   an explicit Corporater feature.)
  - objectives: tenant_id, entity_id, parent_objective_id, perspective
    enum(financial,customer,internal_process,learning_growth,sustainability),
    code, title, description, owner_id, period, target_date, status, progress,
    weight
  - objective_metrics: links an objective to Phase 10 metrics as its measures
  - initiatives: projects delivering an objective, with budget, owner, milestones
  - Linkage: objective ← risk ← control ← KRI, so a board can see "which risks
    threaten this objective and are the controls over them effective".
  - A strategy map view (perspectives as rows, objectives as linked nodes) and a
    balanced scorecard view.

17.2 Third-party / vendor risk management
  - vendors: legal name, category, criticality, services provided, contract
    dates, spend, owner, entity, regulators notified (CBN material outsourcing
    notification is a real obligation), data access classification,
    sub-processor list
  - vendor_assessments: due diligence questionnaires (reuse the Phase 9
    questionnaire engine), risk scoring, review frequency, expiry
  - vendor_findings and remediation tracking
  - Contract and SLA register with obligation linkage and renewal alerts
  - Continuous monitoring hooks: adverse media and sanctions screening as a
    connector (Phase 12), AI-assisted screening summary (Phase 14 capability 8)
  - Concentration risk: exposure by vendor, by service, by jurisdiction

17.3 IFRS S1/S2 sustainability controls
  Nigeria's FRC roadmap: all PIEs from periods beginning 1 Jan 2028, SMEs from
  1 Jan 2030, public sector 1 Jan 2028, with three-stage pre-reporting filings
  at −3 months, +3 months and +6 months from the year start. COSO ICSR (2023) is
  the control framework.
  - Materiality assessment workflow (double materiality where the tenant elects
    it), sustainability topic register, GHG data lineage with controls over
    Scope 1/2/3 (Scope 3 deferrable under the transition reliefs), scenario
    analysis evidence, metrics and targets, the three-stage FRC filing tracker,
    board training log, and controls over sustainability reporting mapped to
    COSO ICSR.
  - Output feeds a Phase 13 submission pack.

17.4 Combined assurance map
  - Assurance provider register (management, second line, internal audit,
    external audit, regulator, external assurance).
  - Map: risk × assurance provider × coverage × last assurance date × reliance.
  - Gap identification (unassured significant risks) and duplication
    identification (three providers testing the same control).
  - This is the King IV/V and NCCG combined-assurance concept, and it is the
    natural integration point with ThirdLine — publish and consume assurance
    coverage across the existing integration layer.

17.5 Internal audit interlock
  - Extend the existing ThirdLine/NexusRisk integration to exchange: control
    reliance decisions, audit findings that become control exceptions, and
    second-line testing that third line can rely on. Update docs/openapi.yaml.

TESTS
  - Objective progress rolls up correctly from metrics and initiatives
  - Vendor assessment expiry triggers a review obligation
  - Concentration risk aggregates correctly across entities
  - Combined assurance map identifies a known gap and a known duplication
  - IFRS S1/S2 filing-stage tracker computes the three deadlines correctly from
    a fiscal year start
  - Integration round-trip with ThirdLine preserves reliance decisions

DELIVERABLES
  ~12 migrations, 10 models, ObjectiveService, VendorService,
  SustainabilityService, AssuranceService, controllers, routes, React pages
  (Strategy/Map, Strategy/Scorecard, Objectives/*, Vendors/*, Sustainability/*,
  Assurance/Map), seeders, tests, README + openapi.yaml updates.
```

---
# PART C — AI LAYER SPECIFICATION
### Reference document for Phase 14. Read in full before building.

## C.1 Principles

1. **AI never decides.** Every output is a draft. Human approval is a structural
   requirement enforced in the gateway, not a UI convention.
2. **Everything is auditable.** Prompt version, model, redacted input hash,
   retrieved context ids, raw output, tokens, cost, latency, reviewer, decision.
3. **Nothing sensitive leaves the boundary.** Redaction is a mandatory pipeline
   stage. No customer name, account number, BVN, NIN, phone, email, address or
   confidential case content ever reaches the model.
4. **Everything is grounded.** Retrieval-augmented, citing the tenant's own
   records. When retrieval is empty, the model says so rather than inventing.
5. **Cost is bounded.** Per-tenant, per-user and per-capability budgets with a
   hard stop.

## C.2 Configuration

```php
// config/services.php
'anthropic' => [
    'api_key'          => env('ANTHROPIC_API_KEY'),
    'base_url'         => env('ANTHROPIC_BASE_URL', 'https://api.anthropic.com'),
    'default_model'    => env('ANTHROPIC_MODEL', 'claude-sonnet-4-6'),
    'reasoning_model'  => env('ANTHROPIC_REASONING_MODEL', 'claude-opus-4-6'),
    'fast_model'       => env('ANTHROPIC_FAST_MODEL', 'claude-haiku-4-6'),
    'max_tokens'       => (int) env('ANTHROPIC_MAX_TOKENS', 4096),
    'timeout'          => (int) env('ANTHROPIC_TIMEOUT', 120),
    'max_retries'      => (int) env('ANTHROPIC_MAX_RETRIES', 3),
],
```

`.env.example` gains `ANTHROPIC_API_KEY=` with no value. Verify the exact model
identifiers against Anthropic's current model documentation at build time
(`https://docs.claude.com/en/docs/about-claude/models`) — model names change and
must not be guessed. Store the resolved identifier in `ai_configurations` so it
is a tenant setting, not a constant.

**Model selection by capability:** fast model for classification, extraction and
short summarisation; default model for drafting and review; reasoning model for
framework mapping, ICFR deficiency aggregation and multi-document regulatory
analysis. Make it configurable per capability, with a sane default.

## C.3 Data model

```
ai_configurations
  id, tenant_id, capability_key, is_enabled, model, temperature, max_tokens,
  system_prompt_id, monthly_token_budget, requires_approval_role_id,
  min_confidence_to_surface, created_by, approved_by, approved_at

ai_prompts                    -- versioned prompt registry
  id, key, version, name, description, system_prompt longtext,
  user_template longtext, output_schema json, variables json,
  model_hint, temperature, max_tokens, is_active, created_by,
  approved_by, approved_at, changelog, eval_score

ai_interactions               -- the audit log; append-only like audit_trails
  id, tenant_id, user_id, capability_key, prompt_id, prompt_version, model,
  subject_type, subject_id, input_hash, input_redacted json,
  retrieved_context json (ids only, never content), raw_output longtext,
  parsed_output json, confidence decimal(4,3), citations json,
  input_tokens, output_tokens, cost_minor, currency, latency_ms,
  status enum(Pending,Completed,Failed,Rejected by Schema,Budget Exceeded,
  Rate Limited), error_message,
  human_decision enum(Pending,Accepted,Edited,Rejected) default 'Pending',
  decided_by, decided_at, edit_diff json, rejection_reason, created_at

ai_feedback
  id, interaction_id, user_id, rating tinyint, was_useful bool,
  issue_category enum(incorrect,incomplete,irrelevant,unsafe,formatting,other),
  comment, created_at

ai_knowledge_chunks           -- the RAG index
  id, tenant_id, source_type, source_id, chunk_index, content text,
  content_hash, embedding json (or a vector column if the DB supports it),
  metadata json, permission_key, token_count, indexed_at, is_stale

ai_budgets
  id, tenant_id, period_label, period_start, period_end,
  token_budget, tokens_used, cost_budget_minor, cost_used_minor, currency,
  hard_stop bool, alert_thresholds json, alerted_at json
```

## C.4 Gateway pipeline

`App\Services\Ai\AiGateway::execute(CapabilityRequest $request): AiDraft`

```
1.  Capability enabled?          → else CapabilityDisabledException
2.  User authorised?             → policy check on capability + subject
3.  Budget available?            → else BudgetExceededException, no call made
4.  Rate limit?                  → per user, per tenant, per capability
5.  Assemble context             → RetrievalService, permission-filtered at
                                   query time using the requesting user's scope
6.  Redact                       → RedactionService (MANDATORY, unskippable)
7.  Render prompt                → PromptRegistry::render(key, version, vars)
8.  Call Anthropic               → retry with exponential backoff on 429/5xx,
                                   respect timeout, stream where the UI benefits
9.  Parse and validate           → against the prompt's output_schema; on
                                   failure, one repair attempt, then fail
10. Score confidence             → from the model's own stated confidence,
                                   retrieval coverage and schema completeness
11. Resolve citations            → map returned record ids back to real records
                                   the user can open; drop unresolvable ones
12. Record interaction           → ai_interactions, always, success or failure
13. Return AiDraft               → a value object that CANNOT be persisted to a
                                   domain model without an explicit
                                   AiDraft::accept(User $approver) call, which
                                   itself writes an audit event
```

`AiDraft` is the enforcement mechanism for Rule R4. Domain services accept an
`AiDraft` only through an `applyAiDraft()` method that requires an approver
distinct from... nothing — the approver may be the requester, but it must be an
explicit human action, recorded. No service method may accept raw model output.

## C.5 Redaction

`App\Services\Ai\RedactionService` — deterministic, tested, not model-based:

| Category | Pattern / source | Replacement |
|---|---|---|
| Person names | Names from `users`, `complaints.customer_name`, `cases.subject_persons` | `[PERSON_1]`, stable within a request |
| NUBAN account | `\b\d{10}\b` in a financial context | `[ACCOUNT]` |
| BVN | `\b\d{11}\b` | `[BVN]` |
| NIN | `\b\d{11}\b` (contextual) | `[NIN]` |
| Phone | `(\+?234|0)[789]\d{9}` and international forms | `[PHONE]` |
| Email | RFC-ish pattern | `[EMAIL]` |
| Card PAN | Luhn-valid 13–19 digits | `[PAN]` |
| Address | Address fields from structured data | `[ADDRESS]` |
| Amounts | Optional per tenant | `[AMOUNT]` or retained |
| Case content | `cases` marked Highly Restricted | Excluded from retrieval entirely |

Redaction is applied to the assembled context AND the user's free-text input.
A reversible mapping is held in memory for the request only, so citations and
entity references can be rehydrated in the UI after the response returns — the
map is never persisted and never sent.

**Test:** mock the HTTP client, execute every capability against fixtures
containing each pattern, and assert the outbound request body contains none of
them.

## C.6 The nine capabilities — contracts

| # | Capability key | Input | Output schema | Model |
|---|---|---|---|---|
| 1 | `control.draft` | risk / process / requirement | `{objective, description, type, nature, frequency, key_control, evidence_requirements[], test_script:{name, check_items[{assertion, procedure, evidence, sample_basis}]}}` | default |
| 2 | `regulatory.parse` | circular text/PDF | `{regulator, reference, published_at, effective_at, summary, obligations[{title, type, frequency, due_rule, penalty, legal_reference, confidence}], affected_areas[]}` | reasoning |
| 3 | `risk.intelligence` | incidents, complaints, losses, register | `{risk_statements[{title, cause, event, consequence, category, suggested_kris[]}], emerging_themes[], register_gaps[]}` | default |
| 4 | `evidence.review` | check item + evidence metadata + extracted text | `{supports_assertion: bool, confidence, gaps[], quality_issues[], recommended_action, rationale}` | default |
| 5 | `exception.triage` | exception + history | `{category, probable_root_cause, root_cause_confidence, similar_exception_ids[], recurrence_risk, proposed_remediation[{action, owner_role, effort, sequence}]}` | default |
| 6 | `narrative.generate` | report section spec + retrieved data | `{narrative, citations[{record_type, record_id, claim}], data_gaps[]}` | default |
| 7 | `framework.map` | requirement + candidate requirements | `{mappings[{target_requirement_id, relationship, coverage_percentage, rationale, confidence}]}` | reasoning |
| 8 | `vendor.screen` | vendor profile + documents | `{risk_summary, risk_factors[{factor, severity, source}], adverse_findings[], recommended_dd_questions[], overall_indication}` | default |
| 9 | `atlas.chat` | question + conversation | `{answer, citations[{record_type, record_id, title}], confidence, suggested_actions[], insufficient_context: bool}` | default |

Every schema includes `confidence` and every generative schema includes
`citations`. `insufficient_context: true` must render as an explicit "I don't
have enough information in your data to answer that" — never a plausible
fabrication.

## C.7 Retrieval

- Chunk at ~800 tokens with 100-token overlap, splitting on semantic boundaries
  (a control's fields, a policy's sections, an obligation's description).
- Embed with a small embedding model; store per tenant. If no vector column is
  available in MySQL, store the vector as JSON and do the similarity in PHP over
  a candidate set pre-filtered by FULLTEXT — correctness first, optimise later.
- **Permission filtering happens at retrieval, before the model sees anything.**
  Each chunk carries a `permission_key`; retrieval joins against the requesting
  user's effective permissions. A user must never receive a synthesised answer
  built from records they cannot open. This is the single most important test in
  the phase.
- Hybrid retrieval: semantic + keyword, reciprocal rank fusion, top-k with a
  relevance floor.
- Re-index on model save via a queued listener; mark stale rather than blocking
  the write.

## C.8 Governance surface

`Admin → AI` shows: capability enable/disable per tenant, model per capability,
prompt version history with diffs and the ability to pin a version, token and
cost usage against budget with a trend, acceptance/edit/rejection rate per
capability (the honest quality metric), rejection reasons grouped by category,
and an exportable AI activity log.

This surface exists partly to satisfy our customers' own AI-governance
obligations — draft King V's technology chapter covers AI governance explicitly
(human oversight, ethics, transparency) and Ghana's 2026 BoG directive requires
AI/ML governance for fraud detection and credit scoring models. Demonstrating
our own governance is a sales asset, not overhead.

---

# PART D — REGULATORY CONTENT PACK SPECIFICATION
### Reference document for Phase 8.

## D.1 Pack format

Each content pack is a JSON file in `database/content-packs/<code>/<version>.json`,
installed by `php artisan atheris:install-content-pack`.

```json
{
  "code": "CBN-CG-2023",
  "name": "CBN Corporate Governance Guidelines 2023",
  "version": "1.0.0",
  "jurisdiction": "NG",
  "issuing_body": "Central Bank of Nigeria",
  "effective_from": "2023-08-01",
  "source_url": "https://www.cbn.gov.ng/Out/2023/FPRD/...",
  "verification_status": "verified",
  "verified_by": "",
  "verified_at": null,
  "changelog": "Initial pack",
  "framework": { "...": "framework record" },
  "requirements": [ { "ref_code": "", "title": "", "...": "" } ],
  "obligations":  [ { "obligation_ref": "", "due_rule": {}, "...": "" } ],
  "controls":     [ { "suggested control definitions" } ],
  "mappings":     [ { "to_framework": "", "to_ref": "", "relationship": "" } ]
}
```

**Every record carries `verification_status`.** Only `verified` records appear
in generated regulatory submissions. `unverified` and `draft` records are usable
internally but badged, and excluded from anything filed with a regulator.

## D.2 COSO 2013 — the exact 17 principles

Seed these verbatim; they are the backbone of the FRC ICFR module.

**Control Environment**
1. Demonstrates commitment to integrity and ethical values
2. Exercises oversight responsibility
3. Establishes structure, authority and responsibility
4. Demonstrates commitment to competence
5. Enforces accountability

**Risk Assessment**
6. Specifies suitable objectives
7. Identifies and analyses risk
8. Assesses fraud risk
9. Identifies and analyses significant change

**Control Activities**
10. Selects and develops control activities
11. Selects and develops general controls over technology
12. Deploys through policies and procedures

**Information and Communication**
13. Uses relevant information
14. Communicates internally
15. Communicates externally

**Monitoring Activities**
16. Conducts ongoing and/or separate evaluations
17. Evaluates and communicates deficiencies

## D.3 COSO ERM 2017 — 5 components, 20 principles

**Governance & Culture:** 1 Exercises Board Risk Oversight · 2 Establishes Operating Structures · 3 Defines Desired Culture · 4 Demonstrates Commitment to Core Values · 5 Attracts, Develops and Retains Capable Individuals
**Strategy & Objective-Setting:** 6 Analyzes Business Context · 7 Defines Risk Appetite · 8 Evaluates Alternative Strategies · 9 Formulates Business Objectives
**Performance:** 10 Identifies Risk · 11 Assesses Severity of Risk · 12 Prioritizes Risks · 13 Implements Risk Responses · 14 Develops Portfolio View
**Review & Revision:** 15 Assesses Substantial Change · 16 Reviews Risk and Performance · 17 Pursues Improvement in Enterprise Risk Management
**Information, Communication & Reporting:** 18 Leverages Information and Technology · 19 Communicates Risk Information · 20 Reports on Risk, Culture and Performance

*(Some third-party sources circulate a 23-principle variant. The official count
is 20. Do not seed the variant.)*

## D.4 Other international frameworks — structure to seed

| Pack | Structure |
|---|---|
| **COBIT 2019** | 5 domains, 40 objectives: EDM 5, APO 14, BAI 11, DSS 6, MEA 4. Seven components per objective. Capability levels 0–5. |
| **ISO/IEC 27001:2022** | Clauses 4–10 (Annex SL) + Annex A 93 controls in 4 themes: A.5 Organizational (37), A.6 People (8), A.7 Physical (14), A.8 Technological (34). 11 new controls vs 2013: threat intelligence; information security for use of cloud services; ICT readiness for business continuity; physical security monitoring; configuration management; information deletion; data masking; data leakage prevention; monitoring activities; web filtering; secure coding. Amendment 1:2024 adds climate to 4.1/4.2. SoA is the mandatory artefact. **2013 transition closed 31 Oct 2025 — seed 2022 only.** |
| **ISO 31000:2018** | 8 principles (integrated, structured and comprehensive, customised, inclusive, dynamic, best available information, human and cultural factors, continual improvement); framework (leadership, integration, design, implementation, evaluation, improvement); process (scope/context/criteria → identification → analysis → evaluation → treatment, with communication, monitoring and recording throughout). Not certifiable. |
| **ISO 37301:2021** | Certifiable CMS. Compliance obligations identification, compliance risk assessment, policy, governance and compliance-function independence, controls, competence and training, raising concerns, investigation, performance evaluation, internal audit, management review, corrective action. |
| **ISO 22301:2019** | BIA → strategies → plans → exercising → evaluation. Parameters MTPD, RTO, RPO, MBCO. |
| **NIST CSF 2.0** | 6 functions: GOVERN (new in 2.0, wrapping the rest), IDENTIFY, PROTECT, DETECT, RESPOND, RECOVER. Categories → subcategories. Tiers: Partial, Risk Informed, Repeatable, Adaptive. Current/Target Organizational Profiles. GV.SC covers supply chain. *(Category and subcategory counts commonly cited as 22 and 106 — verify against NIST CSWP 29 before seeding as verified.)* |
| **PCI DSS 4.0.1** | 12 requirements, 6 goals. v4.0's 51 future-dated requirements became effective 31 Mar 2025. v4.0.1 (June 2024) is clarification-only. Customised approach option; targeted risk analyses (Req. 12.3.1); annual scoping, six-monthly for service providers. |
| **SOX** | s.302 quarterly CEO/CFO certification of disclosure controls; s.404(a) management ICFR assessment; s.404(b) auditor attestation. PCAOB AS 2201 top-down risk-based: entity-level → significant accounts → processes → controls. |

## D.5 Nigerian packs — key facts to encode

These are the highest-value records in the product. Every date, amount and
section number below is drawn from the research brief; **each must be confirmed
against the regulator's primary document before shipping as `verified`.** The
CBN's own PDFs block automated fetch, so several items are marked unverified.

**CBN Corporate Governance Guidelines 2023** (effective 1 Aug 2023; separate
instruments for banks and financial holding companies; sit on top of NCCG 2018)
- Internal audit cannot be outsourced; head reports to the Board Audit
  Committee, minimum rank AGM; appointment/removal needs CBN approval;
  **independent external assessment annually, filed with CBN by 31 May**
- Risk management: board-approved ERM framework; **framework review every 3
  years, effectiveness review annually**; quoted banks publish a risk management
  summary on their website
- Compliance: board-designated Executive Compliance Officer; CCO rank GM
  (national) / AGM; appointment/removal needs CBN approval
- Committees: BAC, BNGC, BRC, BRMC mandatory; **BAC and BRMC cannot be
  combined**; chairs must be INEDs; quarterly minimum; 2/3 quorum with NED
  majority; membership reviewed every 3 years; **charters need CBN "No
  Objection" within 30 days of board approval**
- Board: 7–15 directors (CMB/NIB), 7–13 (PSB); minimum 2–3 INEDs; **single-gender
  boards prohibited (S.1.6)**; two NEDs must have fintech/ICT/cyber expertise
- Tenure: MD/CEO and EDs max 12 years; NEDs 12 (3×4); INEDs 8 (2×4); cumulative
  directorship cap 24 years; FHC MD/CEO 10 years; auditor tenure max 10 years +
  10-year cooling off; **audit partner rotation every 5 years**; cooling off
  exec→NED 2 years, NED→exec 2 years
- Filings: board evaluation report by **31 May**; external auditor report by
  **31 March**; investment policy review every 3 years; NIB Shariah audit
  reports quarterly

**CBN Risk-Based Cybersecurity Framework** (DMB/PSP and OFI variants, both
effective 1 Jan 2023) — 6 parts: Governance & Oversight; Risk Management System;
Cyber Resilience Assessment; Operational Resilience; Cyber-Threat Intelligence;
Metrics, Monitoring & Reporting. Board, senior management and CISO jointly
accountable. CISO maintains a live inventory of users, devices, applications,
software and hardware; periodic cyber risk assessments; offsite backups.
⚠ **Unverified:** the incident-notification window to CBN (commonly cited as 24
hours) and the returns cadence/format.

**CBN AML/CFT/CPF Regulations 2022** + TFS Guidelines 2022, anchored on s.66
BOFIA 2020 and s.54 TPPA 2022. Real-time screening of customers, transactions
and beneficial owners; sanctions-list monitoring; immediate freeze on
designation; STR/CTR filing with the NFIU. May 2025 exposure draft "Baseline
Standards for Automated AML Solutions" signals a coming automation mandate.
⚠ **Unverified:** section numbers, retention periods, CTR thresholds, training
frequency.

**CBN Consumer Protection** (Framework 7 Nov 2016; Regulations 20 Dec 2019) —
complaints via letter, email, phone, social media and digital platforms;
**acknowledged within 24 hours with a unique tracking ID**; penalties **₦500,000
per complaint per week unresolved**, **₦2,000,000** for acknowledgement failure,
**₦2,000,000** for non-compliance with a CBN directive.

**CBN payment data localisation** — Circular PSS/DIR/PUB/CIR/001/004, 15 June
2026: all Nigerian payment transaction data stored and processed in Nigeria;
**compliance date 1 January 2027**; market-structure compliance by 31 Dec 2026
(no entity >25% card issuing AND >15% merchant acquiring); UBO disclosure;
monthly market-share returns. Complementary NITDA cloud computing policy
(17 Feb 2025) mandating local hosting of sensitive finance/health/government data.

**NDIC DPAS** — base rate plus risk add-ons capped at 0.30%. Quantitative:
capital adequacy 0.01–0.05%; asset quality 0.02–0.04%; liquidity 0.02–0.04%.
**Management add-ons (software-addressable):** poor internal controls +0.02%;
late return submission +0.01%; financial misreporting +0.03%; weak risk
management +0.02%; non-compliance with examiners' recommendations +0.02%.

**FRC NCCG 2018** — 28 principles, apply-and-explain, return FRC/CG/001,
sections A (introduction), B (general information: incorporation date, RC
number, auditors, registrars, contacts), C (board composition, appointment
dates, meeting attendance per director and per committee), D (senior management:
positions, names, gender), E (application of the 28 principles). **"N/A"
responses are prohibited.** Certified by Chairman, MD/CEO, Governance Committee
Chair and Company Secretary.
Principles 1–15 cover board role and charter, structure and composition,
chairman, MD/CEO, EDs, NEDs, INEDs, company secretary, access to professional
advice, meetings, committees, appointments, induction and education, board
evaluation. Principles 16–25 cover remuneration governance, risk management,
internal audit, whistleblowing, external audit, general meetings, shareholder
engagement, shareholder rights, business conduct and ethics, ethical culture
monitoring. Principles 26–28 cover sustainability, stakeholder communication and
disclosures.
**PIE definition:** governments and government organisations; listed companies;
unlisted regulated entities (banks, insurance, pension operators); public limited
companies; private holdcos with public or regulated subsidiaries; concessioned
and privatised companies; government licensees; government contractors with
public-works contracts ≥ ₦1bn; entities with annual turnover ≥ ₦30bn.
**Filing:** annual report + AFS within 60 days of board approval; qualified
reports within 30 days of qualification; copies of other-regulator filings
within 30 days.

**FRC Guidance on Management Report on ICFR** (July/Aug 2024) — Nigeria's
SOX-404. Scope: all PIEs **except** listed entities (SEC guidance applies to
them), CAMA 2020 small companies, unit MFBs, insurance brokers, and non-tertiary
education and health institutions. The report must contain: (1) a statement of
management responsibility for ICFR; (2) identification of the evaluation
framework used (**COSO 2013 highly recommended**); (3) management's
effectiveness assessment as at fiscal year end, disclosing any material
weaknesses; (4) a statement that the external auditor issued an attestation
report. Top-down, risk-based evaluation; evidential matter giving reasonable
support; documentation scoped to controls addressing identified
financial-reporting risks. **Three-tier taxonomy:** control deficiency /
significant deficiency (report to audit committee and external auditor) /
material weakness (precludes an effective conclusion).

**FRC IFRS S1/S2 roadmap (amended Feb 2026)** — Phase 1 early adopters (periods
ending on/before 31 Dec 2023, subject to an FRC readiness test); Phase 2
voluntary (periods beginning 1 Jan 2024 → ending 31 Dec 2027); **Phase 3A
mandatory all PIEs: periods beginning 1 Jan 2028**; **Phase 3B SMEs (turnover
≤ ₦500m, total assets ≤ ₦200m): periods beginning 1 Jan 2030**; Phase 4 public
sector IPSASB SRS 1 from 1 Jan 2028. **Three-stage pre-reporting filings:**
Stage 1 (3 months *before* the financial year) board resolution, gap analysis,
implementation plan; Stage 2 (within 3 months of year start) sustainability
disclosure policies, materiality assessment, governance structure evidence,
board training proof; Stage 3 (within 6 months of year start) professional
registration evidence, risk management framework, scenario analysis models,
metrics and targets. Transition reliefs: climate-first reporting, Scope 3
deferral, alternative GHG methods aligned to the GHG Protocol.

**NDPA 2023 + NDPC GAID 2025** (issued 20 Mar 2025, effective 19 Sep 2025;
audit-filing fees from the 2026 audit cycle) — tiers UHL/EHL/OHL (Arts. 8–9),
only UHL and EHL file Compliance Audit Returns. **CAR (Art. 10): by 31 March
annually; new entities within 15 months of commencing business; fees ₦100,000–
₦1,000,000 by data volume; 50% administrative penalty for late filing; UHL/EHL
must engage a licensed DPCO.** DPO independence (Arts. 11–12). **DPIA (Art. 28)**
mandatory for high-risk processing — before processing begins; within 4 months
for sensitive-data software deployed post-GAID; within 6 months for pre-existing
processing. **Breach (Art. 33): notify NDPC within 72 hours**; notify data
subjects immediately where high risk. Cross-border per Part VIII; Schedule 5
gives the adequacy-evaluation explanatory note.

**SEC Nigeria** — ISA 2025 assented 31 March 2025, repealing ISA 2007. ICFR
guidance on ss.60–63 (circular 8 Nov 2021, compliance extended to 31 Dec 2023):
CEO/CFO personalised certifications, annual board report on ICFR effectiveness,
identification of the control framework used, external auditor attestation
placed near the audit opinion. ⚠ **Unverified:** whether ISA 2025 renumbers
ss.60–63 and whether SEC has reissued the guidance — seed as `unverified`.
SEC Corporate Governance Guideline + Form 01 (10 Oct 2020), 14 principles,
penalty ₦500,000 plus ₦5,000/day. Returns calendar: annual report and accounts
within 3 months of year end; quarterly financials within 30 days of quarter end;
corporate governance report within 30 days of year end.

**NAICOM / NIIRA 2025** (assented 6 Aug 2025) — risk-based supervision,
strengthened actuarial independence with direct NAICOM reporting, audited FS +
investment statements + revenue accounts by 30 June, quarterly returns within 10
days of quarter end, dividend declarations require prior NAICOM approval.
⚠ **Unverified:** minimum capital by class, claims-handling SLAs, penalties.

**PENCOM** — PRA 2014, Regulations for Compliance Officers (RR/P&R/09/03),
Guidelines for Risk Management Framework for Licensed Pension Operators,
Investment Regulation (amended Feb 2019). ⚠ **Unverified:** RM guideline
contents — seed the obligation shells as `draft`.

**NCC** — annual audited financial reports within **180 days** of fiscal year
end (penalty ₦3m plus ₦300,000/day); **Annual Operating Levy 1% of net revenue**
within 30 days of submitting audited accounts; annual ownership report by
**1 March**; notify NCC **90 days before** share transfers exceeding 10% of
capital; Year End Questionnaire (typically Q1); address changes within 7 days;
equipment type approval; Consumer Code of Practice submission and publication;
licence renewal initiated at least 6 months before expiry.

**FIRS e-invoicing** — circular 9 July 2025, Merchant Buyer Solution (FIRSMBS),
pre-clearance (CTC) model, **mandatory for large taxpayers (turnover ≥ ₦5bn)
from 1 August 2025**. ⚠ **Unverified:** later taxpayer-band dates, IRN/QR/UBL
technical spec, penalties, and the Nigeria Tax Act 2025 / Nigeria Tax
Administration Act 2025 commencement.

**CAMA 2020 / statutory baseline** — CAC annual returns (first within 18 months
of incorporation, then annually); PSC filing (PSC Regulations 2022); first AGM
within 18 months, subsequent AGMs no later than 15 months after the preceding
one; first board meeting within 6 months; CIT first filing within 18 months then
within 6 months of financial year end; VAT by the 21st of the following month;
PAYE monthly by the 10th and annual returns by 31 January; WHT within 30 days of
deduction; NSITF 1% of monthly payroll; pension 8% employee + 10% employer
remitted within 7 days of salary payment; NDPC registration; NIPC (foreign-owned);
NOTAP (technology transfer); SCUML/EFCC (DNFIs).

## D.6 Pan-African packs — ship as `draft`, verify before selling in-market

**South Africa** — King IV (2016, 17 principles, apply-and-explain, sector
supplements); **draft King V (April 2025)**: plain-language rewrite, consolidated
principles, two-year cooling-off for former executives claiming independence,
nine-year tenure rule for independence, Risk and Social & Ethics Committees each
requiring at least one independent NED, and a substantially expanded technology
chapter explicitly covering **AI governance**. ⚠ final publication date and
principle count unconfirmed. Companies Act 71 of 2008 (s.94 audit committee,
s.72(4) social and ethics committee, public interest score). POPIA (fully
effective 1 July 2021; 8 conditions; s.55 Information Officer registration; PAIA
manual). JSE Listings Requirements s.3.84 and the JSE Sustainability & Climate
Disclosure Guidance. SARB/PA Banks Act 94 of 1990 and Regulations; **Joint
Standard 2 of 2024 on Cybersecurity and Cyber Resilience (effective 1 June
2025)** and Joint Standard 1 of 2023 on IT governance and risk.

**Kenya** — CBK Prudential Guidelines (2013 set): corporate governance, risk
management, internal controls and internal audit, AML/CFT, stress testing; CBK
Climate Risk Guidance 2021. ⚠ individual CBK/PG numbers unverified. Data
Protection Act 2019 + ODPC registration, DPIA, **72-hour breach notification**.
CMA Code of Corporate Governance Practices for Issuers 2015, apply-or-explain,
notably requiring a **biennial independent governance audit by an accredited
governance auditor** — a distinctive, software-addressable requirement. POCAMLA.

**Ghana** — BoG Corporate Governance Directive 2018 (Act 930): board
composition, tenure limits, committees, s.56 internal audit. **BoG Cyber &
Information Security Directive launched 15 April 2026**, replacing the Oct 2018
directive: scope extended to commercial banks, MFIs, fintechs, PSPs and non-bank
FIs; board-level cyber accountability not delegable to IT; explicit **AI/ML
governance** for fraud detection and credit scoring; **data localisation — core
systems and customer data must remain in Ghana**; cloud restricted to
non-sensitive operations; expanded FICSOC oversight; proportional compliance by
size and risk. Data Protection Act 2012 (Act 843), registration renewable every
2 years.

**Survey-level only — do not encode without dedicated research:** Egypt (PDPL
5/2021, CBE cybersecurity framework and governance instructions, FRA rules, EGX
ESG), Morocco (Law 09-08 + CNDP, Bank Al-Maghrib internal control circular,
AMMC governance code), Rwanda (Law 058/2021, NCSA, data localisation by default,
BNR governance and risk regulations), Tanzania (PDPA 2022 + PDPC, BoT Risk
Management Guidelines 2010 and Corporate Governance Guidelines 2021), Uganda
(DPPA 2019 + PDPO; first criminal conviction July 2025; BoU Corporate Governance
Regulations 2005), Zambia (Data Protection Act No. 3 of 2021 **with a
localisation provision for sensitive personal data**, BoZ Corporate Governance
Directives 2016).

**Continental:** AU Malabo Convention — adopted 2014, **in force 8 June 2023**;
21 signatures, 16 ratifications including Nigeria, South Africa, Kenya, Rwanda,
Senegal, Uganda, Tanzania, Mauritius. Requires an independent national DPA, a
national cybersecurity policy, criminalisation of CIA-affecting acts, and
e-transaction rules. AfCFTA Protocol on Digital Trade adopted February 2024
(cross-border data flows, localisation disciplines, source-code protection);
annexes still under negotiation — note the tension with CBN/BoG localisation.

## D.7 Verification protocol

Before any pack ships as `verified`:

1. Obtain the regulator's primary document (PDF or gazette). CBN, PenCom and
   several others block automated fetch — these must be downloaded manually.
2. Confirm every section number, date, threshold and penalty against it.
3. Record `verified_by` and `verified_at` on the pack.
4. Anything still unconfirmed stays `unverified` and is excluded from generated
   submissions by the Phase 13 generators.

**The 13 known-unverified items** (from the research): CBN cyber incident-
reporting window and returns cadence · CBN AML/CFT section detail, retention,
thresholds, training frequency · the complete eFASS/FinA return schedule ·
whether ISA 2025 renumbers ss.60–63 and whether SEC reissued its guidance ·
PenCom risk management framework guideline contents · NIIRA 2025 capital by
class, claims SLAs and penalties · CBK prudential guideline numbers · King V
final publication and principle count · per-jurisdiction Basel III/IV status
(use BIS RCAP profiles) · NIST CSF 2.0 category and subcategory counts ·
Nigeria Tax Act 2025 commencement and its interaction with the FIRS e-invoicing
phases · the six survey-level African jurisdictions · the Nigerian local-vendor
market scan.

Assign an owner and a date to each. This list is the phase-8 research backlog.

---

# APPENDIX — Quick-start prompt (if you want to begin today)

For a first session that produces immediate value without committing to the full
roadmap, paste Part A followed by:

```
Before we start Phase 7, do a repository readiness audit and produce
docs/V2-READINESS.md containing:

1. A file-by-file inventory of what exists today, mapped against the 32
   Corporater solution features, marked Have / Partial / Missing with the
   specific file or table that provides it.
2. Every technical-debt item that will make Phases 7-17 harder: missing indexes,
   N+1 queries, untested paths, hardcoded values that rule R1 says must be data,
   places where tenant scoping is implicit rather than enforced, and any spot
   where an admin could bypass a control.
3. A dependency analysis: which of Phases 7-17 could run in parallel and which
   are strictly sequential, with the reason.
4. A concrete list of the smallest changes that would most reduce risk before
   Phase 7 begins.

Read the code — do not infer from the README. Cite file paths and line numbers.
Change nothing. Produce the document, then stop.
```

That gives you a verified baseline, written by the same tool that will do the
build, before a single migration is written.
