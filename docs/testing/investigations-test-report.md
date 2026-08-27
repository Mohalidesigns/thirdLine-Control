# Modal & persistence audit — findings

Spec §8. Companion to `modal-inventory.md`, which carries the full table.
This is what the audit did, what it found, and what remains open.

## Result in one line

Every one of the **109 modals in the application is wired and saves**. Two real
defects were found, both of which present to a user as "the modal does not
save" without actually being that:

| ID | Defect | Scope | Status |
|---|---|---|---|
| DEF-M01 | A failed submission produces no message anywhere | 69 modals | Fixed |
| DEF-M02 | The Add-subject dialog could never save | Investigations | Fixed |

## Method, and what it cost

Hand-inspecting 109 modals was not going to be reliable, so the JSX → route →
controller chain was analysed statically and every hit was then confirmed by
reading the code. Four detectors were run:

| Detector | Raw hits | Real |
|---|---|---|
| Route names used in JS that do not exist server-side | 0 | 0 |
| Form fields submitted that no validation rule accepts | 3 | 0 |
| Required rules the form never sends | 2 | 0 |
| Modals with fields but no submit wiring | 4 | 0 |
| Modals with fields and no `<InputError>` | 69 | **69** |

**The false positives are worth recording, because each was a flaw in the
analysis and not in the application.** Anyone re-running this should expect
them:

- **`transform()` rewrites the payload.** `connection_config_text` is parsed to
  JSON and submitted as `connection_config`; `frameworks.import` replaces the
  whole body with a `FormData` carrying `pack`. A field-name diff cannot see
  this.
- **Validation factored into a private helper.** `EscalationMatrixController`
  validates in `validateRule()`, shared by store and update. Following
  `$this->` delegation one level cleared it.
- **Rules whose requiredness is conditional.** `change_reason` is
  `required` on PUT and `nullable` on POST, written as a ternary. A grep for
  `'required'` sees only the literal.
- **Shadowed variable names.** Five components in `Vendors/Show.jsx` each
  declare `const form`. A file-wide search binds all five to all five routes;
  it needs per-scope search, and a word boundary, or `form.post` matches
  `contractForm.post`.
- **Keys not at the start of a line.** The first key extractor was
  line-anchored, so `{ subject_type: 'staff', name: '', ... }` yielded one key
  out of eleven. This one mattered: it silently halved the field coverage of
  the first two detectors, and both were re-run after it was fixed.
- **Dialogs that do not use `useForm`.** Several submit through `router.post`
  with plain `useState`; six more render their body through a child component
  in the same file. All were checked by hand.

## DEF-M01 — a failed submission says nothing

**69 modals carry form fields and render no `<InputError>` anywhere.**

`FlashNotification`, the only global message surface, reads `flash.success`,
`flash.error`, `flash.warning` and `flash.info` — *session* messages the server
chose to send. A validation failure sends none of them. Laravel returns 422 and
Inertia puts the messages in `page.props.errors`, and nothing in the
application was reading that.

So: fill in a dialog, press Save, and the dialog stays open, unchanged, with no
message. Nothing written, nothing said. That is indistinguishable from a save
that did nothing, which is how it was reported.

**Fixed** by `resources/js/Components/ValidationNotification.jsx`, mounted in
`AuthenticatedLayout` beside `FlashNotification`. It reads the errors bag and
surfaces the messages, keyed on the error set so dismissing one failure does not
suppress the next.

It is a net, not a replacement. Where a form renders `<InputError>` beside the
offending field, that inline message is the better one and stays.

It is mounted in `GuestLayout` as well. The four `Auth/*` screens and the two
public forms already render `<InputError>`, but `Whistleblowing/Status` — where
a reporter checks their own case by token — did not, and a bad token produced
the same silence. `Csa/QuestionnaireBuilder` has no layout of its own; it renders
inside a page that does.

**Still open:** the inline pass over the 69. The net guarantees a user is never
left in silence; it does not point at the field. Worklist in the inventory,
longest forms first — a 19-field dialog is where a corner toast helps least.

## DEF-M02 — the Add-subject dialog could never save

`InvestigationSubjectRequest` is shared by store and update. Its rule

```php
'outcome_rationale' => ['nullable', 'required_unless:outcome,pending', ...]
```

is written for the update path, where the outcome dialog posts the whole
subject back including its outcome. **The create dialog has no outcome field at
all** — an outcome is recorded later, from a different dialog. So `outcome`
arrived absent, `required_unless` saw a value that was not `pending`, and
demanded a rationale for an outcome nobody had reached.

Every attempt to name a subject on an investigation returned 422. Combined with
DEF-M01 the dialog simply sat there.

**Why no test caught it:** every existing test adds subjects through
`InvestigationService::addSubject()`. The service is correct. Nothing exercised
the HTTP path the dialog actually uses — which is the argument for §8.2 asking
for tests through the real route.

**Fixed** with `prepareForValidation()` defaulting an absent `outcome` to
`pending`, the column's own default, so the two paths agree while the pairing
rule stays intact for the dialog that genuinely records an outcome.

## Tests added

`tests/Feature/InvestigationModalPersistenceTest.php` — 13 tests over the
eleven dialogs on the investigation case file, to the §8.2 standard: valid
submit asserted field by field, invalid submit asserted to populate the errors
bag and write nothing, unauthorised actor refused with nothing written, and the
diary entry where one is expected.

Both defects were verified by reverting the fix and watching the test fail.

Three of the first run's failures were the test being wrong rather than the
code, and are recorded because each is a real property of this codebase:

- **Assigning a team member returns 403 for the head of function.** The policy
  requires the actor to already be on the case: only someone trusted with it may
  widen who sees it.
- **An outsider gets 404, not 403.** Visibility is a global scope, so
  route-model binding fails before the permission middleware is reached. An
  outsider is not told the case exists.
- **Archive refuses a live case** before it ever reaches validation — only a
  completed or closed case may be archived.

## Verified in the browser

DEF-M01 is a UI defect, so a feature test cannot prove the fix. It was
confirmed live against the dev database, on one of the 69 affected dialogs —
Escalation Matrix → Add rule, eight fields, no `<InputError>` anywhere:

1. Opened the dialog and set `days_threshold` to 999 (`between:0,365`).
2. Submitted.
3. **Before:** the dialog closed and the row never appeared. No message. The
   worst version of the symptom — it looked like it had worked.
4. **After:** `This could not be saved — The days threshold field must be
   between 0 and 365.`
5. `EscalationMatrix::where('days_threshold', 999)->count()` → `0`. The refusal
   was correct all along; it was simply never shown.

The same session confirmed the §7 list-column work renders: **Days open** shows
`65d·` frozen against the completed case while live ones accrue (§7.6), and
**Financial impact** prints its basis — `CONFIRMED` on INV-2026-002, `ESTIMATED`
elsewhere (§7.3).

## Coverage, honestly

- **Feature tests through the real route: the 11 investigation dialogs.** The
  other 98 modals are covered by static analysis and by reading the code, not by
  tests. §8.2 asks for a test per modal; that is a much larger piece of work and
  is not done.
- **Browser tests: none.** §8.2 also asks for a Dusk/Playwright test per modal
  asserting the parent list refreshes without a reload. Not started. The
  "saves but does not refresh its parent" failure mode is therefore **unmeasured**
  — no claim is made about it either way.
- The §8.3 suspects were checked statically: no enum select submits a label
  instead of a value, and no submit handler swallows a 422 (they did not handle
  it at all, which is DEF-M01). CSRF/session expiry on long-open modals and
  nested-modal context loss were **not** tested.
