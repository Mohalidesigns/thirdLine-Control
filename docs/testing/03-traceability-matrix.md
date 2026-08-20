# 03 — Traceability Matrix

**Source of requirements:** [`Control-Solution-BRD-v1.0.md`](../../Control-Solution-BRD-v1.0.md)
**Run date:** 2026-08-14 · **Branch / commit:** `phase-17-extended-grc` @ `8d233c7`

Every functional requirement in BRD v1.0 is listed. Nothing is omitted, and nothing untested is shown as passing.

## Position against exit criterion 3

> *"Every BRD requirement is traced to at least one executed test case."*

**Not met — but now at the halfway point.** **53 of 106** functional requirements (50.0%) are traced to an executed test case. The remaining 51 are mapped to their intended test cases but **were not executed** — see [`04-coverage-gaps.md`](04-coverage-gaps.md).

| Outcome | Count |
|---|---|
| Pass | 35 |
| Partial (passes with a logged defect against part of the requirement) | 12 |
| Fail | 6 |
| Not implemented (unconfirmed) — FR-9.10, FR-9.11 | 2 |
| **Not executed** | **51** |
| **Total** | **106** |

## Requirements with an executed result

| Ref | Requirement | Result | Detail |
|---|---|---|---|
| **FR-5.3** | Exception lifecycle Open → Assigned → In Progress → Remediated → Verified/Closed → Accepted Risk | **Pass** | Guarded transition table; illegal transitions refused; terminal states hold |
| **FR-5.4** | **Only the control function may close an exception, after verifying remediation** | **Pass** | The engagement's central requirement. Control Owner, Control Officer, System Administrator, Line Manager and Executive Viewer all receive 403 on a direct `POST /exceptions/{id}/close`, with no state change. Closure from any pre-`Remediated` status refused |
| **FR-5.5** | Closure records verification method, evidence and date | **Pass** | `verified_by`, `verified_at`, `verification_method` all persisted; `verification_method` mandatory server-side |
| **FR-5.10** | Full commentary thread, timestamped and attributed | **Pass** | Verification recorded with from/to values in `exception_activities` |
| **FR-12.3** | Enforce SoD in code — tester ≠ verifier; owner cannot close own exception | **Partial** | Both rules hold end-to-end over HTTP. `ExceptionService::verifyAndClose()` omits the exception-owner re-check present in the policy — **DEF-003** (not reachable through any current code path) |
| **FR-12.4** | Immutable audit trail of every state change — user, timestamp, before/after, IP | **Partial** | Actor, IP, user-agent and before/after all captured; model-layer immutability holds; no mutating route exists. But the table is rewritable via the query builder (**DEF-004**) and denied actions are unlogged (**DEF-005**) |
| **FR-8.1** | Configure escalation matrices per tenant, keyed on severity | **Pass** | 30 rules across 4 severities and 8 triggers; deactivating a rule stops it firing; changing a threshold changes behaviour with no code change (TC-12-09) |
| **FR-8.2** | Trigger conditions incl. exception past due date, unassigned after N days, test overdue | **Partial** | Triggers fire, and `exception_unassigned` honours its threshold. `exception_overdue` ignores it — **DEF-012** |
| **FR-8.3** | Tiered escalation path Owner → Line Manager → CFH → Executive → Board | **Fail** | All four tiers fire simultaneously on day one — **DEF-012** |
| **FR-8.4** | Configure escalation intervals per severity — Critical escalates faster and further than Low | **Fail** | Critical and Low escalate on the same day — **DEF-012** |
| **FR-8.8** | Suspend escalation when an exception moves to Remediated | **Pass** | Verified: a `Remediated` exception does not escalate |
| **FR-8.5** | Deliver escalations by in-app notification and email | **Fail** | In-app delivers. Email fails permanently for every escalation that is not exception- or test-scoped — **DEF-001** |
| **FR-8.7** | Log every escalation — trigger, recipient, timestamp, **delivery status** | **Fail** | `delivery_status` records `Sent` for escalations whose delivery failed — **DEF-002** |
| **FR-12.2** | Role-based access control with the §4 roles, plus custom role definition | **Pass** | All six roles enforced; a route-table sweep admitted no unauthorised caller; privilege escalation blocked; System Administrator role immutable; seeded roles undeletable; last active admin undemotable; role changes apply and revoke immediately. Custom role *creation* exists (`admin.roles.store`) but was not exercised |
| **FR-9.1** | Evidence stored in a controlled repository, linked to its test, check item, exception or finding | **Pass** | Stored on a private disk with randomised paths, checksummed, linked via a 4-type allowlist |
| **FR-9.2** | Classify every item at upload: contains customer personal data yes/no, plus category | **Pass** | Declaration mandatory server-side; categories mandatory when the answer is yes |
| **FR-9.3** | Configurable retention per evidence class, with tenant override | **Pass** | PII evidence takes the 60-month Customer Personal Data class; everything else the 72-month default; expiry derived on upload |
| **FR-9.6** | Restrict evidence access to role-based need; log every view and download | **Partial** | Download correctly restricted to admin/CFH/officer or the uploader, tenant-isolated, and access-logged. But **upload** is unrestricted — DEF-009 |
| **FR-9.7** | Legal hold suspends deletion | **Pass** | Held evidence past expiry is excluded from the disposal sweep; hold settable only by the control function |
| **FR-9.8** | Automate disposal at end of retention, with a disposal log and dual approval | **Pass** | Sweep queues expired items; two *different* approvers required; file deleted; `disposed` audit entry and the evidence record both survive; download returns 410 |
| **FR-12.8** | Session timeout, password policy and account lockout aligned to CBN IT standards | **Partial** | Login correctly refuses a deactivated account and does not permit account enumeration. But deactivation does not reach a live session — **DEF-007**. Timeout, lockout/throttle and password policy not yet executed |

