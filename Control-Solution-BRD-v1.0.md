# Business Requirements Document

## Atheris Control Solution — Control Testing & Exception Management Platform

### Working name: **SecondLine** *(second line of defence — sibling to ThirdLine)*

---

| Field | Detail |
| :---- | :---- |
| Document Title | Control Solution — Business Requirements Document |
| Version | 1.0 (Draft for review) |
| Product Owner | Atheris Limited |
| Target Market | Nigerian and African banks, mortgage banks, microfinance banks, and fintechs |
| Technology Stack | Laravel 11 \+ Inertia.js \+ React (aligned to ThirdLine) |
| Design System | AEGIS — Navy `#0B1F3A`, Gold `#C9A227` |
| Related Products | ThirdLine (Internal Audit), NexusRisk IRM (ERM), ThirdLine Compliance |
| Status | Draft — pending stakeholder sign-off |

---

## 1\. Executive Summary

The Control Solution is a second-line-of-defence platform that enables control functions in banks and fintechs to **define, test, rate, and monitor internal controls**, and to **track exceptions through to verified closure**.

Where ThirdLine gives internal audit an independent assurance workflow (third line), the Control Solution gives risk and control officers, compliance, and operations the tooling to run continuous control monitoring (second line). The two products exchange control definitions and test results by API, so audit can place reliance on control testing already performed rather than duplicating it.

The scope deliberately favours operational discipline over analytical sophistication: a strong control library, checklist-driven testing, an exception tracker that cannot be self-closed, an escalation engine that creates accountability, and a simple severity-and-overdue dashboard.

---

## 2\. Business Drivers

| Driver | Implication for the solution |
| :---- | :---- |
| CBN risk-based supervision expects demonstrable, evidenced internal control testing | Every test must carry evidence, a tester, a date, and an effectiveness conclusion |
| Basel / IIA "Three Lines" model | Clear separation between control owner (1st line), control function (2nd line), and internal audit (3rd line) — enforced in RBAC |
| Regulators and boards ask "what is still open and how old is it?" | Overdue and unresolved-by-severity are first-class dashboard metrics |
| NDPA 2023 / NDPR obligations on customer data | Evidence captured during testing may contain customer PII; retention, minimisation, access control, and disposal must be built in, not bolted on |
| Manual control testing is spreadsheet-bound and memory-dependent | Structured checklists replace tester judgement about *what* to check |
| Audit and control functions duplicate the same testing | API integration with ThirdLine so audit can rely on second-line testing |

---

## 3\. Scope

### 3.1 In Scope (Version 1\)

- Control library (standard \+ institution-defined)  
- Risk-to-control mapping and inherent/residual risk reduction  
- Control testing via structured checklists, scheduled and ad-hoc  
- Spot checks with inline findings and configurable report output  
- Exception management with control-function-only closure  
- Compensating control registration when a control fails  
- Control effectiveness rating — design and operating effectiveness  
- Tiered escalation engine  
- Evidence repository with retention policy and NDPA safeguards  
- Severity and overdue dashboard, exportable reports  
- API integration with ThirdLine Internal Audit  
- Multi-tenant deployment consistent with the existing branch-per-client model  
- Full audit trail

### 3.2 Out of Scope (Version 1\)

- Automated control testing against core banking data (deferred to Phase 5 — Continuous Controls Monitoring)  
- Full RCSA facilitation workflow (consumed from NexusRisk where available)  
- Policy management and attestation  
- Loss event / incident management  
- Regulatory returns generation  
- Mobile native applications

---

## 4\. Stakeholders and User Roles

| Role | Line | Core rights |
| :---- | :---- | :---- |
| **Control Officer / Tester** | 2nd | Define controls, build checklists, execute tests and spot checks, raise exceptions, propose effectiveness ratings |
| **Control Function Head** | 2nd | Approve control definitions, approve effectiveness ratings, **verify and close exceptions**, approve compensating controls |
| **Control Owner** | 1st | View controls they own, respond to exceptions, submit remediation and evidence, request extensions |
| **Line Manager / Unit Head** | 1st | Escalation recipient for their unit; oversight of owner responses |
| **Executive / CAE / Board Viewer** | — | Read-only dashboards and reports; no data entry |
| **Internal Auditor (via ThirdLine)** | 3rd | Consume control definitions and test results by API; no write access to the Control Solution |
| **System Administrator** | — | Tenancy, users, roles, escalation matrices, retention policies, integrations |

**Segregation of duties rule (system-enforced):** a user who executes a control test cannot verify and close the resulting exception; a control owner cannot close an exception on their own control.

---

## 5\. Functional Requirements

### Module 1 — Control Library

| ID | Requirement | Priority |
| :---- | :---- | :---- |
| FR-1.1 | Maintain a central library of controls, each with a unique control ID, title, description, and control objective | Must |
| FR-1.2 | Classify controls by type (Preventive / Detective / Corrective), nature (Manual / Automated / Hybrid / IT-Dependent Manual), and category (e.g. Segregation of Duties, Access Management, Authorisation, Reconciliation, Dual Control, Cut-off, Physical Security) | Must |
| FR-1.3 | Ship a pre-seeded starter library of standard banking controls that a tenant can adopt, edit, or ignore | Must |
| FR-1.4 | Allow institutions to define their own controls and their own control categories | Must |
| FR-1.5 | Assign each control to a control owner, a business process, and an organisational unit | Must |
| FR-1.6 | Define testing frequency per control (Daily, Weekly, Monthly, Quarterly, Semi-annual, Annual, Event-driven) | Must |
| FR-1.7 | Version control definitions — every amendment retains the prior version with effective dates | Must |
| FR-1.8 | Route new and amended controls through a maker–checker approval before they become active | Must |
| FR-1.9 | Support control status lifecycle: Draft → Pending Approval → Active → Under Review → Retired | Must |
| FR-1.10 | Bulk import controls from Excel/CSV during onboarding | Should |
| FR-1.11 | Map controls to regulatory or framework references (CBN circulars, ISO 27001, COBIT, PCI DSS, NDPA) | Should |

### Module 2 — Risk and Control Linkage

