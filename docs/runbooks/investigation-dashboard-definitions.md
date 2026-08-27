# Investigation dashboard — figure definitions

Spec §4 and §6.2. What each number on the dashboard counts, where this
product's definition differed from the specification's, and which of the two
won.

The demo pack was **not** replaced (owner decision): the shipped pack
exercises the module rather than reproducing another product's screenshots.
§6.2's five cases are used instead as a **test fixture**, because their
figures are what pin the definitions down. See
`InvestigationDashboardReconciliationTest`.

## The KPI row

| Tile | Counts | Period-scoped? |
|---|---|---|
| Total cases | `reported_date` within the period | yes |
| Completed | `completed_date` within the period | yes |
| **Outstanding** | `reported`, `under_investigation`, `pending_review` | **no** — state of play |
| **Overdue** | anything not `completed`/`closed`, past its target date | **no** |
| Avg days to close | mean `completed_date − reported_date`, completed in period | yes |
| **Archived** | `is_archived = true` | **no** |
| *Open now* | as Outstanding, **plus drafts** | no |
| *Suspended* | `status = suspended` | no |

The last two are this product's, not the specification's. They are kept
because a draft is real work-in-progress to the officer who started it, and a
suspended case is neither outstanding nor finished.

**Outstanding and Overdue are deliberately different populations**, and this
is the most confusable pair on the screen:

- a **draft** is *not* outstanding — it is something somebody started
  typing, not work the function is carrying. Counting drafts inflates the
  number a control function is asked for most often, with records that may
  never be reported at all.
- a **draft** *is* overdue if its target date has passed, and so is a
  **suspended** case. A deadline does not stop existing because the case is
  waiting on a police report. The point of the number is to show what has
  slipped.

§6.2 is the worked example: five cases, three of them drafts, two of those
past their target date → **Outstanding 0, Overdue 2**.

## Divergences found and resolved

Five, all resolved in the specification's favour, all previously untested:

| # | Was | Now | Why |
|---|---|---|---|
| 1 | Outstanding counted drafts (`open()`) | `outstanding()` excludes them | §4 is explicit, and drafts are not carried work |
| 2 | Overdue excluded suspended cases | `unfinished()` includes them | §4: "any non-completed, non-closed case". A suspension does not cancel a deadline |
| 3 | No **Archived** tile | added | §4 lists it; `base()` excludes archived from every other figure, so this one reaches past that scope |
| 4 | Recovery rate rounded to a whole number | one decimal | ₦2.1m ÷ ₦55m is **3.8%**; 4% overstates a recovery rate by a sixth |
| 5 | No **Estimated exposure** figure at all | added, leading the widget | §4 lists it first. It is a different number from confirmed loss — what the cases were opened fearing, against what they established. Showing only the second hides every investigation that turned out smaller than feared |

And one that follows from #1:

**Ageing now runs over the outstanding population, not the open one.** §6.2
expects "No open cases" on a register holding three drafts, which is only
true if a draft is not yet work sitting. Same reasoning as the Outstanding
tile, and the two now agree by construction: Outstanding 0 ↔ ageing empty.

Note this is the opposite treatment of *suspended* from the Overdue tile, and
deliberately so. Ageing asks **how long has work been sitting**, so a case
suspended pending a police report gets its own bucket and is left out of the
average. Overdue asks **what has slipped**, and a suspended case past its date
has.

## Not period-scoped, on purpose

Outstanding, Overdue, Archived, Open now and Suspended answer *"what is the
state of the register right now"*. A state-of-play number that changes with a
reporting window is a bug, not a feature —
`test_the_state_of_play_tiles_ignore_the_period` asserts they do not move.

Everything else re-queries on the range.
`test_every_period_scoped_widget_moves_with_the_range` drives the dashboard
at a period before anything happened and asserts each period-scoped figure
empties out, with the wide period asserted non-empty in the same test so the
assertions measure the filter and not an empty database.

## The activity timeline

Built to §4: **page size 20**, Previous / 1 / 2 / Next, and an "All activity
types" filter. Three things about it are deliberate:

- **It is period-scoped.** It previously was not — it returned the last
  fifteen events regardless of the range, so a dashboard filtered to one
  quarter showed another quarter's activity beneath its figures. That was the
  real defect here; pagination was only the visible half.
- **A page past the end clamps to the last page**, rather than showing an
  empty table with working paging. An unknown type falls back to all types for
  the same reason.
- **Changing the filter resets to page 1.** Filtering while on page 2 of an
  unfiltered set would otherwise land the reader on an empty page.

It carries the event **type** and the case reference, and deliberately not
§4's "— description". A diary line is free text and free text can name a
person: "Subject named: A. Teller" belongs on the case file and nowhere near a
dashboard. Carrying the type makes that leak structurally impossible rather
than dependent on how carefully somebody worded a title. `confidential_view`
never appears at all, and is not offered as a filter option — reading a
confidential file is oversight of the case, not news, and listing it would
advertise who is reading what.
