# 04 — Coverage Gaps

**Run date:** 2026-08-14 · **Branch / commit:** `phase-17-extended-grc` @ `8d233c7`

This document exists so that nothing untested is mistaken for something tested. Every Part B case not appearing in [`01-test-execution-log.md`](01-test-execution-log.md) is listed here with the reason it was not run.

> **Phase 2 update (2026-08-20).** The browser-dependent portion of these gaps
> has since been executed against the live application in a real browser —
> TC-01 session flows (logout, back-button, live session termination), the
> §12.D browser-driven form checks, TC-16 notification content on a live
> queue, responsive/mobile layout, an accessibility first pass, and the CR-01
> loop end-to-end through the UI. Results and the four defects it surfaced
> (DEF-022 … DEF-025, all fixed same-day) are in
> [`06-phase2-browser-log.md`](06-phase2-browser-log.md); what remains open is
> listed in its §10. The tables below are preserved as written after Phase 1.

---

## 1. The headline

**The run is half complete.** Ten modules were executed to depth — TC-02 authorisation, TC-07 control testing, TC-08 effectiveness ratings, TC-10 closure control, TC-12 escalation, TC-13 evidence/retention/NDPA, TC-14 reporting, TC-15 dashboard, TC-17 audit trail, TC-18 ThirdLine integration — plus partial TC-01. **Eight of the nineteen Part B modules were not executed at all**, and every exit criterion has now been assessed.

Exit criterion 1 — *100% of planned test cases executed* — is **not met**. No release recommendation can rest on this run alone.

---

## 2. Modules not executed

| Module | Part B ref | Reason not executed |
|---|---|---|
| Authentication & session | TC-01 | **Partially executed** — TC-01-02 (no account enumeration) and TC-01-03 (deactivated user denied at login) pass. Session timeout, lockout/throttle, password policy, MFA, concurrent sessions and logout/back-button all not run. |
| Users, roles & permissions | TC-02 | **Substantially executed** — TC-02-02, -05, -06, -07, -08 all run; see §2a. Still not run: TC-02-01 (user CRUD field matrix — no user create/edit endpoint was exercised) and TC-02-03's reassignment-of-open-assignments half. |
| Organisation setup & hierarchy | TC-03 | Not reached. |
| Control library | TC-04 | Not reached. Includes the maker–checker approval chain and versioning (FR-1.7, FR-1.8). |
| Risk & RCSA mapping | TC-05 | Not reached. Residual-risk recomputation (FR-2.4) requires independent recalculation against the documented formula — see §4. |
| Checklists | TC-06 | Not reached. |
| Control testing | TC-07 | **Executed** — 20 tests, 19 pass. **Not run:** TC-07-05 (draft save/resume), TC-07-06/-07 (evidence during a test; the mandatory-evidence submission gate was not located). |
| Effectiveness ratings | TC-08 | **Executed** — 33 tests, 32 pass. Full 16-cell matrix verified plus three invariants. FR-2.4 residual risk reconciles exactly to an independent recomputation. |
| Spot checks | TC-09 | Not executed. **No longer blocked** — 4 spot checks (one per lifecycle state) and 6 findings are now seeded. |
| Exception management (beyond closure) | TC-10-01/-02/-03, -11, -12, -14, -15 | Partially executed. Ageing (TC-10-11), overdue escalation (TC-10-12), bulk actions (TC-10-14) and filter reconciliation (TC-10-15) not run. |
| Compensating controls | TC-11 | Not executed. **No longer blocked** — 3 compensating controls seeded, including one temporary control past its end date for TC-11-04. |
| Escalation engine | TC-12 | **Executed** — 11 tests. TC-12-02 through -06, -08 and -09 covered; DEF-012 found. **Still not run:** TC-12-01 (immediate owner notification on a failed check, plus notification *content* correctness — working deep links, no placeholder tokens, no PII in subject lines) and TC-12-07 (queue worker down then restored), which needs the queued-channel behaviour of `NotificationDispatcher` established first. |
| Evidence, retention & NDPA | TC-13 | **Executed** — 27 tests, 19 pass. TC-13-01 through -05, -07, -08, -09 and -11 all covered. Two defects (DEF-010, DEF-011), both in upload-time file validation. **Still not covered:** TC-13-06 is **N/A** (no evidence-delete route exists — removal is only via the disposal pipeline, which is a stronger design than the case assumes); **TC-13-10** (encryption at rest and in transit) not executed — a deployment concern not observable from this environment; **FR-9.11 / TC-13-09's data-subject-request half** not covered — no DSR search path was found (confirms discovery finding B-06); **FR-9.10** in-place redaction not found (confirms B-03). |
| Reporting | TC-14 | **Executed** — 15 tests, all pass, including TC-14-10 figure reconciliation. **Not run:** TC-14-02 (configurable report templates — report designer is Tier 2), TC-14-08 (1,000+ record performance), TC-14-09 (scheduled delivery). |
| Dashboard | TC-15 | **Executed** — 17 tests, 16 pass. All 12 tiles reconcile exactly to SQL. DEF-015 raised on missing FR-10.6 filters. |
| Notifications | TC-16 | Not reached beyond the two incidental escalation findings. |
| ThirdLine integration | TC-18 | **Executed** — 19 tests, 17 pass. Both directions, auth, conflict resolution, idempotency, failure and replay all covered. DEF-016 and DEF-017 raised. **Not run:** TC-18-11 (large batch sync). |
| Non-functional | TC-19 / §11 | Not reached: performance thresholds, N+1 detection, concurrency. |