| ID | Requirement | Priority |
| :---- | :---- | :---- |
| FR-2.1 | Maintain or consume a risk register sourced from risk and control self-assessment (RCSA) | Must |
| FR-2.2 | Map controls to risks in a many-to-many relationship — one control may mitigate several risks, one risk may require several controls | Must |
| FR-2.3 | Record inherent risk rating (likelihood × impact) per risk | Must |
| FR-2.4 | Compute residual risk from inherent rating adjusted by the aggregate effectiveness of mapped controls | Must |
| FR-2.5 | Flag risks with **no mapped control** (control gaps) and controls with **no mapped risk** (orphan controls) | Must |
| FR-2.6 | Where NexusRisk IRM is deployed, consume the risk register by API rather than maintaining a duplicate | Should |
| FR-2.7 | Display a risk-control heat map showing exposure before and after controls | Should |

### Module 3 — Control Testing (Checklist-Driven)

| ID | Requirement | Priority |
| :---- | :---- | :---- |
| FR-3.1 | Build a reusable test checklist ("test script") per control, composed of ordered check items | Must |
| FR-3.2 | Each check item records a result: **Pass / Fail / Not Applicable**, with a mandatory comment on Fail or N/A | Must |
| FR-3.3 | Attach evidence at check-item level and at test level (documents, screenshots, extracts) | Must |
| FR-3.4 | Auto-generate a test instance on the control's defined frequency, with a due date and an assigned tester | Must |
| FR-3.5 | Record sampling basis where applicable: population size, sample size, sampling method, items selected | Must |
| FR-3.6 | **Automatically raise an exception from every Failed check item** — no manual re-keying | Must |
| FR-3.7 | Test instance lifecycle: Scheduled → In Progress → Submitted for Review → Reviewed → Closed | Must |
| FR-3.8 | Reviewer (Control Function Head) can return a submitted test to the tester with review notes | Must |
| FR-3.9 | Lock a test instance after review sign-off; further changes require a formal reopen with reason, captured in the audit trail | Must |
| FR-3.10 | Track testing completion rate and overdue tests per unit, per owner, per period | Must |

### Module 4 — Spot Checks

| ID | Requirement | Priority |
| :---- | :---- | :---- |
| FR-4.1 | Initiate an ad-hoc spot check outside the testing calendar, against one or more controls, a process, or a branch/unit | Must |
| FR-4.2 | Capture findings inline during the spot check, with severity, evidence, and observation text | Must |
| FR-4.3 | Findings raised during a spot check flow into the same exception tracker as scheduled test failures | Must |
| FR-4.4 | Generate a spot check report on completion, containing scope, period, locations covered, findings, and management responses | Must |
| FR-4.5 | **Configurable report templates** — administrators define the report layout, sections, header/footer, and logo per tenant rather than using a fixed format | Must |
| FR-4.6 | Export reports to PDF and Word | Must |
| FR-4.7 | Support surprise/unannounced spot checks where the target unit is not notified in advance | Should |
| FR-4.8 | Record management response and agreed action per finding before report issuance | Should |

### Module 5 — Exception Management

| ID | Requirement | Priority |
| :---- | :---- | :---- |
| FR-5.1 | Maintain a central exception tracker aggregating exceptions from control tests, spot checks, and manual entry | Must |
| FR-5.2 | Each exception carries: reference, source, linked control and risk, description, root cause, severity (Critical / High / Medium / Low), control owner, responsible party, date raised, target closure date | Must |
| FR-5.3 | Exception lifecycle: Open → Assigned → In Progress → Remediated (pending verification) → **Verified/Closed** → (or) Accepted Risk | Must |
| FR-5.4 | **Only the control function may move an exception to Closed, and only after verifying remediation evidence.** Control owners may mark "Remediated" but cannot close | Must |
| FR-5.5 | Closure requires the verifier to record verification method, verification evidence, and verification date | Must |
| FR-5.6 | Age each exception from the date raised; auto-flag Overdue when past target closure date | Must |
| FR-5.7 | Support due-date extension requests from the owner, requiring control function approval and a documented reason | Must |
| FR-5.8 | Support formal risk acceptance for exceptions that will not be remediated, requiring senior approval, an expiry date, and periodic re-confirmation | Must |
| FR-5.9 | Detect and link recurring exceptions — the same control failing in consecutive periods — and flag them for elevated attention | Should |
| FR-5.10 | Full commentary thread per exception with timestamped, attributed entries | Must |

**Change request CR-01 — Exception Manager: departmental escalation, response capture and closure tracking.** An exception is no longer only an internal tracker row: the control function ISSUES it as a formal, addressed instrument to one or more departments or processors, the named respondent ANSWERS on the record, and the answer is reviewed round by round before the lapse can close.

| ID | Requirement | Priority |
| :---- | :---- | :---- |
| FR-5.11 | The control function can issue an exception to one or more departments, business processes, named users or external processors (fan-out) — one escalation per target, each with its own reference, respondent, SLA clock and response thread. An escalation can never be created without a resolvable respondent: resolution runs explicit user → routing rule → unit head → process owner, and failure is a hard validation error, never a silent no-op | Must |
| FR-5.12 | Each issued escalation lands in the named respondent's inbox as an in-app notice and an email carrying the reference, control, severity, what is being asked for, the response due date and a single deep link to the response screen — never evidence or Restricted-classification detail. The issue notice cannot be muted in notification preferences | Must |
| FR-5.13 | Acknowledgement and response deadlines are computed from configurable per-severity SLA policies as business days in the tenant's timezone, skipping seeded public holidays. No SLA, day count, role name or recipient is hard-coded | Must |
| FR-5.14 | Opening the response screen for the first time records the acknowledgement (who, when, and whether late) — a department cannot claim it never saw the notice | Must |
| FR-5.15 | The departmental response is structured: position (Accepted / Partially Accepted / Disputed / Already Remediated / More Information Required), management comment, root cause and category, agreed action plan, proposed target date and evidence. The required content is driven by the escalation's required-response setting; a Disputed position demands a substantive rebuttal and evidence instead. Responses save as drafts and are immutable once submitted | Must |
| FR-5.16 | The control function reviews each response: Accept converts the committed plan into a tracked remediation action owned by the department; Reject (with a mandatory reason) re-issues the escalation as a new round with a fresh SLA clock, leaving prior rounds readable and unchanged. Segregation of duties binds every role with no admin bypass: the raiser/issuer cannot respond, the responder cannot review, and only a holder of 'close exceptions' may accept a response or verify closure | Must |
| FR-5.17 | An escalation closes only when its accepted actions are Completed and the control function records validation against evidence (Effective / Partially Effective / Ineffective); anything short of Effective re-issues rather than closes. An exception cannot reach Verified-Closed while any escalation on it is open; risk-accepted closure withdraws all open escalations with the acceptance as the reason | Must |
| FR-5.18 | External processors without an account answer through a hashed, expiring, revocable, rate-limited secure link scoped to a single escalation, with every access logged with IP and user agent. The plaintext token is shown once at generation and never stored | Must |
| FR-5.19 | A daily, idempotent chase engine sends acknowledgement and response reminders on the SLA policy's business-day offsets (capped per policy) and, past the policy's escalation threshold, hands the unanswered escalation to the FR-8 escalation matrix through dedicated trigger conditions so it climbs the tier ladder | Must |
| FR-5.20 | The Exception Manager register (rows are escalations), a per-department scorecard (acknowledged/responded on time, average response days, closure and re-issue rates), ageing by department and severity, PDF/XLSX exports and a board/ARCC extract of everything escalated, unanswered past SLA or re-issued more than once | Must |

