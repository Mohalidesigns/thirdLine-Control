# 06 — Phase 2 Browser Execution Log

**Run date:** 2026-08-20 · **Branch / commit:** `claude/atheris-exception-manager-hy7wvq` @ post-`4e35ff1`
**Environment:** live e2e application — `APP_ENV=e2e php artisan serve` on `127.0.0.1:8123`, MariaDB `atheris_control_e2e`, real browser (Chromium via the in-app pane), real sessions, real queue worker, `MAIL_MAILER=log`
**Method:** driven end-to-end in a real browser — real logins, real clicks and keystrokes, DOM/accessibility-tree reads, console and network capture, viewport resizing. Nothing in this log was produced through `actingAs()` or the HTTP test client.

This closes the browser-dependent portion of the Phase 1 coverage gaps
([04-coverage-gaps.md](04-coverage-gaps.md)): TC-01 session flows, the §12.D
browser-driven form checks, notification content on a live queue, responsive
layout, and UI execution of the CR-01 loop. Defects found in this run are
DEF-022 … DEF-025 in [02-defect-log.md](02-defect-log.md) — all four fixed and
re-verified in the same run.

---

## 1. TC-01 — Authentication & session (browser)

| Case | Result | Evidence |
|---|---|---|
| Login happy path (CFH, Control Owner) | **PASS** — authenticated, role-scoped dashboard and sidebar render | screenshots in run |
| Logout terminates the session server-side | **PASS** — after logout, a fetch to an authenticated route returns a redirect (`opaqueredirect`), and reload lands on `/login` | live probe |
| Back-button after logout | **PASS with observation** — the browser's bfcache repaints the last authenticated page, but every server interaction is refused and reload bounces to login. Observation O-1: no `Cache-Control: no-store` on authenticated pages, so the *pixels* of the last page survive logout on a shared machine until any interaction. |
| Enter key submits the login form | **INCONCLUSIVE — automation artifact.** The automated key event produced no POST, but code review shows a textbook implicit-submission structure (single `<form onSubmit>`, default-type button inside it, no keydown interception anywhere on the page). Synthetic CDP key events are known not to trigger implicit submission reliably. Needs one manual keyboard check; not raised as a defect. |

## 2. CR-01 Exception Manager — full loop in the UI

Executed as the Definition-of-Done demo path, in a real browser, across three
logins:

1. **Issue (CFH):** EXC-2026-007 → "Escalate to departments" → two targets
   (Operations / Emeka Nwosu, Information Technology / Folake Balogun), issue
   notes, required response `both` → "Issue 2 escalations" → toast confirms,
   panel shows EXE-2026-004/005, each with its own respondent and clock.
   Response due computed as **27 Aug 2026 23:59 tenant time** — the
   business-day SLA clock, live.
2. **Delivery:** queue worker drained with **zero failed jobs**. Both
   respondents received in-app notifications (badge count) **and** emails
   carrying reference, severity, due date and a working deep link to the
   response screen — no evidence, no restricted detail (TC-12-01 content
   correctness, live).
3. **Acknowledge + respond (Control Owner):** opening the response screen
   stamped `Acknowledged` on first open (CR1.3). Submit disabled until the
   form is complete; position/root cause/action plan/target date captured;
   submission confirmed by toast and the round-1 thread rendered immutably.
4. **SoD in the UI:** the responder sees **no** accept/reject/review/close
   control anywhere on their own response.
5. **Review (CFH):** "Review response" → accept with note → escalation moves
   to `Accepted`, action button becomes "Validate & close", stat cards
   recompute live (Awaiting Response 3→2, Avg Response Days 6→4).
6. **Reporting:** Register, **Department scorecard** (ack-on-time %,
   responded-on-time %, avg days, open overdue, closure rate, re-issue rate)
   and Ageing tabs all render with figures consistent with the day's actions.

**Result: PASS end-to-end.**

## 3. §12.D — browser-driven form checks

| Check | Result |
|---|---|
| §12.A errors render inline; typed input survives a failed submit | **PASS** — incomplete exception submit renders "The target closure date field is required." under the field; title preserved |
| Client gate on incomplete response form | **PASS** — Submit disabled until required fields present |
| §12.D.24 cancel path exists | **PASS** — Cancel (type=button) present on create forms |
| §12.D.23 unsaved-changes warning | **FAIL → FIXED** — navigating away from a dirty form silently discarded input (DEF-025). `useUnsavedChanges` hook added and wired to the exception create and departmental response forms; guard verified live (navigation blocked when declined). Remaining ~75 forms: wire the same one-line hook as they are touched. |
| §12.D.19 double-submit | already guarded by `DedupeFormSubmission` (Phase 1 remediation) — not re-run in browser |

