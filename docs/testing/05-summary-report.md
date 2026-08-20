# 05 — Summary Report

**Product:** SecondLine — Atheris Control Solution
**Branch / commit:** `phase-17-extended-grc` @ `8d233c7`
**Run date:** 2026-08-14
**Prepared by:** QA Automation / Test Analysis
**Environment:** local e2e — MariaDB 10.4.28, database `atheris_control_e2e`, isolated from the developer's schema, which was left untouched

---

## 1. Release recommendation

> ## REMEDIATED — 2026-08-20
>
> All 21 logged defects (6 High, 10 Medium, 5 Low) have since been fixed on branch
> `claude/atheris-exception-manager-hy7wvq`; every defect's regression guard in
> `tests/e2e` now passes, and the product's own regression suite remains green.
> Per-defect resolutions are recorded in [02-defect-log.md](02-defect-log.md).
> The remaining exit-criteria gap is coverage (criterion 1 and 3 — the untested
> half of the requirement set), not open defects.
>
> **Phase 2 browser run (2026-08-20, same day):** the browser-dependent gaps
> were then executed against the live application —
> [06-phase2-browser-log.md](06-phase2-browser-log.md). It surfaced four
> further defects (DEF-022 cross-tenant user pickers, DEF-023 CSP-blocked
> font import, DEF-024 blank notification rows, DEF-025 missing
> unsaved-changes warning), all fixed and re-verified in the same run.
> Defect count now stands at **25 logged, 25 fixed, 0 open**.
>
> The assessment below is preserved as written at the time of the run.

> ## DO NOT RELEASE *(as of the 2026-08-14 run — superseded above)*
>
> **Six open High-severity defects**, none with accepted risk sign-off. Four of the seven exit criteria are failed outright.
>
> This is not a judgement that the product is unsound. Much of what was tested holds up well, and some of it is genuinely strong. It is a judgement that six specific defects must be fixed and half the requirement set still has no executed evidence behind it.

**No Critical defects were found.** That is a real result rather than an absence of looking: it survived direct-endpoint authorisation sweeps across the whole routing table, tenant isolation probed in both directions on both the web and API surfaces, evidence and NDPA handling, mass assignment, stored XSS, SQL injection, the full API contract, the security baseline, and a 630-page sweep across every domain in the product.

### Exit criteria

| # | Criterion | Position |
|---|---|---|
| 1 | 100% of planned test cases executed | **FAILED** — 10 of 19 modules at depth, plus §9, §10, §12 and a Tier 2 pass over all ~30 domains. What remains is chiefly browser-dependent |
| 2 | Zero open Critical; zero open High without accepted risk sign-off | **FAILED** — 6 open High, no sign-off. Zero Critical |
| 3 | Every BRD requirement traced to an executed test case | **FAILED** — 53 of 106 (50.0%) |
| 4 | All authorisation negative tests pass, including direct endpoint calls | **PARTIAL** — every read-side negative passed; two write-side gaps (DEF-008, DEF-009) |
| 5 | Audit trail complete and immutable for every state-changing action | **FAILED** — DEF-004 (query-builder bypass), DEF-005 (denials unlogged) |
| 6 | Dashboard and report figures reconcile exactly to source data | **PARTIAL** — **every figure reconciled exactly**; fails only on FR-10.6's missing filters (DEF-015) |
| 7 | ThirdLine integration verified in both directions, including failure and retry | **PARTIAL** — outbound sound; inbound loses every attribute but the title (DEF-016) |

---

## 2. The numbers

| Metric | Value |
|---|---|
| Automated tests written and executed | **348** across 18 files |
| Assertions | **1,604** |
| Passed | 317 |
| Failed | 29 — every one a regression guard for a logged defect |
| Skipped | 2 (both intentional) |
| Pass rate on executed cases | **91%** |
| Part B modules executed to depth | 10 of 19 |
| BRD requirements traced to an executed case | **53 of 106 (50.0%)** — 35 Pass, 12 Partial, 6 Fail |
| Open defects | **21** — 0 Critical, **6 High**, 10 Medium, 5 Low |
| Accepted risks | **None recorded** |
| Product's own regression suite (baseline) | 784 tests, 2,863 assertions — **all passing** |