### Module 6 — Compensating Controls

| ID | Requirement | Priority |
| :---- | :---- | :---- |
| FR-6.1 | When a control is rated ineffective or fails testing, allow registration of one or more compensating controls | Must |
| FR-6.2 | Capture for each compensating control: description, owner, whether it is temporary or permanent, effective from/to dates, and the residual exposure it does **not** cover | Must |
| FR-6.3 | Require control function approval before a compensating control is recognised in the residual risk calculation | Must |
| FR-6.4 | Test compensating controls in their own right — they enter the testing calendar like any other control | Must |
| FR-6.5 | Expire temporary compensating controls automatically at the end date and re-escalate the underlying exception if the primary control is still not remediated | Must |
| FR-6.6 | Report on all active compensating controls, their age, and the primary control weaknesses they cover | Should |

### Module 7 — Control Effectiveness Rating

| ID | Requirement | Priority |
| :---- | :---- | :---- |
| FR-7.1 | Rate every tested control on **Design Effectiveness** — is the control, as designed, capable of mitigating the risk? | Must |
| FR-7.2 | Rate every tested control on **Operating Effectiveness** — did the control operate as designed throughout the period? | Must |
| FR-7.3 | Use a consistent scale for both: Effective / Partially Effective / Ineffective / Not Tested | Must |
| FR-7.4 | Derive an overall control rating from the two dimensions using a configurable matrix (default: a design-ineffective control cannot be rated better than Partially Effective overall) | Must |
| FR-7.5 | Require a documented rationale for any rating other than Effective | Must |
| FR-7.6 | Route effectiveness ratings through control function approval before they are published | Must |
| FR-7.7 | Retain rating history per control per period, and trend it over time | Must |
| FR-7.8 | Feed the overall rating into the residual risk calculation in Module 2 | Must |

### Module 8 — Escalation Engine

| ID | Requirement | Priority |
| :---- | :---- | :---- |
| FR-8.1 | Configure escalation matrices per tenant, keyed on severity | Must |
| FR-8.2 | Trigger conditions: check item failed; exception unassigned after N days; exception past due date; exception with no activity for N days; test instance overdue; extension request pending | Must |
| FR-8.3 | Tiered escalation path: Control Owner → Line Manager / Unit Head → Control Function Head → Executive Management → Board Audit & Risk Committee | Must |
| FR-8.4 | Configure escalation intervals per severity — Critical escalates faster and further than Low | Must |
| FR-8.5 | Deliver escalations by in-app notification and email; SMS optional | Must |
| FR-8.6 | Send a periodic digest to each control owner listing their open and overdue items | Must |
| FR-8.7 | Log every escalation — trigger, recipient, timestamp, delivery status — in the audit trail | Must |
| FR-8.8 | Suspend escalation automatically when an exception moves to Remediated, and resume it if verification fails | Must |

### Module 9 — Evidence Management, Retention and Data Protection

| ID | Requirement | Priority |
| :---- | :---- | :---- |
| FR-9.1 | Store all evidence in a controlled repository, linked to its test, check item, exception, or finding | Must |
| FR-9.2 | Classify every evidence item at upload: **Contains Customer Personal Data — Yes/No**, and data category where Yes | Must |
| FR-9.3 | Enforce configurable retention periods per evidence class; default retention aligned to statutory record-keeping requirements for financial institutions, with tenant override | Must |
| FR-9.4 | Apply data minimisation guidance at upload — prompt testers to redact account numbers, BVN, and identity data unless the data is essential to the test conclusion | Must |
| FR-9.5 | Encrypt evidence at rest and in transit | Must |
| FR-9.6 | Restrict evidence access to users with a role-based need; log every view and download | Must |
| FR-9.7 | Provide legal hold — suspend deletion for evidence linked to an open investigation, litigation, or regulatory examination | Must |
| FR-9.8 | Automate disposal at end of retention, with a documented disposal log and a dual-approval step before permanent deletion | Must |
| FR-9.9 | Maintain a record of processing activities for evidence containing personal data, sufficient to answer an NDPA audit | Must |
| FR-9.10 | Support redaction of evidence in place, retaining the unredacted original under stricter access control where operationally required | Should |
| FR-9.11 | Support data subject request handling — locate and report on personal data held in evidence for a named individual | Should |

> **Note for legal review:** the retention periods, lawful basis for processing evidence containing customer data, and the data subject request workflow should be confirmed with a Nigerian data protection practitioner and the client's Data Protection Officer before go-live. This BRD specifies the capability; the parameter values are a client configuration decision.

### Module 10 — Dashboard and Reporting

| ID | Requirement | Priority |
| :---- | :---- | :---- |
| FR-10.1 | Executive dashboard showing: open vs closed exceptions; breakdown by severity (Critical / High / Medium / Low); **overdue findings**; unresolved Critical and High items | Must |
| FR-10.2 | Every dashboard tile drills through to the underlying record list | Must |
| FR-10.3 | Control testing completion rate and overdue tests by period, unit, and owner | Must |
| FR-10.4 | Control effectiveness distribution — how many controls are Effective, Partially Effective, Ineffective | Must |
| FR-10.5 | Ageing analysis of open exceptions (0–30, 31–60, 61–90, 90+ days) | Must |
| FR-10.6 | Filters: period, unit, process, control owner, severity, status, risk | Must |
| FR-10.7 | Export any report to PDF and Excel; scheduled email delivery of standard reports | Must |
| FR-10.8 | Board and committee pack generation from selected reports in a single document | Should |
| FR-10.9 | Keep the dashboard deliberately simple — no configurable widget builder in Version 1 | Must |

### Module 11 — Integration Layer

