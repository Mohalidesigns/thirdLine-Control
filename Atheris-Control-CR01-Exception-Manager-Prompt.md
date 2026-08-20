# Atheris Control — CR-01 · Exception Manager
### Client change request: departmental escalation, response capture and closure tracking

**How to use this document**

1. Open a fresh Claude Code session in the `thirdLine-Control` repo.
2. Paste **Part A — Master Context Brief** from `Atheris-Control-v2-Claude-Code-Prompts.md` first. Every time.
3. Then paste **the whole of Part B below**, in one go.
4. Let it run to completion, verify the Definition of Done, commit, close the session.

This is a change request against the shipped Phase 7–11 baseline, not a new roadmap phase.
It slots between Phase 11 and Phase 12 and depends on nothing that is unbuilt.

---

# PART B — THE PROMPT
### Paste everything inside the fence

```
CR-01 — EXCEPTION MANAGER: DEPARTMENTAL ESCALATION, RESPONSE & CLOSURE

OBJECTIVE
The client has named this the key feature of the solution. Today an exception is
an internal tracker row: the control function raises it, assigns it to one user,
and chases it. The client needs it to work the way the ThirdLine Internal Audit
product works its findings — a formal, addressed instrument that the control
function ISSUES to one or more departments or processors, that lands in the
named respondent's inbox, that the department ANSWERS on the record, and whose
answer is REVIEWED, tracked round by round, and closed only by the control
function against evidence.

Build the Exception Manager: fan-out escalation to departments/processors,
emailed and in-app issue notices with a deep link, a structured departmental
response, a review/accept/reject loop with re-issue, an SLA and reminder engine,
and closure of the control lapse that cannot be reached without the loop being
closed.

PARITY REFERENCE — ThirdLine Internal Audit
The internal audit product implements this as:
  audit_findings.management_response / response_status / agreed_target_date
  AuditeeResponseController          — the auditee (department) answers findings
  finding_actions                    — the agreed action plan per finding
  follow_up_validations              — the auditor validates and closes
  finding_ic_comments                — the second line's immutable comment thread
  finding_escalations                — formal, levelled escalation with a
                                       recipient snapshot in notified_to
  external_auditee_links             — hashed, expiring, revocable token links so
                                       a respondent outside the system can answer
  NotificationService::sendInternalControlEscalation() /
    escalateOverdueFindings() / notifyFindingAssigned()
Mirror the BEHAVIOUR and the workflow states. Do NOT mirror the schema — that
product has no tenancy, no service layer and no Form Requests. This build is
Laravel 13 + Inertia + the Atheris conventions in DEVELOPMENT_STANDARD.md.

READ FIRST
  DEVELOPMENT_STANDARD.md
  Control-Solution-BRD-v1.0.md — FR-5.1..5.10 (exceptions), FR-8.1..8.8 (escalation)
  app/Services/ExceptionService.php        ← the state machine and SoD pattern
  app/Services/EscalationService.php       ← the time-based matrix ladder
  app/Services/NotificationDispatcher.php  ← the ONLY way to notify a user
  app/Models/ControlException.php, app/Models/ExceptionActivity.php
  app/Policies/ExceptionPolicy.php
  database/migrations/2026_08_08_150011_add_attestation_escalation_support.php
  database/migrations/2026_08_09_100013_add_risk_escalation_support.php
      ↑ these two are the established pattern for extending the escalation
        matrix enum and wiring a new trigger condition. Copy it exactly.
  database/seeders/RolePermissionSeeder.php, NotificationEventSeeder.php
  resources/js/Pages/Exceptions/Show.jsx
Do not restate what you read. Read it, then build.

WHAT ALREADY EXISTS — REUSE, DO NOT REBUILD
  - control_exceptions, its guarded lifecycle and ExceptionService::TRANSITIONS.
    Open→Assigned→In Progress→Remediated→Verified-Closed / Risk Accepted stays
    exactly as it is. The Exception Manager wraps it; it does not replace it.
  - exception_activities — the commentary thread. Extend the activity_type enum,
    do not create a second thread table.
  - escalation_matrices / escalation_events / EscalationService — the TIME-BASED
    tier ladder (owner → line manager → control function head → exec → board).
    The new departmental escalation is ADDRESSED and deliberate; the matrix
    ladder is automatic and time-driven. Keep both. Wire them: a department that
    misses its response SLA feeds the existing ladder through two new trigger
    conditions.
  - NotificationDispatcher + notification_events + notification_preferences —
    every notification in this build goes through the dispatcher. No direct
    Mail::send, no $user->notify() outside it.
  - evidence table + EvidencePanel — response attachments are evidence rows with
    linked_type/linked_id, classification and retention. Do not add a file column.
  - public_holidays + tenants.localisation — SLA clocks are BUSINESS-DAY clocks
    in the tenant's timezone, skipping seeded holidays. Never calendar days.
  - The component library: DataTable, FilterBar, StatCard, Modal, StatusBadge,
    SeverityBadge, PageHeader, EmptyState, ConfirmDialog, EvidencePanel,
    ProgressBar, FlashNotification.

DATA MODEL

  exception_escalations          -- one row per department/processor addressed
    id, tenant_id, exception_id, reference (EXE-…, GeneratesReference),
    round_no unsigned default 1,
    target_type enum(unit,process,user,external_party),
    unit_id NULL → organisation_units,
    process_id NULL → business_processes,
    respondent_id NULL → users,          -- the named officer who must answer
    external_name NULL, external_email NULL, external_organisation NULL,
    cc_user_ids json NULL,
    severity enum(Critical,High,Medium,Low),   -- snapshot at issue
    issued_by → users, issued_at,
    issue_note text,                      -- what the control function is asking for
    required_response enum(root_cause,action_plan,both,confirmation) default both,
    acknowledge_due_at, response_due_at, sla_policy_id NULL,
    status enum(Draft,Issued,Acknowledged,Responded,Under Review,
                Accepted,Rejected,Reissued,Closed,Withdrawn) default Draft,
    acknowledged_at NULL, acknowledged_by NULL → users,
    first_response_at NULL, last_response_at NULL,
    is_acknowledgement_late bool, is_response_late bool,
    reminder_count unsigned default 0, last_reminder_at NULL,
    matrix_escalated_at NULL,             -- when it fed the FR-8 tier ladder
    closed_at NULL, closed_by NULL → users, closure_note text NULL,
    withdrawn_at NULL, withdrawn_by NULL → users, withdrawal_reason text NULL,
    notified_to json NULL,                -- recipient snapshot [{id,name,email,via}]
    superseded_by_escalation_id NULL → self
    unique(tenant_id, reference)
    index(tenant_id, status, response_due_at), index(exception_id, round_no),
    index(tenant_id, unit_id, status)

  exception_responses            -- the department's answer. One per round.
    id, tenant_id, escalation_id, exception_id, round_no,
    responder_id NULL → users, responder_name NULL, responder_email NULL,
    submitted_at, channel enum(portal,secure_link),
    position enum(Accepted,Partially Accepted,Disputed,Already Remediated,
                  More Information Required),
    management_comment text,              -- the response body
    root_cause text NULL, root_cause_category enum(people,process,technology,
      governance,external) NULL,
    agreed_action_plan text NULL,
    proposed_target_date NULL,
    is_late bool,
    review_status enum(Pending Review,Accepted,Rejected,Superseded)
      default Pending Review,
    reviewed_by NULL → users, reviewed_at NULL, review_note text NULL,
    rejection_reason text NULL,
    ip_address NULL, user_agent NULL      -- for secure-link submissions
    index(tenant_id, review_status), index(escalation_id, round_no)

  exception_actions              -- the committed remediation the department owns
    id, tenant_id, exception_id, escalation_id, response_id,
    reference (EXA-…), title, description,
    action_type enum(corrective,preventive,detective) default corrective,
    owner_id → users, unit_id NULL → organisation_units,
    target_date, revised_target_date NULL, extension_reason NULL,
    extension_approved_by NULL → users,
    status enum(Not Started,In Progress,Completed,Overdue,Cancelled),
    progress_percentage decimal(5,2) default 0, progress_note text NULL,
    completed_at NULL, completed_by NULL → users,
    validation_status enum(Effective,Partially Effective,Ineffective) NULL,
    validated_by NULL → users, validated_at NULL, validation_note text NULL
    index(tenant_id, status, target_date)
    NOTE: this is the action a department COMMITS TO in its response. It is not
    the improvement database. Do not write to improvement_actions from here;
    link the two through entity_links if a tenant wants the roll-up.

  exception_response_links       -- secure answer link for respondents outside the app
    id, tenant_id, escalation_id, token_hash (unique, sha256), token_prefix(8),
    created_by → users, expires_at, revoked_at NULL, revoked_by NULL,
    status enum(active,used,revoked,expired) default active,
    access_count unsigned default 0, last_accessed_at NULL,
    submitted_at NULL, ip_address NULL, user_agent NULL
    NEVER store the plaintext token. Mirror external_auditee_links +
    hash_external_auditee_tokens from the internal audit product.

  exception_sla_policies         -- R1: no hard-coded SLA anywhere
    id, tenant_id, name, severity enum(Critical,High,Medium,Low),
    acknowledge_within_hours, respond_within_days,
    reminder_offsets json,       -- e.g. [-3,-1,1,3,7] business days around due
    max_reminders, escalate_after_days, auto_escalate_to_matrix bool,
    is_active bool
    unique(tenant_id, severity, name)

  exception_routing_rules        -- who a given exception is issued to by default
    id, tenant_id, sequence, is_active,
    match_unit_id NULL, match_process_id NULL, match_control_category_id NULL,
    match_severity NULL, match_source_type NULL,
    route_to_type enum(unit_head,process_owner,role,user),
    route_to_role NULL, route_to_user_id NULL, route_to_unit_id NULL,
    cc_role NULL, cc_user_ids json NULL, sla_policy_id NULL
    Evaluated in sequence order; first match wins; a rule only PRE-FILLS the
    issue form — the control officer always confirms the recipient.

  ALTER control_exceptions (one migration, additive, with down())
    escalation_status enum(None,Issued,Awaiting Response,Response Under Review,
      Response Accepted,Response Rejected,All Closed) default None,
    first_escalated_at NULL, last_response_at NULL,
    open_escalation_count unsigned default 0,
    is_response_overdue bool default false,
    closure_type enum(Remediated,Risk Accepted,Superseded,No Longer Applicable) NULL
    These are DENORMALISED CACHES maintained by the service. Never the source of
    truth — the escalation rows are.

  ALTER exception_activities.activity_type — add:
    'Escalation Issued','Escalation Withdrawn','Acknowledgement',
    'Response Submitted','Response Accepted','Response Rejected',
    'Reissued','Reminder Sent','Action Committed','Action Completed'
    It is a MySQL enum: alter it the way the existing enum-extension migrations
    in this repo do, and keep the down() lossless-or-explicit.

  ALTER escalation_matrices.trigger_condition — add:
    'escalation_unacknowledged','escalation_response_overdue',
    'escalation_response_rejected'
    Follow 2026_08_08_150011 / 2026_08_09_100013 exactly, and implement the three
    new branches in EscalationService::run()'s match. The ladder must not re-fire
    for an escalation that has already been answered.

BUILD

  CR1.1 Issue an exception to departments (fan-out)
    ExceptionEscalationService::issue(ControlException $e, array $targets, User $actor)
    - One escalation row per target. Three departments = three independent
      escalations, three clocks, three respondents, three response threads.
    - Resolve the respondent: explicit user > routing rule > unit head >
      process owner. If nothing resolves, the issue FAILS LOUDLY — a validation
      error to the issuer plus a logged warning. An escalation must never be
      created with nobody to answer it. (This repo has already been bitten once:
      see the "a public Speak-Up report reached nobody" fix.)
    - Compute acknowledge_due_at and response_due_at from the matching
      exception_sla_policy, in BUSINESS DAYS, in the tenant timezone, skipping
      public_holidays. Never from a constant in PHP.
    - Snapshot recipients into notified_to. Write an 'Escalation Issued'
      exception_activity and an audit_trail entry.
    - Set control_exceptions.escalation_status and open_escalation_count.
    - Issuing does not change the exception's own lifecycle status. An exception
      may be In Progress with three escalations outstanding.

  CR1.2 Delivery
    - Through NotificationDispatcher only. New seeded notification_events:
        exception.escalation.issued          (default in_app+email,
                                              is_user_configurable = FALSE —
                                              a department cannot mute an
                                              exception addressed to it)
        exception.escalation.acknowledgement_due
        exception.response.reminder
        exception.response.overdue
        exception.response.submitted         (to the control function)
        exception.response.accepted
        exception.response.rejected
        exception.escalation.closed
        exception.escalation.withdrawn
    - The email carries: reference, control, severity, what is being asked for,
      the response due date, and a single deep link to the response screen.
      It carries NO evidence and no Restricted-classification detail.
    - Respondents with an account get route('exception-manager.respond', …)
      behind auth. External processors get a secure-link URL built from a
      one-time plaintext token that is shown once at generation and never
      persisted.

  CR1.3 The departmental response
    - Authenticated screen for internal respondents; token screen for external.
    - Captures position, management comment, root cause + category, agreed
      action plan, proposed target date, and evidence uploads.
    - Save-draft (no status change) and Submit (Issued/Acknowledged → Responded).
    - required_response drives validation: 'both' means root cause AND action
      plan are mandatory unless position is Disputed, where a rebuttal comment
      and evidence are mandatory instead.
    - Submitting sets is_late by comparing submitted_at to response_due_at, and
      writes a 'Response Submitted' activity.
    - Opening the screen for the first time records the acknowledgement
      (acknowledged_at/by, is_acknowledgement_late) — the department cannot
      claim it never saw it.

  CR1.4 Review, reject, re-issue
    - The control function reviews: Accept or Reject with a reason.
    - Accept → escalation Accepted; committed actions become exception_actions
      owned by the department; the response clock stops.
    - Reject → response.review_status Rejected, escalation Rejected then
      Reissued with round_no + 1, a NEW response_due_at from the SLA policy, and
      a fresh notification. Prior rounds stay visible and immutable. This is the
      loop the client means by "we would expect response and closure".
    - Withdraw (control function only, reason mandatory) ends an escalation that
      was issued in error. Audited.

  CR1.5 Closure of the control lapse
    - An escalation closes when its accepted actions are Completed and the
      control function records validation (Effective / Partially Effective /
      Ineffective) against evidence. Partially Effective or Ineffective
      re-issues rather than closes.
    - ExceptionService::close() (Verified-Closed) is BLOCKED while any escalation
      on the exception is in a state other than Closed or Withdrawn. Throw a
      ValidationException naming the open escalations. FR-5.4 is unchanged: the
      department can reach Responded and Completed, never Verified-Closed.
    - Risk Accepted closure withdraws every open escalation with the acceptance
      reference as the withdrawal reason.

  CR1.6 Chase engine
    - php artisan exceptions:chase — daily, registered in routes/console.php
      alongside the existing scheduled work.
    - Sends acknowledgement and response reminders on the SLA policy's
      reminder_offsets, capped at max_reminders, IDEMPOTENT (a second run the
      same day sends nothing), increments reminder_count, writes a
      'Reminder Sent' activity.
    - Past escalate_after_days it stamps matrix_escalated_at and hands the
      escalation to EscalationService's tier ladder through the new trigger
      conditions — so an unanswered department climbs to the unit head, then the
      control function head, then executive management, then the board committee,
      exactly as FR-8.3 already specifies.
    - Marks is_response_overdue on the parent exception.

  CR1.7 The cockpit
    resources/js/Pages/ExceptionManager/Index.jsx — the register the client will
    demo. Rows are escalations, not exceptions. Filters: department, respondent,
    severity, status, overdue, ageing bucket, round > 1, source.
    Stat cards: issued, awaiting response, overdue, awaiting review, closed
    this period, average response days.
    ExceptionManager/Show.jsx — the full round-by-round thread: issue note,
    acknowledgement, response, review decision, re-issue, actions, closure.
    Exceptions/Show.jsx — add an "Escalations & Responses" panel plus an
    "Escalate to department" action.
    ExceptionManager/Respond.jsx — the authenticated response form.
    Public/ExceptionResponse.jsx — the token form. Branded, minimal, shows only
    this one exception, no navigation into the app.
    Settings/ExceptionRouting.jsx + Settings/ExceptionSla.jsx — admin config.

  CR1.8 Reporting
    - Department scorecard: issued, acknowledged on time %, responded on time %,
      average response days, open overdue, closure rate, re-issue rate.
    - Ageing 0-30 / 31-60 / 61-90 / 90+ by department and severity.
    - Exception Manager register PDF (ReportService) and XLSX
      (ExcelExportService), reusing the existing report routes and templates.
    - A board/ARCC extract: every exception escalated, unanswered past SLA, or
      re-issued more than once.
    - Add the new counters to DashboardService and the existing dashboard.

PERMISSIONS
  New: 'escalate exceptions', 'respond exceptions', 'review exception-responses',
       'withdraw exceptions', 'configure exception-routing'.
  Seed them in RolePermissionSeeder — NOT in the migration. RolePermissionSeeder
  uses syncPermissions(), so a grant made in a migration is wiped on the next
  seed. Control Function Head and Control Officer escalate, review and withdraw;
  Control Owner and Line Manager respond; System Administrator configures
  routing and SLA but does NOT gain review or close.

BUSINESS RULES
  R-A  The user who raised or issued an exception cannot submit the departmental
       response to it.
  R-B  The user who submitted a response cannot review it. No exceptions, no
       admin bypass.
  R-C  Only a holder of 'close exceptions' may accept a response or verify
       closure. FR-5.4 survives this change request intact.
  R-D  An exception cannot reach Verified-Closed with an escalation still open.
  R-E  Every SLA is read from exception_sla_policies. A reviewer finding a day
       count in a PHP class must move it to the table (R1).
  R-F  Escalation rows are append-mostly: rounds are never edited or deleted,
       only superseded. Responses are immutable once submitted.
  R-G  An escalation with no resolvable recipient is a hard failure at issue
       time, never a silent no-op.
  R-H  Secure links: hashed at rest, single escalation in scope, expiring,
       revocable, rate-limited, every access logged with IP and user agent.
       A token grants sight of ONE exception and the right to answer it —
       nothing else, no listing, no other tenant, ever.
  R-I  Tenant scoping on every new table and every new query. Recipient lookups
       that run outside a request (the chase command) must use
       withoutGlobalScope('tenant') with an explicit tenant_id filter, the way
       EscalationService already does.

TESTS  (tests/Feature — each of these is a named test)
  - Fan-out: issuing to three units creates three escalations with independent
    clocks, respondents and threads.
  - The raiser gets 403 on respond (SoD failing path).
  - The responder gets 403 on review (SoD failing path).
  - A System Administrator without 'close exceptions' gets 403 on accept and on
    close — the explicit no-admin-bypass test required by R2.
  - A Control Owner can submit a response and mark an action Completed but gets
    403 on Verified-Closed.
  - close() throws while an escalation is Issued/Responded/Under Review, and
    succeeds once all are Closed or Withdrawn.
  - Reject increments round_no, sets a new response_due_at, resumes the ladder,
    and leaves round 1 readable and unchanged.
  - is_late is true for a response one business day past due and false for one
    submitted on the due date at 23:59 tenant time.
  - The SLA clock skips weekends and a seeded public holiday, and is computed in
    the tenant timezone, not the server's.
  - Secure link: expired → denied; revoked → denied; a valid token for
    escalation A cannot read or answer escalation B; access increments
    access_count and records IP; brute-forcing the token is rate-limited.
  - exceptions:chase run twice in a day sends exactly one reminder set.
  - An issue attempt with no resolvable respondent fails with a validation error
    and creates no escalation row.
  - Changing an exception_sla_policy changes the computed due date — nothing is
    hard-coded.
  - exception.escalation.issued cannot be disabled in notification preferences.
  - Tenant isolation on exception_escalations, exception_responses,
    exception_actions and exception_response_links.
  - An audit_trail row exists for issue, acknowledge, respond, accept, reject,
    reissue, withdraw and close.

DELIVERABLES
  ~9 migrations (6 create, 3 alter), 4 models with the Auditable,
  BelongsToTenant and GeneratesReference traits, ExceptionEscalationService and
  ExceptionResponseService, ExceptionSlaCalculator (business-day clock),
  ExceptionRoutingResolver, ExceptionManagerController,
  ExceptionResponseController, PublicExceptionResponseController,
  ExceptionRoutingController, Form Requests for every write, policies for
  ExceptionEscalation / ExceptionResponse / ExceptionAction, nine notification
  classes extending PreferenceRoutedNotification, the ChaseExceptions command,
  the React pages above, seeder updates (RolePermissionSeeder,
  NotificationEventSeeder, a default SLA policy set and demo escalations in the
  demo seeder), the tests above, README section, and an update to
  Control-Solution-BRD-v1.0.md §5 recording FR-5.11..FR-5.20 for this change
  request.

DEFINITION OF DONE
  - php artisan migrate:fresh --seed runs clean.
  - The full test suite is green, including the existing
    SegregationOfDutiesTest.
  - A demo path works end to end on seeded data: raise an exception → issue it
    to two departments → both respondents receive an in-app notice and an email
    → one responds and is accepted → one is rejected, re-issued, responds again,
    is accepted → actions are completed and validated → the exception is
    Verified-Closed → the register, the department scorecard and the PDF/XLSX
    exports all show the closed loop.
  - No hard-coded SLA, day count, role name or recipient anywhere in the diff.
  - Commit on branch claude/atheris-exception-manager-hy7wvq.
```

---

## Notes for the person running this

**Why it is one prompt and not three.** The escalation, the response and the closure
are one loop; splitting them leaves a half-built feature that cannot be demonstrated.
It is large but it is inside a single context if the session does nothing else.

**If the session runs out of context**, cut at CR1.6 and run CR1.7 and CR1.8 as a
second session — the cockpit and reporting depend on the data model but nothing
depends on them.

**The two escalation concepts are deliberately separate.** `escalation_matrices` is
the automatic, time-driven ladder that FR-8 already specifies. `exception_escalations`
is the deliberate, addressed instrument the client is asking for. They meet at
CR1.6: an unanswered department feeds the ladder.

**What to confirm with the client before running it:**
- whether "processors" means outsourced service providers who have no account
  (the secure-link path, built above) or internal process owners (the
  `business_processes` path, also built above) — both are covered, but which one
  the demo should lead with changes the seeded data;
- whether a department may dispute an exception outright, and who arbitrates a
  dispute that survives two rejection rounds;
- the default response SLA per severity, so the seeded `exception_sla_policies`
  match the client's own control policy on day one.
