# Investigation confidentiality and access (CR-04 §D.3)

Two registers in this product carry a confidentiality model, and they are
not the same model. Getting them the wrong way round is the mistake this
page exists to prevent.

| | `cases` — Speak Up intake | `investigations` — casework |
|---|---|---|
| Class | `App\Models\SpeakUpCase` | `App\Models\Investigation` |
| Access | allowlist on the record (`access_user_ids`) | membership of `investigation_team_members` |
| Oversight override | `view all cases`, **System Administrator only**, read-only | `view all investigations` — ordinary cases only |
| Confidential override | none; the allowlist is absolute | `view confidential-investigations` |
| Control Function Head | **deliberately withheld** from `view all cases` | holds both overrides |

The Control Function Head reaches a Speak Up report the same way everyone
else does — by being named on it. On an investigation they are the
supervisor and must see the register. That asymmetry is the reason the two
modules were not merged, and it is enforced in `RolePermissionSeeder`.

## The two regimes on `investigations`

Both are one query — `Investigation::scopeVisibleTo()`, applied as the
`visibility` **global scope**, so a query that skips the controller and the
policy still cannot return a case the user may not see.

**Ordinary investigation** — visible to:
- the lead investigator
- anyone on the team
- the creator
- anyone holding `view all investigations`

**Confidential investigation** — visible to:
- the lead investigator
- anyone on the team
- anyone holding `view confidential-investigations`

`view all investigations` does **not** open a confidential case. That is the
whole point of the second permission, and `InvestigationConfidentialityTest`
asserts it against a role that holds the first and not the second.

Every read of a confidential case file writes two records: an
`investigation_activities` row of type `confidential_view` that the
investigating team can see on the case timeline, and an `audit_trail` entry
of `confidential_case_viewed`. An access log nobody opens is not oversight.

## The Speak Up boundary — four rules

When an investigation's `origin_type` is `SpeakUpCase`:

1. `is_confidential` is forced true and `confidentiality_locked` is set.
   Nobody on the investigating team can lower it — the protection belongs
   to a reporter who is not on the team and cannot argue for it. Enforced
   in `InvestigationService::open()` and again in
   `InvestigationController::update()`.
2. The initial team is seeded from the case's `access_user_ids`, never from
   the request. Nobody gains sight of a whistleblowing matter by being
   named on an investigation.
3. No reporter identity crosses: not `reporter_id`, not the token hash, not
   Tier 2 metadata, and not into any report section. The report's Background
   names the origin by **type** — "a Speak Up report" — and nothing more. An
   anonymous case's investigation runs end to end without ever resolving a
   person.
4. Adding a team member additionally requires that person to be on the
   case's allowlist. One allowlist, enforced in both directions.

### Granting someone access to a Speak-Up-origin investigation

Do **not** add them to the investigation team first — the service will
refuse it, and correctly.

1. Someone already on the Speak Up case grants access there
   (`cases.access.grant`).
2. Then add them to the investigation team.

If nobody on the case can grant, the case's own allowlist rules apply; there
is no route through the investigation module.

## Exhibits

Investigation exhibits live in the shared `evidence` repository, so they
inherit its retention policy, its legal hold and its access log. Downloading
one additionally requires `view` on the investigation it hangs on
(`EvidenceController::download()`) — without that check, a role check alone
would hand any control officer the CCTV still from a matter they are not
named on.

## Segregation of duties

Three rules, two hard and one deliberately not:

1. **A subject may never be on the team.** Blocked from both directions —
   `assignTeamMember()` and `addSubject()`.
2. **The recommender never approves their own consequence.** Blocked in
   `ConsequenceService::approve()`, whatever roles the actor holds.
3. **The officer who owns the failed control may lead the investigation
   into it** — flagged, not blocked, because in a four-person branch it is
   sometimes unavoidable. It is recorded on `investigations.has_sod_conflict`
   with a note, shown as a banner on the case, and printed on the report
   cover.

Rule 3 deliberately does **not** use `SodConflictRule` / `SodViolation`.
Those tables are entitlement-extract shaped: their rules are toxic function
pairs from a source system, `sod_violations.subject_identifier` is a
source-system staff id explicitly documented as *not* a platform user,
`rule_id` is non-nullable, and `unique(tenant_id, rule_id,
subject_identifier)` would prevent flagging the same officer on two
investigations. The flag belongs on the case.

## Subject PII

`investigation_subjects.name`, `.staff_id` and `.account_number` are the
most sensitive columns CR-04 introduces. They are reachable only through an
investigation the reader can already open, and they must never appear in an
aggregate:

- `InvestigationDashboardService` returns references and titles only; "top
  cases by loss" carries no subject.
- The dashboard activity feed carries the **type** of each event, never the
  diary line, because a diary line is free text and free text can name a
  person.
- Notifications about a confidential investigation carry its reference and
  nothing else — no title, no category, no department. A confidential
  subject line on a lock screen is not confidential.

`InvestigationDashboardTest` asserts all three.

**Open question (§H.5-3).** How long identifying fields may be kept after an
*exonerated* outcome is a policy decision the client has not yet made. The
proposal on the table is a configurable purge at 24 months through the
existing `RetentionPolicy` machinery, retaining the finding and the outcome
in anonymised form. Nothing purges today.

## Other decisions still with the client

| § | Question | Assumed answer, pending confirmation |
|---|---|---|
| H.5-1 | Investigation vs. incident — which number goes on the CBN return? | The incident owns the operational-risk loss; the investigation owns the confirmed loss and recovery for consequence purposes |
| H.5-2 | Who approves a consequence? | A single named approver holding `manage investigation-consequences`. No routing or quorum built |
| H.5-4 | Can a Speak-Up-origin report ever be distributed? | Built as `Board` confidentiality; automatic scheduled distribution is not wired |
| H.5-5 | Branch scope — can a branch officer see other branches' cases? | **Not** implemented as a scope. Today a branch officer sees only the investigations they are on, which is stricter. Confirm before adding `control_entity_id` scoping |
| H.5-6 | Does `suspended` stop the clock? | **Yes, as built.** Suspended cases get their own ageing bucket, are excluded from average-days-to-close, and are not chased |