| ID | Requirement | Priority |
| :---- | :---- | :---- |
| FR-11.1 | Expose a REST API that publishes control definitions to ThirdLine Internal Audit when a control is defined, approved, or amended by control officers | Must |
| FR-11.2 | Publish control test results, effectiveness ratings, and open exceptions to ThirdLine so internal audit can place reliance on second-line testing | Must |
| FR-11.3 | Support **bidirectional synchronisation**: where control officers define controls inside ThirdLine's existing control feature, those definitions can flow into the Control Solution | Must |
| FR-11.4 | Configure integration direction at setup per tenant: Control Solution as master, ThirdLine as master, or bidirectional with conflict rules | Must |
| FR-11.5 | Maintain a stable external control identifier so records match across both systems, and never re-key a control manually across products | Must |
| FR-11.6 | Authenticate integration by OAuth 2.0 client credentials or signed API keys, scoped per tenant | Must |
| FR-11.7 | Log every synchronisation event with payload reference, direction, status, and error detail; support replay of failed events | Must |
| FR-11.8 | Consume the risk register from NexusRisk IRM where deployed | Should |
| FR-11.9 | Webhook subscriptions so ThirdLine is notified in near real time of new Critical/High exceptions | Should |
| FR-11.10 | Publish an OpenAPI specification and integration guide | Must |

### Module 12 — Administration, Security and Audit Trail

| ID | Requirement | Priority |
| :---- | :---- | :---- |
| FR-12.1 | Multi-tenant architecture consistent with the existing per-client branch deployment model, integrated with the LicensingServer | Must |
| FR-12.2 | Role-based access control with the roles in Section 4, plus custom role definition | Must |
| FR-12.3 | Enforce segregation of duties in code — tester ≠ verifier; owner cannot close own exception | Must |
| FR-12.4 | Immutable audit trail of every create, update, delete, approval, closure, escalation, export, and evidence access — user, timestamp, before/after values, IP address | Must |
| FR-12.5 | Maintain organisational hierarchy, business process catalogue, and unit/branch register | Must |
| FR-12.6 | Configurable notification templates and escalation matrices per tenant | Must |
| FR-12.7 | Single sign-on via SAML 2.0 / OIDC, with MFA support | Should |
| FR-12.8 | Session timeout, password policy, and account lockout aligned to CBN IT standards | Must |

---

## 6\. Non-Functional Requirements

| ID | Category | Requirement |
| :---- | :---- | :---- |
| NFR-1 | Performance | Dashboard renders within 3 seconds for a tenant with 5,000 controls and 50,000 exceptions |
| NFR-2 | Scalability | Support 500 concurrent users and 100+ branches per tenant |
| NFR-3 | Availability | 99.5% uptime target for hosted deployments |
| NFR-4 | Security | Encryption at rest (AES-256) and in transit (TLS 1.2+); OWASP Top 10 remediated; annual penetration test |
| NFR-5 | Data residency | Support in-country hosting where the client requires Nigerian data residency |
| NFR-6 | Auditability | No hard deletes of transactional records; soft delete with audit trail only |
| NFR-7 | Backup | Daily automated backup, 30-day point-in-time recovery, tested restore quarterly |
| NFR-8 | Usability | A control tester can complete a routine test in under 5 minutes; no training required beyond a one-page guide |
| NFR-9 | Browser support | Latest two versions of Chrome, Edge, Firefox, Safari; responsive down to tablet width |
| NFR-10 | Localisation | Nigerian Naira, West Africa Time, DD/MM/YYYY date format as defaults |
| NFR-11 | Maintainability | Master branch holds core product; client branches receive downstream merges, consistent with ThirdLine practice |

---

## 7\. Data Model

### 7.1 Entity Relationship Diagram

