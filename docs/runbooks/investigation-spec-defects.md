# Investigations — §7 reference defects, and how each was resolved

The SecondLine Investigations specification lists six defects observed in
the ThirdLine Internal Audit implementation and asks that they be fixed
rather than replicated. This note records, per defect, whether it actually
existed in this codebase, what was changed, and which test holds the fix
in place.

Three of the six were present, one was present in a latent form that a
single config change would have exposed, one was already structurally
prevented, and one surfaced a separate and more serious defect while being
investigated.

**Scope note.** Investigations already existed here as CR-04. This work is
a delta against it, not a rebuild — see the reconciliation at the end for
the specification clauses honoured differently, and why.

---

## §7.1 — Date off-by-one · **latent, fixed**

**Present?** Yes, but masked. `config('app.timezone')` is `'UTC'`, and the
bug only appears off UTC.

**Mechanism.** A `date` cast holds Carbon at local midnight, and
`toArray()` serialises it through `toJSON()`, which converts to UTC. One
hour east of Greenwich, midnight on the 28th is 23:00 on the 27th. The
edit form binds `investigation.reported_date?.slice(0, 10)` and gets the
27th. Reproduced exactly:

```
Africa/Lagos, 'date' cast => "2026-07-27T23:00:00.000000Z"
JS .slice(0,10) binds     => 2026-07-27
```

That is the reference implementation's symptom precisely: 28 Jul 2026 on
the header, 27/07/2026 in the form. The deployment timezone for this
client is Africa/Lagos, so this was one `.env` line away from being live.

**Fixed.**

- `Investigation`, `InvestigationFinding` and `ConsequenceAction` cast
  their date columns as `date:Y-m-d`, so a calendar date serialises as a
  calendar date and carries no spurious instant.
- `formatDate()` in `resources/js/utils.js` now detects a date-only value
  and formats it from its own parts under UTC, instead of passing it
  through `new Date()` and the tenant timezone. Without this half the bug
  simply moves: `new Date('2026-07-28')` is UTC midnight, which renders as
  the 27th for any tenant timezone behind UTC.

**Test.** `InvestigationDateRoundTripTest` — runs every assertion under
both `Africa/Lagos` and `UTC`. Verified to fail before the fix.

---

## §7.2 — Duplicated team member · **already prevented, rendering hardened**

**Present?** No. The structural rule the specification asks for was
already in place: `investigation_team_members` carries a unique
`(investigation_id, user_id)` index, the lead is seeded onto the team with
`role = 'lead'`, and `lead_investigator_id` is kept in sync by
`InvestigationService`.

**Changed anyway.** The report did not print its team at all — see §7.4
below, which is how that was noticed. Team rows are now rendered, keyed by
`user_id`, with the denormalised `lead_investigator_id` only contributing a
row if the team table does not already carry that person. The dedup the
specification asks for is therefore enforced at the point of rendering as
well as in the schema.

**Test.** `InvestigationReportTest::test_the_generated_sections_carry_the_record_not_a_placeholder`
asserts the parties table has no repeated name.

---

## §7.3 — Financial labelling and validation · **partly present, fixed**

**Present?** The mislabelling was not: the case detail screen already
showed "Estimated impact" and "Confirmed loss" as separate, correctly
named rows. The missing validation was, and so was the list column — the
register had no financial column at all.

**Fixed.**

- `Investigation::financialImpact()` returns the amount *and* the basis it
  is on (`Confirmed` where a loss exists, otherwise `Estimated`), so no
  surface has to guess. The register's new column is labelled **Financial
  impact** and prints the basis under the figure.
- `Investigation::confirmedLossExceedsEstimate()` flags a confirmed loss
  more than 20% above the opening estimate. It **warns and saves** — an
  investigation uncovering more than was first estimated is normal, and
  blocking it would be wrong. The warning is raised on update and again at
  completion.

**Test.** `InvestigationCompletionGateTest` — the reference figures
(₦32.9m estimated, ₦50m confirmed) flag; 10% over does not; a loss below
estimate does not.

---

## §7.4 — Chronology conflation · **present, fixed — and see below**

**Present?** Yes, and worse than in the reference. `InvestigationReportBuilder::chronology()`
rendered the activity diary only. The investigator's narrative chronology
— collected on the create form, collected on the edit form, stored in
`chronology` and `chronology_rich`, and one of the six Report Narrative
fields the form tells the user "feed the Investigation Report" — was
**never rendered anywhere**. It was write-only data.

**Fixed.** The section now carries the narrative first, then the case
handling timeline table drawn from the diary, separately labelled.

---

## §7.4a — Speak Up reporter seeded onto the investigation team · **found while fixing §7.4**

Not in the specification's list. Found because §7.2's fix made the report
print its own team, which broke
`InvestigationReportTest::test_no_reporter_identity_appears_in_a_speak_up_origin_report`.

**Mechanism.** Two individually correct decisions composing into a wrong
one:

1. `CaseService::initialAllowlist()` adds the reporter to a non-anonymous
   Speak Up case's allowlist. Correct — a reporter must be able to follow
   their own report and read the feedback written back to them.
2. `InvestigationService::initialTeam()` seeded the investigation team from
   that allowlist **wholesale**.

The result: the reporter of a confidential Speak Up report became a team
member of the investigation into their own report — with sight of the
subjects, their recorded outcomes, the evidence register and the case
file, and with their name in the report's parties table, from which a
reader could work backwards to the source.

The existing test passed only because the report declined to print its
team. The leak was in the record, not the rendering.