### 2a. TC-02-05 sweep — what it did and did not reach

The route-table sweep is the broadest authorisation evidence in the run, so its limits matter as much as its result.

| Population | Count | Swept? |
|---|---|---|
| Mutating routes (POST/PUT/PATCH/DELETE) | 325 | — |
| …declaring a role/permission requirement | 204 | — |
| …parameterless | 43 | **28 swept**; 15 skipped because every seeded role satisfies the requirement |
| …parameterised | 161 | **only the `exceptions/{exception}` family** — a sweep needs a real record id per parameter for route-model binding to resolve and the authorisation layer to be reached |
| Mutating routes with **no** role/permission middleware | **121** | **Fully triaged.** 96 carry an inline or `FormRequest::authorize()` check; all 25 without one were individually reviewed. 24 are correct by design; the 25th is DEF-009. A regression guard now fails the build if a new un-gated route appears without a check. |

The 15 skipped routes need a purpose-built under-privileged account to test meaningfully — a seventh fixture user holding no permissions. That is the cheapest way to close the gap and should be added before the next run.

**IDOR (TC-02-07) is now executed** — URL-based isolation closed in both directions on web and API; payload IDOR found (DEF-008). Still not covered: IDOR on the ~30 other parameterised resource families (documents, policies, incidents, vendors, cases, submissions…), which were not probed individually.

## 3. Test types not applied

| Type | Part B ref | Status |
|---|---|---|
| Form submission matrix | §12 (31 checks × ~78 forms) | **Executed on the priority forms** (control, exception, risk) — 32 tests, 31 pass. All of §12.B (server parity) and §12.C (mass assignment, XSS, SQLi, CSRF) covered; §12.A field-level and §12.E persistence covered on those forms. **Not run:** §12.A.2/5/8/10/12 on the remaining ~75 forms; §12.D.21–28 (network interruption, session expiry mid-form, unsaved-changes warning, cancel, keyboard-only completion) — all browser-driven; §12.B.13 on forms other than the three priority ones. |
| Workflow / state machine | §7 | Executed for the exception lifecycle only. Control, test-instance, spot-check and compensating-control lifecycles untested. Concurrent-transition testing (§7.5) not attempted for any lifecycle. |
| Data integrity & concurrency | §8 | **Mostly not executed.** Tenant isolation is now covered end-to-end (see TC-02-07). Still untested: referential integrity, transaction atomicity, optimistic locking, timezone consistency, and the individual `withoutGlobalScopes()` call sites. |
| API test suite | §9 | **Executed across all 11 endpoints** — 65 tests, 63 pass. Auth, error envelope, wrong types, oversized payloads, tenant scoping, leak checks and status codes all covered. DEF-019 raised on the absent rate limiting. **Not run:** true malformed-JSON bytes (the Laravel test client always sends valid JSON) and per-endpoint payload-schema fuzzing on the four write endpoints. |
| Security checks | §10 | **Executed** — 12 tests plus live header/cookie/exposure verification. Headers, cookie flags, hashing, login throttling, session fixation, open redirect, secret exposure and `composer audit` (clean) all pass. DEF-019, DEF-020 and DEF-021 raised. **Not run:** HSTS and the `Secure` cookie flag, both of which need an HTTPS origin; and external penetration testing, which remains out of scope. |
| Performance | §11 | **Not executed.** |
| Compatibility & responsive | §12 | **Not executed.** No browser matrix, no viewport testing. |
| Accessibility | §12 | **Not executed.** A `MeetsContrastRatio` rule exists in `app/Rules/` but was not exercised. |
| Regression pack | §13 | N/A — no remediation has occurred yet. |

## 4. Discovery findings raised but not verified

From [`00-system-map.md`](00-system-map.md). Each is a hypothesis from reading code, **not** a tested result, and must not be reported as either a pass or a defect.