The 91% pass rate measures the slice that was executed, deliberately weighted toward the highest-risk areas. It is not a measure of overall product quality.

---

## 3. What holds

Nine findings in the product's favour, each verified rather than assumed.

**3.1 The exception closure control holds.** BRD REQ-001 / FR-5.4 — the requirement the product exists to enforce. Control Owner, Control Officer, System Administrator, Line Manager and Executive Viewer each attempted `POST /exceptions/{id}/close` **by direct endpoint call with a valid session**, and all five received `403` with the row re-read afterwards to confirm no state change. Closure before `Remediated` is refused. All three segregation-of-duties rules hold. `Verified-Closed` is terminal. Closure records verifier, timestamp and a mandatory method. There is **no System Administrator bypass** — a deliberate design decision, verified rather than taken on trust.

**3.2 Every figure reconciles, exactly.** Twelve dashboard tiles and three Excel exports were recomputed independently from SQL — the exports parsed back out of their streamed bytes and counted. **No variance anywhere.** The internal consistency checks hold too: severity buckets sum to the open total, ageing buckets sum to the open total, the Critical/High tile agrees with the donut beside it, and the drill-through list matches its tile.

**3.3 Residual risk reconciles to an independent recomputation.** The plan requires every calculation to be recomputed from the database and compared. The FR-2.4 formula — `max(inherent × (1 − weighted mean reduction), inherent × 0.2)` with the compensating-control bonus — was reimplemented in the test and **matches the stored value for every risk**.

**3.4 The effectiveness rating engine is correct.** All sixteen design × operating matrix cells resolve as configured, and FR-7.4's rule holds as a *property* of the whole matrix rather than by luck of the seed. Rationale is enforced on both dimensions; the maker–checker gate refuses self-approval.

**3.5 Form security is sound — and this was the likeliest place for a Critical.** No controller uses `$request->all()`; every write flows through `FormRequest::validated()`. The two most dangerous injections were both refused: planting a record in another tenant, and creating an exception already `Verified-Closed` with a forged verifier. Server-side validation holds independently of the client, injection strings are parameterised, stored values cannot break out of the Inertia page block, and CSRF returns 419 on the live application.

**3.6 Evidence retention, NDPA handling and disposal are the strongest module tested.** Mandatory PII declaration, PII-specific retention class, legal hold suspending disposal, genuine two-person approval that refuses the same approver twice, file deletion with both the audit entry and the record surviving, `410 Gone` on a disposed item, unguessable paths, access-logged downloads. Both defects here sit in one line of upload validation.

**3.7 Tenant isolation is closed on the read side, in both directions.** Six roles refused another tenant's exception; the foreign tenant refused ours; cross-tenant close and rename both failed; a tenant-scoped API key returned only its own records.

**3.8 A 630-page sweep found no server error and no cross-tenant leak.** All 105 authenticated pages, across ~30 domains, as each of six roles. Where routing and policy diverge, the policy is consistently the *stricter* — nine pages are protected solely by an in-controller `authorize()`.

**3.9 The security baseline is largely in place.** All five security headers, CSP with `frame-ancestors 'none'`, `httponly; samesite=lax` session cookie, bcrypt hashing, login throttling, session regeneration on login, open-redirect protection, no secrets in the client bundle, and **`composer audit` clean**.

---

## 4. The six High-severity defects

Fixing these is the shortest path to exit criterion 2.

| ID | Module | Defect |
|---|---|---|
| **DEF-012** | Escalation | `exception_overdue` never consults `days_threshold`, so **all four tiers fire on day one** and a Low exception escalates as fast as a Critical. Defeats FR-8.3 and FR-8.4, both *Must* |
| **DEF-016** | ThirdLine | Inbound sync **silently discards every control attribute except the title**. A detective/automated/quarterly key control arrives preventive/manual/monthly/non-key — logged as `Success` |
| **DEF-019** | API / Security | **No rate limiting on any `/api/v1` route.** A stolen key can be replayed without limit against endpoints returning 500 records a call; the key space is probeable at request rate |
| **DEF-007** | Auth / session | **Deactivating a user does not end their live session.** Measured scope: 11 pages across 9 domains, including the exception register |
| **DEF-001** | Escalation | Escalation **email** fails permanently for every subject that is not an exception or a test instance — risk-appetite and KRI breaches deliver in-app only |
| **DEF-002** | Escalation / Audit | `delivery_status` records **`Sent`** for escalations whose delivery failed |

