# Speak Up → Investigation: the follow-up surface

Spec §5.4. A concern must be workable, chaseable and reportable-back-on
**without** opening the investigation it may have produced. This note records
what was built, what already existed, and the one boundary that matters most.

## The boundary

Two records, two access regimes, deliberately not merged:

| | Speak Up submission (`cases`) | Investigation (`investigations`) |
|---|---|---|
| Access | explicit allowlist, global scope | team membership + `view confidential-investigations`, global scope |
| Reporter | on their own case's allowlist | **never** |
| Holds | the concern, screening, follow-up, feedback | subjects, findings, evidence, consequences |

An officer handling intake does **not** gain the case file by handling it.
The submission shows the investigation's reference, status, risk rating and
lead — and nothing else. Where the viewer has no reach into the
investigation, the server sends `null` and the panel says no investigation is
visible rather than leaking one.

Proven by `SpeakUpFollowUpTest::test_the_linked_investigation_is_invisible_to_someone_not_on_its_team`,
which asserts both directions: a Control Officer granted the submission sees
nothing, and a Control Function Head — who holds the confidential authority —
still does.

## What already existed

Most of §5.4's data was here before this work, which is why the change is
small:

- the status workflow (`Received → Assessed → Under Investigation →
  Substantiated / Unsubstantiated / Referred → Closed`), richer than the
  specification's five states;
- `case_notes.is_reporter_visible` — feedback to the reporter, in both
  directions, with the token-based reply flow on the public status page;
- `RaiseInvestigationButton`, already on the case screen, already carrying
  the origin;
- the prefill that locks `source = whistleblowing` and forces
  `is_confidential` with `confidentiality_locked` — a Speak Up origin cannot
  be laundered into an ordinary case;
- `speak_up_report_metadata`, encrypted and reveal-gated, which an anonymous
  submission never has a row in at all.

## What was added

**Schema** — `2026_08_27_100004_create_speak_up_follow_up_surface`:

- `cases.triage_note` / `triage_note_rich` / `triage_decision` /
  `triaged_at` / `triaged_by` — the screening decision and its reasoning.
  The stamp is what makes *average time to screen* answerable, which is the
  number a whistleblowing policy is actually judged on.
- `cases.acknowledged_at` / `acknowledged_by` — the first thing a reporter
  asks and the first thing a regulator checks.
- `case_follow_ups` — actions with an owner, a due date and a completion.
  `cases.actions_taken` was one free-text column, and a list in a paragraph
  cannot be chased, counted or reported.

**Model** — `SpeakUpCase::investigation()` as a `morphOne` reading the
existing `origin_type`/`origin_id` pair backwards. Deliberately **not** a
second `investigation_id` column: the specification asks for one, but a
second column can disagree with the morph, and the morph already carries
ControlException, Incident, Complaint and TestInstance origins too.

**Prefill** — the concern now carries across to the investigation with its
Editor.js blocks intact, along with the received date and the entity. The
reporter's identity and device metadata do not; only the submission
reference crosses.

**Authorisation** — a new `followUp` ability, gated like `conclude` rather
than like `update`. The reporter is on their own case's allowlist so they can
follow it, and screening is a decision *about* that concern: a reporter who
also holds `investigate cases` must not be able to screen, chase or answer
their own report.

**UI** — `SpeakUpFollowUp.jsx` on the case screen (screening, acknowledgement,
follow-up log, read-only linked investigation) and a summary on the list:
awaiting screening, average days to screen, substantiated, total, plus
breakdowns by status and category. All counts run through the allowlist scope,
so an officer sees the shape of their own caseload and not the bank's.

## Two decisions worth knowing

**Re-screening does not restart the clock.** `triaged_at` is set once and
never moved. A revised decision updates the decision and the reasoning but
keeps the original stamp — otherwise looking at something twice would flatter
the metric. Same rule for `acknowledged_at`: the question is when the reporter
was *first* told.

**`avg_days_to_screen` is null, not zero, when nothing has been screened.**
Zero would read as "we screen everything the same day", which is the opposite
of the truth it is reporting.

## The anonymity guarantee

`SpeakUpFollowUpTest::test_no_reporter_trace_survives_from_an_anonymous_submission_to_an_issued_report`
does what §5.4 asks for explicitly: anonymous submission → screened → raised
as an investigation → finding → completed → report built, then asserts the
reporter's token and token hash appear nowhere in the investigation record or
the report document, that no device metadata row exists to be copied, that
the origin is named by *type* only, and that nobody but the raising officer is
on the team.

That last assertion exists because of a defect found during this work: the
investigation team was being seeded from the Speak Up allowlist wholesale,
which put the reporter of a non-anonymous report onto the investigation into
their own report. Fixed in `InvestigationService::initialTeam()` — see
`investigation-spec-defects.md` §7.4a.

## Still open

- **A reporter deliberately assigned to the investigation team** by someone
  holding `assign investigations` is still permitted, and their name would
  then appear in the report as an investigator. That is a different fact from
  identity propagating automatically, so it has been left as a question rather
  than silently decided.
- **The `refer_externally` decision records the decision but triggers
  nothing.** Whether a referral to a regulator or the police should open a
  tracked obligation is a client question, not a technical one.