## 4. TC-16 — Notifications (browser, live queue)

- Notification centre renders; unread markers and "Mark all read" present.
- Escalation and governance notifications carry readable summaries.
- **DEF-024 (fixed):** `SubmissionActionNotification` rows rendered as a bare
  "Notification" — its payload had no `summary` key. Payload now carries one.
- Escalation emails for **every** subject type build and deliver (DEF-001 fix
  observed live on risk-appetite and KRI escalation mails in the log, working
  deep links included).
- Observation O-2: notification rows are not links — deep-linking from the
  centre relies on the email. UX backlog, not a defect.

## 5. Cross-tenant leak found in the browser — DEF-022 (fixed)

The escalate dialog's respondent picker listed **the second tenant's users**
("Rival CFH (TEST)", "Rival Owner (TEST)"). Root cause: `User` deliberately
lacks `BelongsToTenant`, and ~39 controller call sites built pickers with
`User::where('is_active', true)->orderBy('name')->get()` — unscoped. This is
the read-side sibling of DEF-008 and was invisible to the HTTP-level suite
because no test read the *option list* of a form. Fixed with a
`User::tenantPicker()` scope replacing all 39 sites; verified live (Rival
users gone, own-tenant users intact).

## 6. Compatibility, responsive, theming

| Surface | Result |
|---|---|
| Desktop 1280×720 | PASS — all pages exercised render correctly |
| Tablet 768×1024 | PASS — single-column tiles, legible |
| Mobile 375×812 | PASS — hamburger drawer with full grouped nav and active states; stat cards stack; **no horizontal body scroll** (`scrollWidth == innerWidth`) |
| Dark mode | Not implemented — the app renders its light theme under `prefers-color-scheme: dark`. Deliberate single-theme; recorded as observation O-3, not a defect. |

## 7. Console hygiene — DEF-023 (fixed)

Every page load logged a CSP violation: `resources/css/app.css` imported
Google Fonts, which `style-src 'self'` blocks — so the intended Inter/Roboto
Mono never rendered anywhere (the app has always displayed its fallback
stack), and the tag contradicted the residency posture (R5: no third-party
origins). The dead import is removed; zero third-party requests confirmed
live. If the brand fonts are wanted, ship them self-hosted — the font stacks
already name them first.

## 8. Accessibility (first pass)

- Form inputs carry programmatic labels (accessibility tree exposes
  "Email", "Password", field labels throughout) — PASS.
- Visible focus ring on focused inputs — PASS.
- Observation O-4: icon-only buttons (hamburger, bell, search, sidebar
  group toggles) have **no `aria-label`** — they read as unnamed buttons in
  the accessibility tree. Backlog for an accessibility pass; not blocking.

## 9. Observations register (not defects)

| # | Observation |
|---|---|
| O-1 | Authenticated pages lack `Cache-Control: no-store`; bfcache repaints the last page after logout until any interaction |
| O-2 | Notification-centre rows are not clickable deep links |
| O-3 | No dark theme; light theme renders under `prefers-color-scheme: dark` |
| O-4 | Icon-only buttons lack `aria-label` |
| O-5 | Escalate dialog stays open (reset to a fresh target) after a successful issue; a close-on-success would read better |
| O-6 | Seeded notification URLs carry the seed-time `APP_URL` (`http://localhost/...`); runtime notifications carry the correct origin — environmental, fix by seeding with the deploy URL |
| O-7 | Enter-to-submit on login could not be reproduced as a defect (see §1) — one manual keyboard check recommended |

## 10. What this run still does not cover

- True cross-browser matrix (Firefox/Safari) and OS matrix — one Chromium
  engine was used.
- §12.D.21 network interruption mid-submit and §12.D.22 session expiry
  mid-form (needs a controllable proxy / clock).
- Performance under load (§11), scheduled report delivery (TC-14-09),
  large-batch sync (TC-18-11).
- Full keyboard-only traversal of every form (§12.D.27 beyond login/create).
- HSTS and `Secure` cookie flag (needs an HTTPS origin).