### The escalation cluster

Three of the six are in one module and they compound. **DEF-012** fires every tier at once, so the ladder conveys no urgency; **DEF-001** means the email half never arrives for breach escalations; **DEF-002** records all of it as `Sent` regardless. An institution relying on this module would have a board tier flooded with day-one notifications, breach escalations reaching nobody by email, and a register asserting everything was delivered on schedule.

Escalation is the module a CBN examiner would test first, because it is what evidences that a control failure reached someone with authority to act.

### Two candidates for Critical — Product Owner's call

Flagged rather than re-rated unilaterally:

- **DEF-002**, if the escalation register is relied upon as examination evidence. It asserts delivery that did not occur, with no contradicting record anywhere in the application.
- **DEF-007**, because deactivation is the primary incident-response control. When an account is compromised or someone is dismissed for cause, disabling it is the action taken — and it currently does nothing to the session already in flight.

---

## 5. Findings that are not defects but need a decision

**5.1 The BRD is substantially out of date.** BRD v1.0 defines 12 modules and 106 requirements. The build spans 17 phases and roughly 35 domains — vendor risk, sustainability (IFRS S1/S2), complaints, investigation cases, an AI layer, continuous monitoring, multi-entity and residency. **There is no requirements document to test roughly 60% of the product against.** This is why traceability is capped at 50% no matter how much testing is done.

**5.2 FR-10.9 is contradicted by the build.** The requirement states *"no configurable widget builder in Version 1"*, as a **Must**. The product ships a dashboard builder and a report designer. Presumably a sanctioned later-phase decision — but unrecorded, and an unrecorded reversal of a Must requirement is exactly what an examiner queries.

**5.3 The test plan's own role matrix was wrong.** Part B §3 named Control Officer as the only role permitted to close exceptions; the implementation reserves it for Control Function Head, enforced in two independent layers. Caught in discovery — otherwise TC-10-05 through -09 would have logged a **false Critical against a control that works correctly**.

**5.4 Two BRD requirements appear unimplemented.** FR-9.10 (in-place evidence redaction) and FR-9.11 (data-subject request handling) were not found during execution. FR-9.11 is NDPA-relevant: the per-item PII classification means the *data* to answer a DSR exists, but nothing searches it.

**5.5 The production CSP includes `script-src 'unsafe-inline'`**, weakening its protection against injected inline script. It appears required by the asset pipeline. Worth confirming whether a nonce-based policy is feasible, since CSP is one of the few controls that would blunt a stored-XSS bug elsewhere.

**5.6 Two demo risks show a risk reduction no control produced.** `Phase16DemoSeeder:224` and `Phase17DemoSeeder:524` write `residual_rating` literally onto risks with zero mapped controls — a state the engine cannot produce. Seed-data quality, not a product fault, but a client demo would display it.

---

## 6. What was not tested

Set out in full in [`04-coverage-gaps.md`](04-coverage-gaps.md). The material items:

| Area | Status |
|---|---|
| Browser matrix (Chrome/Edge/Firefox/Safari, 5 viewports) | **Not run.** One in-app browser available; needs a human or a Playwright grid |
| Accessibility (keyboard-only, focus states, contrast, severity not by colour alone) | **Not run** |
| Performance thresholds and N+1 detection (§11) | **Not run** |
| §12.D interactive checks — network interruption, session expiry mid-form, unsaved-changes warning, keyboard-only completion | **Not run** — browser-dependent |
| State machines for the non-BRD lifecycles (policy, incident, complaint, case, vendor assessment, sustainability filing, submission pack) | **Not run** |
| TC-03 organisation hierarchy, TC-06 checklists, TC-09 spot checks, TC-11 compensating controls, TC-16 notifications | **Not run** — fixtures now exist for all of them |
| TC-12-01 notification content; TC-12-07 worker-down recovery | **Not run** |
| Load testing at NFR-2 scale (500 concurrent users) | **Out of scope** per Part B §1 |
| External penetration test | **Out of scope** — recommend commissioning separately |