erDiagram

    ORGANISATION\_UNIT ||--o{ BUSINESS\_PROCESS : contains

    BUSINESS\_PROCESS ||--o{ CONTROL : "is governed by"

    RISK }o--o{ CONTROL : "mitigated by (risk\_control\_map)"

    CONTROL ||--o{ CONTROL\_VERSION : "has history"

    CONTROL ||--|| USER : "owned by"

    CONTROL ||--o{ TEST\_SCRIPT : "tested using"

    TEST\_SCRIPT ||--o{ CHECK\_ITEM : "composed of"

    CONTROL ||--o{ TEST\_INSTANCE : "scheduled as"

    TEST\_INSTANCE ||--o{ CHECK\_RESULT : records

    CHECK\_ITEM ||--o{ CHECK\_RESULT : "answered by"

    CHECK\_RESULT ||--o{ EXCEPTION : raises

    SPOT\_CHECK ||--o{ FINDING : produces

    FINDING ||--o{ EXCEPTION : raises

    EXCEPTION ||--o{ EXCEPTION\_ACTIVITY : "tracked by"

    EXCEPTION ||--o{ ESCALATION\_EVENT : triggers

    EXCEPTION ||--o{ COMPENSATING\_CONTROL : "mitigated by"

    TEST\_INSTANCE ||--|| EFFECTIVENESS\_RATING : concludes

    TEST\_INSTANCE ||--o{ EVIDENCE : supports

    EXCEPTION ||--o{ EVIDENCE : supports

    SPOT\_CHECK ||--o{ EVIDENCE : supports

    EVIDENCE ||--|| RETENTION\_POLICY : "governed by"

    USER }o--|| ROLE : holds

    TENANT ||--o{ USER : employs

    TENANT ||--o{ ESCALATION\_MATRIX : configures

    CONTROL ||--o{ INTEGRATION\_SYNC\_LOG : "synchronised via"

### 7.2 Core Entities

**TENANT** — `id`, `name`, `licence_key`, `data_residency`, `status`, `settings_json`

**ORGANISATION\_UNIT** — `id`, `tenant_id`, `parent_id`, `name`, `type` (Head Office / Branch / Department), `head_user_id`

**BUSINESS\_PROCESS** — `id`, `tenant_id`, `unit_id`, `code`, `name`, `description`, `process_owner_id`

**RISK** — `id`, `tenant_id`, `external_ref` (NexusRisk ID), `code`, `title`, `description`, `category`, `inherent_likelihood`, `inherent_impact`, `inherent_rating`, `residual_rating` (derived), `risk_owner_id`, `source` (Local / NexusRisk), `status`

**CONTROL** — `id`, `tenant_id`, `control_ref`, `external_ref` (ThirdLine ID), `title`, `description`, `objective`, `type` (Preventive/Detective/Corrective), `nature` (Manual/Automated/Hybrid/ITDM), `category_id`, `process_id`, `unit_id`, `owner_id`, `frequency`, `is_key_control`, `framework_refs_json`, `status` (Draft/Pending/Active/Under Review/Retired), `current_version`, `approved_by`, `approved_at`, `sync_status`

**CONTROL\_VERSION** — `id`, `control_id`, `version_no`, `payload_json`, `effective_from`, `effective_to`, `changed_by`, `change_reason`

**RISK\_CONTROL\_MAP** — `id`, `risk_id`, `control_id`, `contribution_weight`, `mapped_by`, `mapped_at`

**TEST\_SCRIPT** — `id`, `control_id`, `version_no`, `title`, `objective`, `sampling_guidance`, `status`, `approved_by`

**CHECK\_ITEM** — `id`, `test_script_id`, `sequence`, `question`, `guidance`, `expected_result`, `is_mandatory`, `default_severity_on_fail`

**TEST\_INSTANCE** — `id`, `control_id`, `test_script_id`, `period_label`, `period_start`, `period_end`, `due_date`, `assigned_tester_id`, `reviewer_id`, `status` (Scheduled/In Progress/Submitted/Reviewed/Closed/Reopened), `population_size`, `sample_size`, `sampling_method`, `started_at`, `submitted_at`, `reviewed_at`, `is_overdue` (derived)

**CHECK\_RESULT** — `id`, `test_instance_id`, `check_item_id`, `result` (Pass/Fail/NA), `comment`, `tested_by`, `tested_at`

**EFFECTIVENESS\_RATING** — `id`, `test_instance_id`, `control_id`, `period_label`, `design_effectiveness` (Effective/Partially Effective/Ineffective/Not Tested), `design_rationale`, `operating_effectiveness`, `operating_rationale`, `overall_rating` (derived via matrix), `rated_by`, `approved_by`, `approved_at`

**SPOT\_CHECK** — `id`, `tenant_id`, `reference`, `title`, `scope_description`, `unit_id`, `process_id`, `is_surprise`, `conducted_by`, `date_conducted`, `report_template_id`, `status`, `report_issued_at`

**FINDING** — `id`, `spot_check_id`, `control_id` (nullable), `observation`, `severity`, `risk_implication`, `recommendation`, `management_response`, `agreed_action`, `responsible_party_id`, `target_date`

**EXCEPTION** — `id`, `tenant_id`, `reference`, `source_type` (Test/Spot Check/Manual), `source_id`, `control_id`, `risk_id`, `title`, `description`, `root_cause`, `severity` (Critical/High/Medium/Low), `owner_id`, `responsible_party_id`, `unit_id`, `date_raised`, `target_closure_date`, `status` (Open/Assigned/In Progress/Remediated/Verified-Closed/Risk Accepted), `remediation_plan`, `remediated_at`, `remediated_by`, `verification_method`, `verified_by`, `verified_at`, `closure_notes`, `age_days` (derived), `is_overdue` (derived), `is_recurring`, `recurrence_of_exception_id`, `extension_count`

**EXCEPTION\_ACTIVITY** — `id`, `exception_id`, `activity_type` (Comment/Status Change/Assignment/Extension Request/Extension Decision/Evidence Upload), `actor_id`, `from_value`, `to_value`, `note`, `created_at`

**COMPENSATING\_CONTROL** — `id`, `exception_id`, `primary_control_id`, `description`, `owner_id`, `is_temporary`, `effective_from`, `effective_to`, `residual_exposure_note`, `approved_by`, `approved_at`, `status` (Proposed/Approved/Active/Expired/Withdrawn), `linked_control_id` (where registered as a testable control)

**ESCALATION\_MATRIX** — `id`, `tenant_id`, `severity`, `tier_no`, `trigger_condition`, `days_threshold`, `recipient_role`, `recipient_user_id`, `channel`, `is_active`

**ESCALATION\_EVENT** — `id`, `exception_id` / `test_instance_id`, `matrix_id`, `tier_no`, `recipient_user_id`, `channel`, `triggered_at`, `delivery_status`, `payload_summary`

**EVIDENCE** — `id`, `tenant_id`, `linked_type` (Test Instance/Check Result/Exception/Finding), `linked_id`, `file_name`, `storage_path`, `mime_type`, `file_size`, `checksum`, `contains_personal_data` (bool), `personal_data_categories`, `classification` (Public/Internal/Confidential/Restricted), `retention_policy_id`, `retention_expiry_date`, `legal_hold` (bool), `uploaded_by`, `uploaded_at`, `disposed_at`, `disposal_approved_by`

**RETENTION\_POLICY** — `id`, `tenant_id`, `name`, `evidence_class`, `retention_months`, `disposal_action` (Delete/Anonymise), `requires_dual_approval`, `legal_basis_note`

**EVIDENCE\_ACCESS\_LOG** — `id`, `evidence_id`, `user_id`, `action` (View/Download/Redact), `ip_address`, `accessed_at`

**REPORT\_TEMPLATE** — `id`, `tenant_id`, `name`, `report_type` (Spot Check/Test Summary/Exception Register/Board Pack), `sections_json`, `header_config`, `footer_config`, `logo_path`, `is_default`

**INTEGRATION\_CONFIG** — `id`, `tenant_id`, `target_system` (ThirdLine/NexusRisk), `base_url`, `auth_type`, `credentials_encrypted`, `sync_direction` (Push/Pull/Bidirectional), `master_system`, `conflict_rule`, `entities_enabled_json`, `is_active`

**INTEGRATION\_SYNC\_LOG** — `id`, `config_id`, `entity_type`, `local_id`, `external_id`, `direction`, `payload_ref`, `status` (Success/Failed/Retrying), `error_message`, `attempted_at`, `replayed_from_id`

**AUDIT\_TRAIL** — `id`, `tenant_id`, `user_id`, `entity_type`, `entity_id`, `action`, `before_json`, `after_json`, `ip_address`, `user_agent`, `created_at`

### 7.3 Derived Logic

**Overall control rating matrix (default, configurable):**

| Design ↓ / Operating → | Effective | Partially Effective | Ineffective | Not Tested |
| :---- | :---- | :---- | :---- | :---- |
| **Effective** | Effective | Partially Effective | Ineffective | Not Tested |
| **Partially Effective** | Partially Effective | Partially Effective | Ineffective | Not Tested |
| **Ineffective** | Ineffective | Ineffective | Ineffective | Ineffective |
| **Not Tested** | Not Tested | Not Tested | Ineffective | Not Tested |

**Residual risk:** inherent rating reduced by the weighted aggregate of `overall_rating` across all mapped active controls, plus approved active compensating controls. A risk with no Effective control cannot show a residual rating below its inherent rating.

---

## 8\. Integration Architecture — Control Solution ↔ ThirdLine

### 8.1 Design Principle

A control is **defined once and consumed everywhere.** Whichever system a control officer works in, the definition propagates so that internal audit is testing against the same control register the second line maintains.

### 8.2 Configuration Options (set per tenant at onboarding)

| Mode | Behaviour | Use case |
| :---- | :---- | :---- |
| **Control Solution as master** | Controls defined here push to ThirdLine on approval; ThirdLine is read-only for control definitions | Client has a mature second-line control function |
| **ThirdLine as master** | Controls defined in ThirdLine's existing control feature pull into the Control Solution | Client already runs controls out of ThirdLine and is adding second-line capability |
| **Bidirectional** | Either side may originate; conflict resolved by `last_approved_at` wins, or by designated master per control category | Client with both functions defining controls independently |

### 8.3 Synchronised Objects

| Direction | Object | Trigger |
| :---- | :---- | :---- |
| CS → TL | Control definition (create, amend, retire) | On approval |
| CS → TL | Test instance result and effectiveness rating | On test review sign-off |
| CS → TL | Open exceptions with severity, age, and status | On create and on status change |
| CS → TL | Compensating controls | On approval |
| TL → CS | Control definitions originated in ThirdLine | On approval |
| TL → CS | Audit findings that identify a control weakness | On finding issuance |
| NexusRisk → CS | Risk register with inherent ratings | On publish |
| CS → NexusRisk | Control effectiveness feeding residual risk | On rating approval |

### 8.4 Indicative Endpoints

POST   /api/v1/controls                 Publish a control definition

PUT    /api/v1/controls/{external\_ref}  Amend a control definition

POST   /api/v1/controls/{ref}/retire    Retire a control

GET    /api/v1/controls?since={ts}      Pull controls changed since timestamp

POST   /api/v1/test-results             Publish a completed test with rating

GET    /api/v1/exceptions?status=open   Pull open exceptions for reliance

POST   /api/v1/webhooks/subscribe       Subscribe to Critical/High exception events

**Contract rules:** every payload carries `tenant_id`, `external_ref`, `source_system`, `version_no`, and `last_approved_at`. Idempotency key required on all writes. Failed syncs queue for retry with exponential backoff and remain replayable from the sync log.

---

## 9\. Phased Delivery Roadmap

| Phase | Scope | Outcome |
| :---- | :---- | :---- |
| **Phase 0** | Foundation — scaffolding, tenancy, RBAC, audit trail, AEGIS layout shell, licensing integration | Deployable skeleton |
| **Phase 1** | Control Library, org structure, process catalogue, risk register, risk-control mapping | Controls can be defined and mapped |
| **Phase 2** | Test scripts, check items, test scheduling, test execution, review workflow, effectiveness ratings | Controls can be tested and rated |
| **Phase 3** | Exception tracker, verification-based closure, compensating controls, escalation engine, notifications | Exceptions are tracked and chased to closure |
| **Phase 4** | Spot checks, configurable report templates, dashboard, exports, board pack | Reporting layer complete — **MVP ships here** |
| **Phase 5** | Evidence retention automation, NDPA tooling, legal hold, disposal workflow, data subject requests | Data protection hardened |
| **Phase 6** | ThirdLine and NexusRisk API integration, webhooks, sync logs, OpenAPI spec | Ecosystem integration |
| **Phase 7** | Continuous controls monitoring — automated test execution against core banking and application data | Differentiator release |

---

## 10\. Phased Claude Code Implementation Prompts

> Multi-agent pattern consistent with ThirdLine: `/fullstack-guardian` coordinates, `/laravel-specialist` owns backend and data layer, `/php-pro` handles service and domain logic, `/code-reviewer` gates each phase. React/Inertia work sits with `/fullstack-guardian`.

### Phase 0 — Foundation

/fullstack-guardian

Scaffold the Atheris Control Solution (working name: SecondLine) as a new Laravel 11 \+

Inertia.js \+ React application, mirroring the architectural conventions already used in

ThirdLine.

Deliver:

1\. Laravel 11 project with Inertia \+ React (TypeScript), Vite, and Tailwind configured.

2\. Multi-tenancy: tenant scoping middleware, a global tenant scope on all models, and

   integration hooks against the existing LicensingServer for licence activation and

   feature-flag checks. Match the licensing client implementation used in ThirdLine.

3\. Authentication with MFA support, session timeout, password policy, and account lockout.

4\. RBAC with these seeded roles: Control Officer, Control Function Head, Control Owner,

   Line Manager, Executive Viewer, System Administrator. Implement policy classes, not

   inline permission checks.

5\. A reusable, immutable AuditTrail service capturing entity\_type, entity\_id, action,

   before\_json, after\_json, user, IP, and user agent. Wire it to a model observer trait so

   any model can opt in with a single trait.

6\. AEGIS design system implementation: Navy \#0B1F3A primary, Gold \#C9A227 accent. Build the

   app shell — sidebar navigation, top bar, breadcrumb, page header, data table component,

   modal, toast, and form field primitives. Reuse ThirdLine component conventions so the two

   products feel like one suite.

7\. Soft deletes on all transactional models. No hard deletes anywhere.

Do not build any domain features in this phase. Produce a README documenting the tenancy

model, the RBAC matrix, and the audit trail trait usage.

### Phase 1 — Control Library and Risk Mapping

/laravel-specialist

Implement the Control Library and Risk-Control mapping for the Control Solution, following

the data model in the BRD (Section 7.2).

Migrations and models: organisation\_units, business\_processes, risks, controls,

control\_versions, control\_categories, risk\_control\_map.

Requirements:

1\. Control CRUD with the full attribute set: control\_ref (auto-generated, tenant-unique),

   title, description, objective, type, nature, category, process, unit, owner, frequency,

   is\_key\_control, framework\_refs.

2\. Maker-checker approval: a control is only Active after approval by a Control Function

   Head. The creator cannot approve their own control — enforce in the policy layer.

3\. Versioning: every amendment writes a control\_versions row with effective\_from /

   effective\_to and a mandatory change\_reason. Never overwrite history.

4\. Status lifecycle: Draft → Pending Approval → Active → Under Review → Retired, with guarded

   transitions in a state machine class.

5\. Many-to-many risk-control mapping with a contribution weight.

6\. Gap detection queries: risks with no active mapped control, and controls with no mapped risk.

7\. Excel/CSV bulk import of controls with row-level validation, a dry-run preview, and an

   error report the user can download.

8\. Seed a starter library of standard banking controls covering segregation of duties, user

   access management, authorisation limits, reconciliations, dual control, cash handling,

   cut-off procedures, and change management. Mark them as adoptable templates a tenant can

   copy into their own library.

/fullstack-guardian — build the React screens: control register with filter and search,

control detail with version history timeline, control form with approval workflow, risk

register, risk-control mapping matrix, and a gap analysis view.

/code-reviewer — verify tenant scoping on every query, policy coverage on every route, audit

trail coverage on every mutation, and that no approval path allows self-approval.

### Phase 2 — Control Testing and Effectiveness Rating

/laravel-specialist

Implement control testing and effectiveness rating.

Migrations and models: test\_scripts, check\_items, test\_instances, check\_results,

effectiveness\_ratings.

Requirements:

1\. Test script builder — an ordered set of check items per control, each with question,

   guidance, expected result, mandatory flag, and default severity on failure. Scripts are

   versioned and require approval before use.

2\. Scheduler — a command that generates test\_instances from each active control's frequency,

   with period label, period dates, due date, and assigned tester. Run it daily; make it

   idempotent so re-running never duplicates instances.

3\. Test execution UI records Pass / Fail / N/A per check item. Comment is mandatory on Fail

   and N/A. Evidence attachable at check-item and test level.

4\. Sampling: capture population size, sample size, sampling method, and the item references

   selected.

5\. On submission, every Failed check item automatically creates an Exception record (stub the

   exception model now; Phase 3 completes it). No manual re-keying is permitted anywhere.

6\. Review workflow: tester submits, reviewer (Control Function Head) approves or returns with

   notes. Enforce tester ≠ reviewer in the policy layer.

7\. Effectiveness rating captured per test instance: design\_effectiveness and

   operating\_effectiveness, each on Effective / Partially Effective / Ineffective / Not

   Tested. Rationale is mandatory for any value other than Effective.

8\. Derive overall\_rating from a configurable matrix. Implement the default matrix in BRD

   Section 7.3 as seeded configuration, not hard-coded logic.

9\. Lock reviewed test instances. Reopening requires a documented reason and writes to the

   audit trail.

10\. Recompute residual risk for all mapped risks when a rating is approved.

/fullstack-guardian — build: testing calendar, my-tests queue, test execution screen

optimised so a routine test completes in under five minutes, review queue, rating screen, and

a control effectiveness trend chart per control.

/code-reviewer — confirm the scheduler is idempotent, tester/reviewer segregation cannot be

bypassed, and the rating matrix is data-driven.

### Phase 3 — Exception Management, Compensating Controls, Escalation

/php-pro

Implement exception management, compensating controls, and the escalation engine.

Migrations and models: exceptions, exception\_activities, compensating\_controls,

escalation\_matrices, escalation\_events.

Requirements:

1\. Exception tracker aggregating exceptions from test failures, spot check findings (stub

   for now), and manual entry. Full attribute set per BRD Section 7.2.

2\. Lifecycle: Open → Assigned → In Progress → Remediated → Verified/Closed, with Risk

   Accepted as an alternative terminal state. Implement as a guarded state machine.

3\. CLOSURE RULE — this is the critical control of the whole system: only a user holding the

   Control Function role may transition an exception to Verified/Closed, and only after

   recording verification\_method, verification evidence, and verification\_date. A control

   owner may reach Remediated and no further. A user may never close an exception arising

   from a test they personally performed. Enforce in a dedicated policy class with explicit

   tests for each bypass attempt.

4\. Ageing: compute age\_days from date\_raised and is\_overdue from target\_closure\_date, as

   derived attributes refreshed by a daily command.

5\. Extension requests: owner requests, control function approves or declines, reason

   mandatory, extension\_count incremented, original target date retained in history.

6\. Risk acceptance: requires senior approval, an expiry date, and re-confirmation on expiry.

7\. Recurrence detection: flag an exception as recurring when the same control produced an

   exception in a prior period, and link to the earlier record.

8\. Compensating controls: register against a failed control, capture temporary vs permanent,

   effective dates, and the residual exposure not covered. Require control function approval

   before they affect residual risk. Optionally register as a testable control so they enter

   the testing calendar. Auto-expire at end date and re-escalate the parent exception if the

   primary control is still unremediated.

9\. Escalation engine: a configurable matrix per tenant keyed on severity, with tiers Control

   Owner → Line Manager → Control Function Head → Executive → Board Committee. Triggers:

   unassigned after N days, past due date, no activity for N days, overdue test instance.

   Deliver by in-app notification and queued email. Log every escalation event with delivery

   status. Suspend on Remediated; resume if verification fails.

10\. Weekly digest to each control owner listing their open and overdue items.

/fullstack-guardian — build: exception register with saved filters, exception detail with

activity timeline and threaded comments, my-actions queue for control owners, verification

screen for the control function, compensating control registration, and the escalation matrix

configuration screen.

/code-reviewer — write explicit failing-path tests proving a control owner cannot close their

own exception through the UI, the API, or a direct model call.

### Phase 4 — Spot Checks, Reporting and Dashboard (MVP)

/fullstack-guardian

Implement spot checks, configurable reporting, and the dashboard. This phase completes the MVP.

Migrations and models: spot\_checks, findings, report\_templates.

Requirements:

1\. Ad-hoc spot check initiation against one or more controls, a process, a unit, or a branch,

   outside the testing calendar. Support surprise checks where the target unit receives no

   advance notification.

2\. Inline finding capture during the spot check: observation, severity, risk implication,

   recommendation, evidence.

3\. Management response and agreed action captured per finding before report issuance.

4\. Findings flow into the Phase 3 exception tracker as first-class exceptions.

5\. CONFIGURABLE REPORT TEMPLATES: administrators define report layout as an ordered set of

   sections, with header, footer, logo, and per-tenant styling. Store as sections\_json.

   Do not hard-code a report format. Ship three defaults: Spot Check Report, Exception

   Register, Control Testing Summary.

6\. Render reports to PDF and Word. Schedule recurring email delivery of standard reports.

7\. DASHBOARD — keep it simple, no widget builder:

   \- Open vs closed exceptions

   \- Exceptions by severity: Critical / High / Medium / Low

   \- Overdue findings (headline metric, most prominent tile)

   \- Unresolved Critical and High items

   \- Control testing completion rate for the current period

   \- Control effectiveness distribution

   \- Ageing buckets: 0-30, 31-60, 61-90, 90+ days

   Every tile drills through to a filtered record list.

8\. Global filters: period, unit, process, owner, severity, status, risk.

9\. Board pack generator combining selected reports into one document.

10\. Excel and PDF export on every list view.

/code-reviewer — confirm dashboard queries are indexed and aggregate at the database rather

than in PHP; verify the dashboard renders within 3 seconds against a seeded dataset of 5,000

controls and 50,000 exceptions.

### Phase 5 — Evidence Retention and NDPA Compliance

/laravel-specialist

Implement evidence management, retention, and Nigeria Data Protection Act safeguards.

Migrations and models: evidence, retention\_policies, evidence\_access\_log.

Requirements:

1\. Central evidence repository linked polymorphically to test instances, check results,

   exceptions, and findings. Store checksum, mime type, size, and classification.

2\. MANDATORY AT UPLOAD: the uploader must declare whether the evidence contains customer

   personal data, and if so, select the data categories. Block the upload until answered.

3\. Data minimisation prompt at upload advising redaction of account numbers, BVN, and

   identity data unless essential to the test conclusion.

4\. Encryption at rest (AES-256) with per-tenant key separation; TLS in transit; signed

   time-limited URLs for download. Evidence must never be served from a public path.

5\. Retention policies per evidence class with configurable retention\_months, disposal action

   (Delete or Anonymise), and a legal basis note. Compute retention\_expiry\_date on upload.

6\. Legal hold flag that suspends all disposal for evidence linked to an open investigation,

   litigation, or regulatory examination. Legal hold overrides retention expiry absolutely.

7\. Automated disposal job that identifies expired evidence, queues it for disposal, requires

   dual approval before permanent deletion, and writes an immutable disposal log recording

   what was disposed, when, under which policy, and who approved.

8\. Log every evidence view, download, and redaction with user, IP, and timestamp.

9\. In-place redaction that produces a redacted copy while retaining the original under

   stricter access control.

10\. Record of processing activities report: what personal data categories are held, in which

    evidence classes, under which retention policy, accessible by whom.

11\. Data subject request tooling: search evidence metadata for a named individual and produce

    a report of where their personal data is held.

Make all retention periods and data categories tenant-configurable. Do not hard-code any

statutory period — the client's DPO sets these values at onboarding.

/code-reviewer — verify no code path deletes evidence under legal hold, disposal cannot occur

on single approval, and evidence is never accessible without an authorisation check.

### Phase 6 — ThirdLine and NexusRisk Integration

/php-pro

Implement the integration layer between the Control Solution, ThirdLine Internal Audit, and

NexusRisk IRM.

Migrations and models: integration\_configs, integration\_sync\_logs.

Requirements:

1\. Per-tenant integration configuration supporting three modes: Control Solution as master,

   ThirdLine as master, or bidirectional. Where bidirectional, apply a configurable conflict

   rule (default: latest last\_approved\_at wins) and allow a designated master per control

   category.

2\. Stable external\_ref on every control so records match across both systems permanently. A

   control must never be re-keyed manually in either product.

3\. Outbound publication on approval events: control definitions (create, amend, retire),

   completed test results with effectiveness ratings, open exceptions with severity and age,

   and approved compensating controls.

4\. Inbound consumption: control definitions originated in ThirdLine's existing control

   feature, audit findings that identify a control weakness, and the NexusRisk risk register

   with inherent ratings.

5\. Outbound to NexusRisk: control effectiveness ratings feeding residual risk calculation.

6\. Authentication by OAuth 2.0 client credentials or signed API keys, scoped per tenant, with

   credentials encrypted at rest.

7\. Every payload carries tenant\_id, external\_ref, source\_system, version\_no, and

   last\_approved\_at. Idempotency key required on all writes.

8\. Queue all sync operations. Retry failures with exponential backoff. Log every attempt with

   direction, payload reference, status, and error detail. Provide an admin screen to inspect

   and replay failed syncs.

9\. Webhook subscriptions so ThirdLine receives near-real-time notification of new Critical and

   High severity exceptions.

10\. Publish an OpenAPI 3.1 specification and an integration guide covering the three

    configuration modes.

Build against ThirdLine's existing control feature schema — read it from the ThirdLine

codebase rather than assuming a shape. Where ThirdLine needs a reciprocal endpoint, produce

the change specification for the ThirdLine repository as a separate deliverable.

/code-reviewer — verify idempotency holds under duplicate delivery, tenant isolation cannot be

crossed by a malformed external\_ref, and no credential is ever logged.

---

## 11\. Assumptions

1. Deployment follows the existing model — master branch holds the core product, each client gets a branch, general updates merge downstream.  
2. Where a client already runs NexusRisk IRM, the risk register is consumed rather than duplicated.  
3. ThirdLine's existing control feature schema is available to read and, where necessary, extend.  
4. Clients supply their own organisational hierarchy, process catalogue, and control inventory during onboarding.  
5. Retention periods and lawful basis for personal data processing are set by each client's Data Protection Officer, not by Atheris.

## 12\. Open Items for Stakeholder Confirmation

| \# | Item | Owner |
| :---- | :---- | :---- |
| 1 | Default retention periods per evidence class, confirmed with a Nigerian data protection practitioner | Legal / DPO |
| 2 | Whether Version 1 maintains a local risk register or requires NexusRisk as a dependency | Product |
| 3 | Escalation tier defaults — how many days at each severity before escalation | Pilot client |
| 4 | Whether board-level escalation is in scope for Version 1 or deferred | Product |
| 5 | Whether the overall effectiveness matrix defaults are acceptable to pilot clients' control functions | Pilot client |
| 6 | Pricing and licensing model — standalone, bundled with ThirdLine, or module upgrade | Commercial |

## 13\. Glossary

| Term | Definition |
| :---- | :---- |
| **Compensating Control** | An alternative control applied when a primary control is absent or ineffective |
| **Design Effectiveness** | Whether a control, as designed, is capable of mitigating the risk it addresses |
| **Operating Effectiveness** | Whether a control operated as designed throughout the period under review |
| **Exception** | A deviation from expected control performance, requiring remediation and verified closure |
| **Inherent Risk** | Risk exposure before the effect of controls |
| **Residual Risk** | Risk exposure remaining after controls have been applied |
| **RCSA** | Risk and Control Self-Assessment |
| **Spot Check** | An ad-hoc control review conducted outside the scheduled testing calendar |
| **Key Control** | A control on which the institution places primary reliance for a material risk |

---

*End of document — Version 1.0*  