## Full matrix

| Ref | Pri | Requirement | Module | Test case(s) | Status | Notes |
|---|---|---|---|---|---|---|
| FR-1.1 | Must | Maintain a central library of controls, each with a unique control ID, title, description, a… | Control Library | TC-04 | **Not executed** |  |
| FR-1.2 | Must | Classify controls by type (Preventive / Detective / Corrective), nature (Manual / Automated … | Control Library | TC-04 | **Not executed** |  |
| FR-1.3 | Must | Ship a pre-seeded starter library of standard banking controls that a tenant can adopt, edit… | Control Library | TC-04 | **Not executed** |  |
| FR-1.4 | Must | Allow institutions to define their own controls and their own control categories | Control Library | TC-04 | **Not executed** |  |
| FR-1.5 | Must | Assign each control to a control owner, a business process, and an organisational unit | Control Library | TC-04 | **Not executed** |  |
| FR-1.6 | Must | Define testing frequency per control (Daily, Weekly, Monthly, Quarterly, Semi-annual, Annual… | Control Library | TC-04 | **Not executed** |  |
| FR-1.7 | Must | Version control definitions — every amendment retains the prior version with effective dates | Control Library | TC-04 | **Not executed** |  |
| FR-1.8 | Must | Route new and amended controls through a maker–checker approval before they become active | Control Library | TC-04 | **Not executed** |  |
| FR-1.9 | Must | Support control status lifecycle: Draft → Pending Approval → Active → Under Review → Retired | Control Library | TC-04 | **Not executed** |  |
| FR-1.10 | Should | Bulk import controls from Excel/CSV during onboarding | Control Library | TC-04 | **Not executed** |  |
| FR-1.11 | Should | Map controls to regulatory or framework references (CBN circulars, ISO 27001, COBIT, PCI DSS… | Control Library | TC-04 | **Not executed** |  |
| FR-2.1 | Must | Maintain or consume a risk register sourced from risk and control self-assessment (RCSA) | Risk & RCSA | TC-05 | **Not executed** |  |
| FR-2.2 | Must | Map controls to risks in a many-to-many relationship — one control may mitigate several risk… | Risk & RCSA | TC-05 | **Not executed** |  |
| FR-2.3 | Must | Record inherent risk rating (likelihood × impact) per risk | Risk & RCSA | TC-05 | **Not executed** |  |
| FR-2.4 | Must | Compute residual risk from inherent rating adjusted by the aggregate effectiveness of mapped… | Risk & RCSA | TC-08, FR-2.4 reconciliation | Pass | Formula independently recomputed — exact match on every risk |
| FR-2.5 | Must | Flag risks with **no mapped control** (control gaps) and controls with **no mapped risk** (o… | Risk & RCSA | TC-05 | **Not executed** |  |
| FR-2.6 | Should | Where NexusRisk IRM is deployed, consume the risk register by API rather than maintaining a … | Risk & RCSA | TC-05 | **Not executed** |  |
| FR-2.7 | Should | Display a risk-control heat map showing exposure before and after controls | Risk & RCSA | TC-05 | **Not executed** |  |
| FR-3.1 | Must | Build a reusable test checklist ("test script") per control, composed of ordered check items | Control Testing | TC-07 | **Not executed** |  |
| FR-3.2 | Must | Each check item records a result: **Pass / Fail / Not Applicable**, with a mandatory comment… | Control Testing | TC-07-04 | Pass | Comment mandatory on Fail and N/A |
| FR-3.3 | Must | Attach evidence at check-item level and at test level (documents, screenshots, extracts) | Control Testing | TC-07 | **Not executed** |  |
| FR-3.4 | Must | Auto-generate a test instance on the control's defined frequency, with a due date and an ass… | Control Testing | TC-07-01 | Partial | Due date correct; assigned tester always null — DEF-013 |
| FR-3.5 | Must | Record sampling basis where applicable: population size, sample size, sampling method, items… | Control Testing | TC-07 | **Not executed** |  |
| FR-3.6 | Must | **Automatically raise an exception from every Failed check item** — no manual re-keying | Control Testing | TC-07-04 | Pass | One exception per failed item, linked to source and control |
| FR-3.7 | Must | Test instance lifecycle: Scheduled → In Progress → Submitted for Review → Reviewed → Closed | Control Testing | TC-07-10 | Pass | Scheduled → In Progress → Submitted → Reviewed enforced |
| FR-3.8 | Must | Reviewer (Control Function Head) can return a submitted test to the tester with review notes | Control Testing | TC-07-10 | Pass | Reject returns to tester with notes |
| FR-3.9 | Must | Lock a test instance after review sign-off; further changes require a formal reopen with rea… | Control Testing | TC-07-09 | Pass | Locked after sign-off; reopen needs a reason and is audited |
| FR-3.10 | Must | Track testing completion rate and overdue tests per unit, per owner, per period | Control Testing | TC-07 | **Not executed** |  |
| FR-4.1 | Must | Initiate an ad-hoc spot check outside the testing calendar, against one or more controls, a … | Spot Checks | TC-09 | **Not executed** |  |
| FR-4.2 | Must | Capture findings inline during the spot check, with severity, evidence, and observation text | Spot Checks | TC-09 | **Not executed** |  |
| FR-4.3 | Must | Findings raised during a spot check flow into the same exception tracker as scheduled test f… | Spot Checks | TC-09 | **Not executed** |  |
| FR-4.4 | Must | Generate a spot check report on completion, containing scope, period, locations covered, fin… | Spot Checks | TC-14-05 | Pass | Spot check report renders with its findings |
| FR-4.5 | Must | **Configurable report templates** — administrators define the report layout, sections, heade… | Spot Checks | TC-09 | **Not executed** |  |
| FR-4.6 | Must | Export reports to PDF and Word | Spot Checks | TC-14-06 | Partial | PDF verified; Word export not exercised |
| FR-4.7 | Should | Support surprise/unannounced spot checks where the target unit is not notified in advance | Spot Checks | TC-09 | **Not executed** |  |
| FR-4.8 | Should | Record management response and agreed action per finding before report issuance | Spot Checks | TC-09 | **Not executed** |  |
| FR-5.1 | Must | Maintain a central exception tracker aggregating exceptions from control tests, spot checks,… | Exceptions | TC-10 | **Not executed** |  |
| FR-5.2 | Must | Each exception carries: reference, source, linked control and risk, description, root cause,… | Exceptions | TC-10 | **Not executed** |  |
| FR-5.3 | Must | Exception lifecycle: Open → Assigned → In Progress → Remediated (pending verification) → **V… | Exceptions | TC-10-04,-07,-08,-10 | Pass | Lifecycle guarded; terminal states hold |
| FR-5.4 | Must | **Only the control function may move an exception to Closed, and only after verifying remedi… | Exceptions | TC-10-04,-05,-06,-08,-09 | Pass | Only CFH closes; every other role 403 on direct POST |
| FR-5.5 | Must | Closure requires the verifier to record verification method, verification evidence, and veri… | Exceptions | TC-10-08 | Pass | Verifier, date and method all recorded; method mandatory |
| FR-5.6 | Must | Age each exception from the date raised; auto-flag Overdue when past target closure date | Exceptions | TC-10 | **Not executed** |  |
| FR-5.7 | Must | Support due-date extension requests from the owner, requiring control function approval and … | Exceptions | TC-10 | **Not executed** |  |
| FR-5.8 | Must | Support formal risk acceptance for exceptions that will not be remediated, requiring senior … | Exceptions | TC-10 | **Not executed** |  |
| FR-5.9 | Should | Detect and link recurring exceptions — the same control failing in consecutive periods — and… | Exceptions | TC-10 | **Not executed** |  |
| FR-5.10 | Must | Full commentary thread per exception with timestamped, attributed entries | Exceptions | TC-10-16 | Pass | Verification recorded in the activity thread with from/to values |
| FR-6.1 | Must | When a control is rated ineffective or fails testing, allow registration of one or more comp… | Compensating | TC-11 | **Not executed** |  |
| FR-6.2 | Must | Capture for each compensating control: description, owner, whether it is temporary or perman… | Compensating | TC-11 | **Not executed** |  |
| FR-6.3 | Must | Require control function approval before a compensating control is recognised in the residua… | Compensating | TC-11 | **Not executed** |  |
| FR-6.4 | Must | Test compensating controls in their own right — they enter the testing calendar like any oth… | Compensating | TC-11 | **Not executed** |  |
| FR-6.5 | Must | Expire temporary compensating controls automatically at the end date and re-escalate the und… | Compensating | TC-11 | **Not executed** |  |
| FR-6.6 | Should | Report on all active compensating controls, their age, and the primary control weaknesses th… | Compensating | TC-11 | **Not executed** |  |
| FR-7.1 | Must | Rate every tested control on **Design Effectiveness** — is the control, as designed, capable… | Effectiveness | TC-08-01 | Pass | Design recorded as its own attribute with rationale |
| FR-7.2 | Must | Rate every tested control on **Operating Effectiveness** — did the control operate as design… | Effectiveness | TC-08-02 | Pass | Operating recorded separately |
| FR-7.3 | Must | Use a consistent scale for both: Effective / Partially Effective / Ineffective / Not Tested | Effectiveness | TC-08-03 | Pass | Same four-point scale on both dimensions |
| FR-7.4 | Must | Derive an overall control rating from the two dimensions using a configurable matrix (defaul… | Effectiveness | TC-08-03 | Pass | All 16 cells correct; FR-7.4 rule holds as a property |
| FR-7.5 | Must | Require a documented rationale for any rating other than Effective | Effectiveness | TC-08-04 | Pass | Rationale mandatory for any non-Effective rating |
| FR-7.6 | Must | Route effectiveness ratings through control function approval before they are published | Effectiveness | TC-08 | Partial | Approval gate + self-approval refusal hold; stale approver on re-rating — DEF-014 |
| FR-7.7 | Must | Retain rating history per control per period, and trend it over time | Effectiveness | TC-08-05 | Pass | One rating per control per period |
| FR-7.8 | Must | Feed the overall rating into the residual risk calculation in Module 2 | Effectiveness | TC-08-06 | Pass | Approval recomputes residual risk |
| FR-8.1 | Must | Configure escalation matrices per tenant, keyed on severity | Escalation | TC-12-09 | Pass | 30 rules across 4 severities; deactivation honoured |
| FR-8.2 | Must | Trigger conditions: check item failed; exception unassigned after N days; exception past due… | Escalation | TC-12-02,-03,-09 | Partial | exception_overdue ignores its threshold — DEF-012 |
| FR-8.3 | Must | Tiered escalation path: Control Owner → Line Manager / Unit Head → Control Function Head → E… | Escalation | TC-12-02,-03 | Fail | All tiers fire at once — DEF-012 |
| FR-8.4 | Must | Configure escalation intervals per severity — Critical escalates faster and further than Low | Escalation | TC-12-02, FR-8.4 test | Fail | Critical and Low escalate identically — DEF-012 |
| FR-8.5 | Must | Deliver escalations by in-app notification and email; SMS optional | Escalation | TC-12-01 (partial) | Fail | In-app delivers; email fails permanently — DEF-001 |
| FR-8.6 | Must | Send a periodic digest to each control owner listing their open and overdue items | Escalation | TC-12 | **Not executed** |  |
| FR-8.7 | Must | Log every escalation — trigger, recipient, timestamp, delivery status — in the audit trail | Escalation | TC-12-01 (partial) | Fail | delivery_status records Sent for failed delivery — DEF-002 |
| FR-8.8 | Must | Suspend escalation automatically when an exception moves to Remediated, and resume it if ver… | Escalation | TC-12-04 | Pass | Remediated exceptions are suspended |
| FR-9.1 | Must | Store all evidence in a controlled repository, linked to its test, check item, exception, or… | Evidence/NDPA | TC-13-01,-05 | Pass | Private disk, randomised paths, checksummed, 4-type link allowlist |
| FR-9.2 | Must | Classify every evidence item at upload: **Contains Customer Personal Data — Yes/No**, and da… | Evidence/NDPA | TC-13-09 | Pass | Declaration and categories both mandatory server-side |
| FR-9.3 | Must | Enforce configurable retention periods per evidence class; default retention aligned to stat… | Evidence/NDPA | TC-13-07 | Pass | PII 60mo vs default 72mo; expiry derived on upload |
| FR-9.4 | Must | Apply data minimisation guidance at upload — prompt testers to redact account numbers, BVN, … | Evidence/NDPA | TC-13 | **Not executed** |  |
| FR-9.5 | Must | Encrypt evidence at rest and in transit | Evidence/NDPA | TC-13 | **Not executed** |  |
| FR-9.6 | Must | Restrict evidence access to users with a role-based need; log every view and download | Evidence/NDPA | TC-13-05 | Partial | Download gated + logged; upload unrestricted — DEF-009 |
| FR-9.7 | Must | Provide legal hold — suspend deletion for evidence linked to an open investigation, litigati… | Evidence/NDPA | TC-13-08 | Pass | Held evidence excluded from the sweep; hold is control-function only |
| FR-9.8 | Must | Automate disposal at end of retention, with a documented disposal log and a dual-approval st… | Evidence/NDPA | TC-13-08 | Pass | Two different approvers enforced; file deleted, audit + record survive |
| FR-9.9 | Must | Maintain a record of processing activities for evidence containing personal data, sufficient… | Evidence/NDPA | TC-13 | **Not executed** |  |
| FR-9.10 | Should | Support redaction of evidence in place, retaining the unredacted original under stricter acc… | Evidence/NDPA | TC-13 | **Not implemented (unconfirmed)** | Not found during execution — see B-03/B-06 |
| FR-9.11 | Should | Support data subject request handling — locate and report on personal data held in evidence … | Evidence/NDPA | TC-13 | **Not implemented (unconfirmed)** | Not found during execution — see B-03/B-06 |
| FR-10.1 | Must | Executive dashboard showing: open vs closed exceptions; breakdown by severity (Critical / Hi… | Dashboard/Reporting | TC-15-01,-02,-03 | Pass | Open/closed, all 4 severities, overdue, unresolved Critical+High — all reconcile |
| FR-10.2 | Must | Every dashboard tile drills through to the underlying record list | Dashboard/Reporting | TC-15-07 | Pass | Tile drill-through total matches the tile |
| FR-10.3 | Must | Control testing completion rate and overdue tests by period, unit, and owner | Dashboard/Reporting | TC-15, FR-10.3 | Partial | Completion rate exact; "by period" impossible without a period filter — DEF-015 |
| FR-10.4 | Must | Control effectiveness distribution — how many controls are Effective, Partially Effective, I… | Dashboard/Reporting | TC-15, FR-10.4 | Pass | Each control counted once on its latest published rating |
| FR-10.5 | Must | Ageing analysis of open exceptions (0–30, 31–60, 61–90, 90+ days) | Dashboard/Reporting | TC-15 | Pass | Ageing buckets sum exactly to the open total |
| FR-10.6 | Must | Filters: period, unit, process, control owner, severity, status, risk | Dashboard/Reporting | TC-14-03, TC-15-04 | Fail | Only unit/severity/status implemented; period, process, owner, risk absent — DEF-015 |
| FR-10.7 | Must | Export any report to PDF and Excel; scheduled email delivery of standard reports | Dashboard/Reporting | TC-14-01,-06,-10 | Partial | PDF and Excel exports reconcile exactly; scheduled email delivery not tested |
| FR-10.8 | Should | Board and committee pack generation from selected reports in a single document | Dashboard/Reporting | TC-14-01 | Pass | Board pack renders from selected sections |
| FR-10.9 | Must | Keep the dashboard deliberately simple — no configurable widget builder in Version 1 | Dashboard/Reporting | TC-14/TC-15 | **Not executed** |  |
| FR-11.1 | Must | Expose a REST API that publishes control definitions to ThirdLine Internal Audit when a cont… | Integration | TC-18-01,-02 | Pass | Published on approval with the full contract envelope |
| FR-11.2 | Must | Publish control test results, effectiveness ratings, and open exceptions to ThirdLine so int… | Integration | TC-18-01 | Pass | Test results, ratings and exceptions published; all mapped attributes present |
| FR-11.3 | Must | Support **bidirectional synchronisation**: where control officers define controls inside Thi… | Integration | TC-18-03 | Fail | Inbound accepted but every attribute except title is discarded — DEF-016 |
| FR-11.4 | Must | Configure integration direction at setup per tenant: Control Solution as master, ThirdLine a… | Integration | TC-18-03,-04 | Pass | Push / Pull / Bidirectional all honoured, in both directions |
| FR-11.5 | Must | Maintain a stable external control identifier so records match across both systems, and neve… | Integration | TC-18-01 | Pass | external_ref minted on first publish and stable thereafter |
| FR-11.6 | Must | Authenticate integration by OAuth 2.0 client credentials or signed API keys, scoped per tenant | Integration | TC-18-07 | Partial | Signed per-tenant API key verified in 3 negative cases; secrets not logged. OAuth2 supported in schema, not exercised |
| FR-11.7 | Must | Log every synchronisation event with payload reference, direction, status, and error detail;… | Integration | TC-18-08,-10 | Partial | Direction, payload, status and error all logged; replay works but is given a new idempotency key — DEF-017 |
| FR-11.8 | Should | Consume the risk register from NexusRisk IRM where deployed | Integration | TC-18 | **Not executed** |  |
| FR-11.9 | Should | Webhook subscriptions so ThirdLine is notified in near real time of new Critical/High except… | Integration | TC-18 | **Not executed** |  |
| FR-11.10 | Must | Publish an OpenAPI specification and integration guide | Integration | TC-18 | **Not executed** |  |
| FR-12.1 | Must | Multi-tenant architecture consistent with the existing per-client branch deployment model, i… | Platform/Security | TC-01/TC-02/TC-17 | **Not executed** |  |
| FR-12.2 | Must | Role-based access control with the roles in Section 4, plus custom role definition | Platform/Security | TC-02-02,-05,-06,-08 | Pass | Sweep + escalation + invariants all hold; custom role creation not exercised |
| FR-12.3 | Must | Enforce segregation of duties in code — tester ≠ verifier; owner cannot close own exception | Platform/Security | TC-10-05,-06,-09 | Partial | tester!=verifier and owner!=closer hold via HTTP; service layer gap DEF-003 |
| FR-12.4 | Must | Immutable audit trail of every create, update, delete, approval, closure, escalation, export… | Platform/Security | TC-17-01,-02,-04,TC-New-03 | Partial | Actor/IP/before/after captured; model immutable; DEF-004 query-builder bypass, DEF-005 denials unlogged |
| FR-12.5 | Must | Maintain organisational hierarchy, business process catalogue, and unit/branch register | Platform/Security | TC-01/TC-02/TC-17 | **Not executed** |  |
| FR-12.6 | Must | Configurable notification templates and escalation matrices per tenant | Platform/Security | TC-01/TC-02/TC-17 | **Not executed** |  |
| FR-12.7 | Should | Single sign-on via SAML 2.0 / OIDC, with MFA support | Platform/Security | TC-01/TC-02/TC-17 | **Not executed** |  |
| FR-12.8 | Must | Session timeout, password policy, and account lockout aligned to CBN IT standards | Platform/Security | TC-01-02,-03, TC-02-03 | Partial | Login denial and anti-enumeration pass; deactivation does not end a live session — DEF-007 |
## Requirements needing a Product Owner ruling before they can be traced

| Ref | Issue |
|---|---|
| **FR-10.9** | *"Keep the dashboard deliberately simple — no configurable widget builder in Version 1."* The build ships `DashboardBuilderController`, `DashboardBuilderService`, `Dashboard` and `DashboardWidget` models, a report designer, and `manage dashboards` / `publish dashboards` permissions held by every role. This directly contradicts a **Must** requirement. Marked **Superseded — pending PO confirmation** rather than tested as written or silently passed. BRD v1.0 has not been updated to record the decision. |
| **FR-11.6** | Satisfied in the alternative — the requirement offers OAuth 2.0 **or** signed API keys, and signed per-tenant API keys are implemented and in use. `integration_configs.auth_type` is `enum('api_key','oauth2')`, so OAuth2 is supported at schema level; whether it is implemented end-to-end is unconfirmed. |
| **FR-2.6, FR-11.8** | NexusRisk risk-register consumption not observed in discovery. *Should*-priority. Unconfirmed. |
| **FR-11.9** | Outbound webhook subscriptions for near-real-time Critical/High exception notification not observed. Outbound publication fires on closure, not on raise. *Should*-priority. Unconfirmed. |
| **FR-9.10** | In-place evidence redaction not observed. *Should*-priority. Unconfirmed. |
| **FR-9.11** | Data-subject request handling not observed. *Should*-priority, NDPA-relevant. Unconfirmed. |
| **FR-12.2** | Custom role definition — six roles are seeded and a role administration UI exists; whether a tenant can define *new* roles is unconfirmed. |

## Requirements implemented beyond BRD v1.0

Roughly 20 domains are implemented that appear nowhere in BRD v1.0 — regulatory obligations, content packs, CSA campaigns, documents, policies, incidents, complaints, whistleblowing, investigation cases, continuous monitoring, connectors, SoD analysis, submission packs, the AI layer, mobile/offline, omnichannel messaging, multi-entity and residency, strategy, vendor risk, sustainability, and combined assurance.

These carry no BRD requirement to trace to. Under scope decision **D-3** they receive smoke, authorisation and state-machine coverage rather than full-depth testing — **none of which has yet been executed**. The BRD should be revised to cover them, or a superseding requirements document issued, so that a future run has something to trace against.