**Environment divergences:** the engine is MariaDB 10.4.28 where the stack targets MySQL (all 161 migrations applied cleanly and enum constraints are enforced, but JSON semantics differ); the scheduler was driven through a clock harness rather than run as a daemon; HSTS and the `Secure` cookie flag need an HTTPS origin.

---

## 7. Recommended sequence

1. **Product Owner rulings** — FR-10.9 (§5.2); whether DEF-002 and DEF-007 are Critical (§4); whether DEF-005 (unlogged denials) is in release scope, given FR-12.4 does not literally name denied attempts.
2. **Fix the six Highs.** Four are small and well-localised: DEF-012 is one missing clause, DEF-016 one line in a controller, DEF-001 a two-way branch that needs seven cases, DEF-019 a missing `throttle`. DEF-007 and DEF-002 need a small design decision first.
3. **Re-run the suite.** The 29 failing tests are regression guards — they go green as the defects are fixed, and fail again if anything regresses.
4. **Execute the remaining modules.** Fixtures and the clock harness now exist, so TC-03, TC-06, TC-09, TC-11 and TC-16 are unblocked.
5. **Commission the browser, accessibility and performance work** — the part this environment cannot close.
6. **Update the BRD**, or issue a superseding requirements document, so the other ~60% of the product has something to trace against.
7. **Reissue this report.**

---

## 8. What was delivered

| File | Contents |
|---|---|
| [`00-system-map.md`](00-system-map.md) | Phase 0 discovery — 502 routes, 161 migrations, 125 models, 45 policies, 6 roles / ~180 permissions, 5 state machines, 22 scheduled tasks; BRD reconciliation; 8 new test cases arising from discovery |
| [`01-test-execution-log.md`](01-test-execution-log.md) | Case-by-case results with evidence, plus every correction made to my own tests |
| [`02-defect-log.md`](02-defect-log.md) | 21 defects in the required format — root cause at `file:line`, reachability assessment, and severity reasoning |
| [`03-traceability-matrix.md`](03-traceability-matrix.md) | All 106 BRD requirements, honestly statused |
| [`04-coverage-gaps.md`](04-coverage-gaps.md) | Everything untested and why; environment divergences; prerequisites |
| `evidence/` | Route table, escalation failure capture, live CSRF verification, live security-header verification, Tier 2 policy inventory |
| `tests/e2e/` | **218 committed test methods across 18 files**, running against the real MariaDB schema rather than the product suite's in-memory SQLite |
| `database/seeders/E2ETestSeeder.php` | Reproducible fixtures — deactivated user, ThirdLine config, spot checks, findings, compensating controls, evidence with real files, and a second tenant |
| `phpunit-e2e.xml` | Suite configuration |

**No product code was modified.** Ground rule 2 was held throughout: defects were logged, not patched.

### A note on the tests themselves

Nine assertions of my own were wrong before they were right, and each is recorded in the execution log rather than quietly corrected. Three would have reported a **false Critical on XSS** — one matched a substring that survives correct escaping, one matched the page's own Vite tags, one matched the JSON-escaped form of the payload. Others: a `call()` helper that collided with Laravel's, `Http::fake()` appending rather than replacing stubs, an expectation that the controls export would include library templates, and a residual-risk test reading columns that do not exist.

They are documented because a test suite handed to a team is only as trustworthy as its author's willingness to say where it misled them. In every case the product was right and the test was wrong — which is also why the 21 defects that survived can be relied on.

---

## 9. Sign-off

| Role | Name | Decision | Date |
|---|---|---|---|
| QA Lead | | **Fail — do not release** | 2026-08-14 |
| Product Owner | | | |
| Head of IS Audit | | | |

**Statement required by the plan.** 348 test cases executed, **91% pass rate** on those executed, **21 open defects (0 Critical, 6 High, 10 Medium, 5 Low)**, **no accepted risks recorded**, and 9 of 19 Part B modules together with the browser, accessibility and performance suites untested.

The control at the heart of the product — *only the control function may close an exception, and only after verifying remediation* — is verified and holds under direct attack from every other role. The escalation engine that is supposed to tell the institution when a control has failed does not.