**Fixed.** `initialTeam()` now excludes the origin case's `reporter_id`.
The allowlist grants sight of the **submission**; it does not confer a
seat on the investigation.

**Test.** The same test now asserts the record first — that the reporter is
not a team member — before asserting their name is absent from the
rendered document.

**Open question for the client.** A reporter deliberately assigned to the
team by someone holding `assign investigations` is still permitted, and
their name would then appear in the report as an investigator. That is a
different fact from reporter identity propagating automatically, so it has
been left alone rather than silently decided. Flagging it alongside the
existing questions in `investigation-confidentiality.md`.

---

## §7.5 — Empty child collections on a completed case · **partly present, fixed**

**Present?** Partly. `InvestigationService::complete()` already required a
risk rating and a resolved outcome for every named subject. It did not
require a finding or a conclusion, so the reference implementation's
state — Completed, rated High, with Findings (0) — was reachable.

**Fixed.** Completion now additionally requires:

- **at least one finding.** A completed case with none produces a report
  with nothing in it. If an investigation established nothing, that is
  itself a finding and should be recorded as one.
- **a conclusion**, whitespace-only rejected. It is the one report section
  that cannot be generated from the record.

And warns, without blocking, when there is no subject, no evidence, or a
confirmed loss well past the estimate. A process failure with no culpable
individual is a legitimate outcome; an omission looks identical at the
schema level, so it is surfaced rather than enforced.

**Test.** `InvestigationCompletionGateTest`.

**Consequence.** Twelve existing tests completed cases without findings or
conclusions and were updated to supply them. Those were fixture gaps, not
behaviour the module needed to keep.

---

## §7.6 — Days open on closed cases · **not present, implemented**

**Present?** No — the register had no days-open column to get wrong.

**Implemented** to the specification's rule rather than the reference's
behaviour: `Investigation::daysOpen()` measures to `completed_date`, then
`closed_date`, and only falls back to today for a case that is genuinely
still open. A finished case's counter does not move.

**Test.** `InvestigationCompletionGateTest::test_days_open_freezes_once_the_case_is_completed`
travels ten days forward and asserts the figure is unchanged.

---

## Specification clauses honoured differently

Recorded here rather than silently applied, per §12 of the specification.

| Clause | Specification asks for | What this codebase does | Why |
|---|---|---|---|
| §2.4 | Investigation findings live in the existing `findings` table; do not create `investigation_findings` | Kept `investigation_findings` | `findings` here is a **spot-check** finding — `spot_check_id` is a non-nullable FK. The repo already has four source-specific finding tables. The follow-up flow the clause is really after is `improvement_actions`, which CR-04 already feeds via `investigation_findings.improvement_action_id` and `source_type = 'investigation'`. |
| §9 | Convert narrative columns from text to JSON | Kept the `{field}_rich` JSON column with the plain column as a derived mirror | The Editor.js conversion already shipped (2026-08-20) in this shape. Search, filters, exports and report generation all read the plain column; converting it would remove the mirror they depend on. |
| §5.4 | Add `investigation_id` / `speak_up_submission_id` FK columns | Kept the polymorphic `origin_type` / `origin_id` | That morph already carries ControlException, Incident, Complaint and TestInstance origins. Dedicated columns would be a second pattern for the same fact. |
| §2.10 | The approving authority is Group Head Internal Control | Role created, authority carried by permissions | The role now exists because the specification names it and a tenant needs somewhere to put the person. No gate anywhere keys on the role name — the query layer and the workflow both read permissions, per this codebase's rule that a role name alone never widens a query. A tenant that keeps these duties elsewhere moves the permissions and nothing breaks. |
| §2.10 | Permissions `investigations.review_manager`, `review_ghic`, `approve`, `issue_report` | Three permissions, space-separated | `review_ghic` and `approve` name the **same transition** — at the GHIC review node the only forward action is "Approve" — so they are one permission named for the act. Naming follows this repo's `verb noun` convention rather than the specification's dotted style, matching the eleven investigation permissions already here. |

---

## §5.3 — the report review chain (new capability)

Not a defect: it did not exist. `report_runs.status` is Queued / Running /
Completed / Failed / Expired — the state of a **render**, not of an
approval — and `InvestigationReportController` had only `generate()`.

**Built.** `investigation_reports` carries the approval; the rendering
stays in the shared pipeline, referenced by `report_run_id`. Every other
report type in the product shares `report_runs`, and a board pack has no
use for a manager-review column.

Draft → Manager Review → Group Head Internal Control Review → Approved →
Issued, with return-to-preparer from either review node.

Three rules worth knowing before changing anything here:

- **Separation is enforced per person, not per role.** A preparer cannot
  review their own report and a manager reviewer cannot then approve it,
  even holding both permissions. A separation that depends on how a tenant
  happened to assign roles is not a separation.
- **Issue freezes.** The assembled document is written to `snapshot` and
  the issued PDF renders from it. Readers of an issued report are served
  the snapshot, never the live case. A later edit produces -R02.
- **A failed render does not cost the case its workflow.** The draft
  record is opened outside the render's try block, so a renderer that
  falls over leaves a report that can still be reviewed.

The System Administrator holds none of the three permissions, and the
Control Function Head holds only `review investigation-reports` — so the
chain is three people rather than one person wearing three hats.

**Tests.** `InvestigationReportWorkflowTest` — 21 tests covering the chain,
the permission gates, both self-review guards, return semantics, snapshot
immutability under a post-issue edit, -R02 versioning, the case diary, and
scoped route binding.
