# Editor.js conversion register

Spec §9. Every field converted from a plain textarea to the shared
Editor.js component, its table and column, whether a data migration was
needed, and the test that holds it.

## The storage shape (unchanged from the first pass)

The document goes in `{field}_rich`; the ORIGINAL column stays as a derived
plain mirror that `HasRichText` repopulates on every save. Search, filters,
exports and report generation keep reading the column they always read, so
nothing downstream needs to know the editor exists.

**No data migration was needed for any field.** `_rich` starts NULL on every
existing row. `RichTextEditor.toDoc()` turns legacy plain text into paragraph
blocks when the editor loads, and the first save persists the document. A row
nobody edits keeps working forever on the plain column alone — asserted by
`RichTextSecondPassTest::test_a_legacy_plain_row_is_untouched_and_still_readable`.

## Converted in this pass

39 columns across 30 tables.

| Table | Column | Model | Screen(s) | Data migration |
|---|---|---|---|---|
| `cases` | `description` | SpeakUpCase | Cases/Index | none needed |
| `complaints` | `description` | Complaint | Complaints/Index | none needed |
| `complaints` | `resolution_summary` | Complaint | Complaints/Show | none needed |
| `consequence_actions` | `implementation_note` | ConsequenceAction | Investigations/Show | none needed |
| `consequence_actions` | `rejection_reason` | ConsequenceAction | Investigations/Show | none needed |
| `cross_border_transfers` | `description` | CrossBorderTransfer | Residency/Index | none needed |
| `cross_border_transfers` | `lawful_basis_note` | CrossBorderTransfer | Residency/Index | none needed |
| `data_sources` | `data_residency_note` | DataSource | Admin/DataSources/Index | none needed |
| `entity_links` | `notes` | EntityLink | Incidents/Show | none needed |
| `exception_escalations` | `closure_note` | ExceptionEscalation | ExceptionManager/Show | none needed |
| `frameworks` | `description` | Framework | Frameworks/Create | none needed |
| `initiatives` | `description` | Initiative | Objectives/Show | none needed |
| `investigation_subjects` | `outcome_rationale` | InvestigationSubject | Investigations/Show | none needed |
| `investigations` | `archive_reason` | Investigation | Investigations/Show | none needed |
| `materiality_assessments` | `rationale` | MaterialityAssessment | Sustainability/Index | none needed |
| `metric_breaches` | `action_taken` | MetricBreach | Metrics/Show | none needed |
| `metric_values` | `comment` | MetricValue | Metrics/Show | none needed |
| `monitoring_findings` | `review_notes` | MonitoringFinding | Monitoring/Findings/Index | none needed |
| `monitoring_rules` | `description` | MonitoringRule | Monitoring/Rules/Index | none needed |
| `objective_metrics` | `note` | ObjectiveMetric | Objectives/Show | none needed |
| `objectives` | `description` | Objective | Objectives/Index | none needed |
| `obligation_instances` | `notes` | ObligationInstance | Obligations/InstanceShow | none needed |
| `policy_exceptions` | `compensating_measures` | PolicyException | Policies/Show | none needed |
| `policy_exceptions` | `justification` | PolicyException | Policies/Show | none needed |
| `regulatory_changes` | `impact_assessment` | RegulatoryChange | RegulatoryChanges/Index | none needed |
| `regulatory_changes` | `summary` | RegulatoryChange | RegulatoryChanges/Index | none needed |
| `risk_appetites` | `metric_definition` | RiskAppetite | Risks/Appetite | none needed |
| `risk_appetites` | `statement` | RiskAppetite | Risks/Appetite | none needed |
| `risk_assessments` | `impact_rationale` | RiskAssessment | Risks/Show | none needed |
| `risk_assessments` | `likelihood_rationale` | RiskAssessment | Risks/Show | none needed |
| `risk_treatments` | `acceptance_reason` | RiskTreatment | Treatments/Show | none needed |
| `risk_treatments` | `verification_notes` | RiskTreatment | Treatments/Show | none needed |
| `sod_conflict_rules` | `description` | SodConflictRule | Sod/Conflicts | none needed |
| `sustainability_filing_stages` | `note` | SustainabilityFilingStage | Sustainability/Filings, Sustainability/Ghg | none needed |
| `sustainability_filings` | `verification_note` | SustainabilityFiling | Sustainability/Filings | none needed |
| `vendor_assessments` | `conclusion` | VendorAssessment | Vendors/Show | none needed |
| `vendor_screenings` | `disposition` | VendorScreening | Vendors/Show | none needed |
| `vendor_screenings` | `summary` | VendorScreening | Vendors/Show | none needed |
| `vendors` | `services_provided` | Vendor | Vendors/Index | none needed |

## Converted in the first pass (2026-08-20)

Eleven tables, already live before this sweep: `controls`, `control_exceptions`,
`incidents`, `risks`, `spot_checks`, `test_scripts`, `compensating_controls`,
`risk_treatments`, `effectiveness_ratings`, `improvement_actions`,
`exception_responses` — plus `control_units`, `control_entities`,
`control_stakeholders` (CR-02) and `investigations`, `investigation_findings`
(CR-04).

## Deliberately NOT converted

The specification asks for every textarea in the application. These hold
something that is not prose, and a block editor would corrupt them. Each is a
conflict raised rather than silently decided, per §12.

| Where | Field | Why it stays a textarea |
|---|---|---|
| `sso_configurations` | `x509_cert` | A PEM certificate. Byte-exact or the IdP handshake fails. |
| `ai_prompts` | `system_prompt`, `user_template` | LLM templates with placeholders; block markup would reach the model verbatim. |
| `tenant_brandings` | `report_header_html`, `report_footer_html` | Raw HTML for the PDF masthead. |
| `data_sources` | connection / extraction config | JSON, parsed client-side before submit and validated as a config object. |
| `monitoring_rules` | rule definition | The rule DSL. |
| `test_instances` | `sample_items` | Newline-separated identifiers, split server-side. |
| Reports → Designer | section body | Report-definition config, not narrative. |
| Csa / Submissions / Mobile | questionnaire answers | Free-text answers to generated questions, stored as generic values. Mobile is the offline surface, where a 96 KB editor bundle is a regression. |
| Whistleblowing (public) | reporter narrative | An anonymous public form. Left deliberately plain — see below. |

**The whistleblowing exception is the one worth a second look.** A block editor
on an anonymous public page adds a large bundle and a richer fingerprinting
surface to the one form in this product where the reporter's anonymity is
load-bearing and tested. It is a judgement call, not a technical block —
flagging it rather than deciding it.

## Still outstanding

Of 124 textarea instances in the application, **41 were converted** — the ones
that resolve to a concrete narrative column on a model. The rest split into:

- the exclusions above;
- **transient action inputs** — a rejection reason, a diary note, a
  return-to-preparer comment — which are passed to a service and land in an
  audit row or a positional argument rather than a narrative column. Converting
  these means adding a column per input and widening service signatures, and is
  not attempted here.

Swapping the component without the `_rich` column behind it would render an
editor, accept a table, and silently keep only the flattened text on save. That
is worse than a plain textarea, so it was not done anywhere in this pass.

## Tests

`tests/Feature/RichTextSecondPassTest.php`:

- every converted column exists (126 assertions across all 39);
- every one is fillable and cast to `array` — a missing cast round-trips the
  document as a JSON string;
- a document containing a **list and a table** round-trips to the database and
  derives its plain mirror (§9 requires both to reach the PDF);
- a legacy plain row is untouched and still readable;
- a malformed value is discarded rather than stored — blocks are user input and
  end up in generated PDFs.