| Ref | Finding | Status |
|---|---|---|
| TC-New-01 | `webhooks/*` is CSRF-exempt; HMAC/token signature is the only control | Not tested |
| TC-New-02 | `auth/sso/*/acs` is CSRF-exempt; SAML signature + replay protection is the only control | Not tested |
| TC-New-04 | Every `withoutGlobalScopes()` call site is a candidate cross-tenant leak | **Partially tested** — isolation confirmed end-to-end on exceptions, controls, risks, dashboard listings and the `/api/v1` read surface. The individual `withoutGlobalScopes()` call sites (e.g. `refreshAgeing`, `ResidualRiskService::recompute`) were not probed directly. |
| TC-New-05 | `Risk Accepted` is declared terminal but the ageing sweep moves it to `In Progress` via a direct `update()` that bypasses `transition()` | Not tested |
| TC-New-06 | `EvidenceService::LINKABLE` supports only 4 link types; incidents, vendor assessments and monitoring findings may not accept evidence | **Confirmed by code and exercised indirectly** — `linked_type` is validated against the 4-key allowlist, so anything outside it is rejected. Whether those other modules *need* evidence is a product question for the PO. |
| TC-New-08 | Ageing refresh uses `updateQuietly()`, so ageing and overdue changes fire no model events and write no audit record | Not tested |
| B-01 | FR-2.6 / FR-11.8 — NexusRisk risk-register consumption not observed | Not confirmed |
| B-02 | FR-11.9 — outbound webhook subscriptions for Critical/High exceptions not observed | Not confirmed |
| B-03 | FR-9.10 — in-place evidence redaction not observed | **Still not found** during TC-13 execution. Treat as not implemented pending PO confirmation. *Should*-priority. |
| B-05 | FR-12.2 — tenant-defined custom roles not confirmed | Not confirmed |
| B-06 | FR-9.11 — data-subject request handling not observed | **Still not found** during TC-13 execution. PII classification and categories are captured per evidence item, so the data to answer a DSR exists, but no search or reporting path over it was located. NDPA-relevant. *Should*-priority. |

**B-04 is withdrawn.** The system map recorded OAuth 2.0 client credentials (FR-11.6) as absent. `integration_configs.auth_type` is `enum('api_key','oauth2')`, so OAuth2 is supported at schema level. Whether it is implemented end-to-end is **not confirmed** and moves to the list above.

## 5. Environment divergences

| # | Divergence | Consequence |
|---|---|---|
| ENV-1 | Engine is **MariaDB 10.4.28**; the stack targets MySQL | All 161 migrations applied cleanly and enum constraints are enforced. MariaDB 10.4 nonetheless differs in JSON storage (`LONGTEXT` alias, no JSON validation), collation defaults and functional-index support. Any JSON-column behaviour verified here should be re-verified on the production engine. |
| ENV-2 | Scheduler not run as a daemon | All time-dependent behaviour (escalation SLAs, ageing, retention expiry, compensating-control expiry, scheduled reports) is unverified end-to-end. |
| ENV-3 | Single tenant seeded | Multi-tenancy isolation (§8) cannot be tested without a second tenant. A second tenant with overlapping references is a prerequisite for TC-New-04 and TC-02-07 (IDOR). |
| ENV-4 | Mail captured to `log` / `array` | Sufficient to prove dispatch and content; does not prove deliverability. Acceptable, and out of scope per Part B §1. |
| ENV-5 | Browser matrix not exercised | Testing used the in-app browser at default desktop viewport only. Chrome/Edge/Firefox/Safari and the five required viewports are untested. |
| ENV-6 | The e2e suite runs on the real MariaDB schema; the **product** suite (`phpunit.xml`) runs on in-memory SQLite | The product suite's 69 tests do not exercise enum constraints. Any state-machine guarantee proven only by that suite is weaker than it appears. |

## 6. Prerequisites to complete the run

### Done — harness built 2026-08-14

1. ~~**Fixture seeder extension**~~ — **complete.** `E2ETestSeeder` now provides spot checks at all four lifecycle states with findings, compensating controls including one past its end date, evidence covering clean / PII-classified / legal-hold / past-expiry cases with real files on disk, and a **second tenant** with its own users, unit, control and exception. Idempotent, verified by re-running.
2. ~~**Clock control**~~ — **complete.** `E2ETestCase` provides `runCommandAt()`, `at()`, `lagos()` and `makeOverdueByDays()`. The clock is restored in a `finally` block so a failing assertion cannot leak a frozen clock into the next test. Proven against two different scheduled commands rather than asserted.

`tests/e2e/HarnessSelfCheckTest.php` guards both: it fails loudly if a fixture goes missing, if an evidence file's bytes stop matching its stored checksum, if the second tenant stops being a different tenant, or if the clock helpers stop moving the clock. **Run it first — if it fails, no other result in the suite should be trusted.**

**Now unblocked and ready to execute:** TC-09, TC-11, TC-12 (in full), TC-13, TC-10-11/-12, TC-01-03, TC-02-05, TC-02-07, TC-New-04, TC-18.

### Remaining

3. **Form matrix automation** — a reusable driver for the §12 matrix so it can be applied to the ten priority forms without hand-writing 310 cases.
4. **API suite** — `/api/v1` with the seeded `X-Api-Key`; unblocked.
5. **Browser matrix and accessibility pass** — the only genuinely manual remainder, and the one item this environment cannot fully close (one in-app browser; Safari, Firefox and Edge need a human or a Playwright grid).
