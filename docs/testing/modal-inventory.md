# Modal, dialog and inline-form inventory

Spec §8.1. Every `<Modal>` block in `resources/js`, with the form bound to it,
the route it submits to, the table(s) that route writes, and whether a failed
submission tells the user anything.

Built by static analysis of the JSX → route → controller chain, checked by hand
wherever the analysis was ambiguous. The method, and the false positives it
produced on the way, are recorded in `investigations-test-report.md`.

## Headline

| | Count |
|---|---|
| Modal blocks | 109 |
| Carrying form fields | 102 |
| Confirm-only (no fields) | 7 |
| Wired to a submit | **109 / 109** |
| Persist to the database | **86 / 86 resolvable** |
| **Render no validation feedback** | **69** |

**No modal in this application fails to save.** Every one is wired to a submit,
and every route that could be resolved writes. (Six render their body through a
child component defined in the same file — `<TransferForm />` and the like — so
the automated pass could not see their fields; all six were opened and checked
by hand.) The reported symptom — "several
modals do not save" — is the last row: a form that 422s inside a dialog which
renders no `<InputError>` sits there unchanged and silent, which is
indistinguishable from a save that did nothing. See DEF-M01.

## Legend

- **Persists** — `Y` where the controller writes directly or delegates to a service that does; `—` where the dialog is a confirm with no payload.
- **Errors** — ✅ renders `<InputError>`; ⚠️ does not, and depended on the global surface added in DEF-M01.
- Fieldless confirm dialogs have nothing to validate and are marked —.

## Admin

| Screen | Modal | Fields | Route | Table(s) | Persists | Errors | Defect |
|---|---|---|---|---|---|---|---|
| Admin/DataSources/Index | Connector capability matrix | — | — | — | — | — | — |
| Admin/DataSources/Index | Register a data source | `auth_type`, `connection_config_text`, `credentials`, `data_residency_note`, `failure_threshold`, `name` +7 | `admin.data-sources.store` | `data_sources` | Y | ⚠️ | DEF-M01 |
| Admin/DataSources/Show | Add dataset | `description`, `extraction_config_text`, `incremental_field`, `is_active`, `name`, `note` +4 | `admin.data-sources.datasets.authorise-retention`, `admin.data-sources.datasets.store` | — | Y | ⚠️ | DEF-M01 |
| Admin/DataSources/Show | Authorise retention of sensitive personal data | `description`, `extraction_config_text`, `incremental_field`, `is_active`, `name`, `note` +4 | `admin.data-sources.datasets.authorise-retention`, `admin.data-sources.datasets.store` | — | Y | ⚠️ | DEF-M01 |
| Admin/EscalationMatrix | (untitled) | `channel`, `days_threshold`, `is_active`, `recipient_role`, `recipient_user_id`, `severity` +2 | `admin.escalation-matrix.store`, `admin.escalation-matrix.update` | `escalation_matrices` | Y | ⚠️ | DEF-M01 |
| Admin/EvidenceDisposal | New retention policy | `disposal_action`, `evidence_class`, `is_default`, `legal_basis_note`, `name`, `requires_dual_approval` +1 | `admin.retention-policies.store` | `retention_policys` | Y | ⚠️ | DEF-M01 |
| Admin/Sso | {editing === 'new' ? 'Add identity provider' : 'Propose changes'} | (14 inputs) | `admin.sso.store`, `admin.sso.update` | — | Y | ✅ | — |
| Admin/Users/Index | (untitled) | (11 inputs) | — | — | — | ✅ | — |

## Assurance

| Screen | Modal | Fields | Route | Table(s) | Persists | Errors | Defect |
|---|---|---|---|---|---|---|---|
| Assurance/Map | Record a reliance decision | `activity_name`, `assurance_type`, `code`, `conclusion`, `contact_email`, `contact_name` +15 | `assurance.activities.reliance`, `assurance.activities.store`, `assurance.providers.store` | `assurance_activitys`, `assurance_providers` | Y | ⚠️ | DEF-M01 |
| Assurance/Map | Record assurance coverage | `activity_name`, `assurance_type`, `code`, `conclusion`, `contact_email`, `contact_name` +15 | `assurance.activities.reliance`, `assurance.activities.store`, `assurance.providers.store` | `assurance_activitys`, `assurance_providers` | Y | ⚠️ | DEF-M01 |
| Assurance/Map | Register an assurance provider | `activity_name`, `assurance_type`, `code`, `conclusion`, `contact_email`, `contact_name` +15 | `assurance.activities.reliance`, `assurance.activities.store`, `assurance.providers.store` | `assurance_activitys`, `assurance_providers` | Y | ⚠️ | DEF-M01 |

## Cases

| Screen | Modal | Fields | Route | Table(s) | Persists | Errors | Defect |
|---|---|---|---|---|---|---|---|
| Cases/Index | Open a case | `access_user_ids`, `case_type`, `confidentiality`, `description`, `entity_id`, `is_anonymous` +3 | `cases.store` | — | Y | ⚠️ | DEF-M01 |
| Cases/Metadata | (untitled) | — | — | — | — | — | — |
| Cases/Show | Conclude the investigation | `actions_taken`, `conclusion`, `findings`, `outcome`, `referred_to` | `cases.conclude` | — | Y | ⚠️ | DEF-M01 |

## Complaints

| Screen | Modal | Fields | Route | Table(s) | Persists | Errors | Defect |
|---|---|---|---|---|---|---|---|
| Complaints/Index | Log a complaint | `assigned_to`, `category_id`, `channel`, `customer_contact`, `customer_name`, `customer_reference` +3 | `complaints.store` | — | Y | ⚠️ | DEF-M01 |
| Complaints/Show | Resolve the complaint | `customer_satisfied`, `linked_control_id`, `redress_amount_minor`, `redress_currency`, `resolution_summary`, `resolution_type` +1 | `complaints.resolve` | — | Y | ⚠️ | DEF-M01 |

## ControlFunctions

| Screen | Modal | Fields | Route | Table(s) | Persists | Errors | Defect |
|---|---|---|---|---|---|---|---|
| ControlFunctions/Show | Trigger an occurrence | `control_entity_id`, `reason` | `control-functions.trigger` | — | Y | ⚠️ | DEF-M01 |

## Controls

| Screen | Modal | Fields | Route | Table(s) | Persists | Errors | Defect |
|---|---|---|---|---|---|---|---|
| Controls/Distribution/Index | Distribution settings | (4 inputs) | — | — | — | ✅ | — |
| Controls/Distribution/Show | Request to decline this control | (1 inputs) | — | — | — | ⚠️ | DEF-M01 |
| Controls/Show | Return to draft | `reason` | `controls.reject` | — | Y | ⚠️ | DEF-M01 |

## ControlStructure

| Screen | Modal | Fields | Route | Table(s) | Persists | Errors | Defect |
|---|---|---|---|---|---|---|---|
| ControlStructure/Entity | (untitled) | `attachments` | `control-structure.entities.attach` | — | Y | ⚠️ | DEF-M01 |
| ControlStructure/Index | (untitled) | (5 inputs) | `control-structure.units.store`, `control-structure.units.update` | — | Y | ✅ | — |
| ControlStructure/Unit | (untitled) | (9 inputs) | `control-structure.entities.store`, `control-structure.entities.update` | — | Y | ✅ | — |

## Csa

| Screen | Modal | Fields | Route | Table(s) | Persists | Errors | Defect |
|---|---|---|---|---|---|---|---|
| Csa/Respond | Review response | (3 inputs) | — | — | — | ⚠️ | DEF-M01 |

## Documents

| Screen | Modal | Fields | Route | Table(s) | Persists | Errors | Defect |
|---|---|---|---|---|---|---|---|
| Documents/Index | Add document | (9 inputs) | — | — | — | ✅ | — |
| Documents/Index | New folder | (2 inputs) | — | — | — | ✅ | — |
| Documents/Show | Reject document | (1 inputs) | — | — | — | ⚠️ | DEF-M01 |
| Documents/Show | Upload new version | (2 inputs) | — | — | — | ⚠️ | DEF-M01 |

## Entities

| Screen | Modal | Fields | Route | Table(s) | Persists | Errors | Defect |
|---|---|---|---|---|---|---|---|
| Entities/Index | (untitled) | `note`, `user_id` | `entities.grants.store`, `entities.store` | — | Y | ✅ | — |
| Entities/Index | (untitled) | — | — | — | — | — | — |

## ExceptionManager

| Screen | Modal | Fields | Route | Table(s) | Persists | Errors | Defect |
|---|---|---|---|---|---|---|---|
| ExceptionManager/Show | Generate a secure answer link | `expires_at` | `exception-manager.links.store` | — | Y | ⚠️ | DEF-M01 |
| ExceptionManager/Show | Mark action completed | `note` | `exception-actions.complete` | — | Y | ⚠️ | DEF-M01 |
| ExceptionManager/Show | Record progress | `progress_note`, `progress_percentage` | `exception-actions.progress` | — | Y | ⚠️ | DEF-M01 |
| ExceptionManager/Show | Review the departmental response | `action_target_date`, `action_title`, `action_type`, `decision`, `rejection_reason`, `review_note` | `exception-manager.review` | — | Y | ✅ | — |
| ExceptionManager/Show | Validate the remediation and close | `closure_note`, `validation_status` | `exception-manager.close` | — | Y | ✅ | — |
| ExceptionManager/Show | Withdraw this escalation | `reason` | `exception-manager.withdraw` | — | Y | ✅ | — |

## Exceptions

| Screen | Modal | Fields | Route | Table(s) | Persists | Errors | Defect |
|---|---|---|---|---|---|---|---|
| Exceptions/Show | (untitled) | — | — | — | — | — | — |
| Exceptions/Show | Escalate to departments | `targets` | `exception-manager.issue` | — | Y | ✅ | — |

## Frameworks

| Screen | Modal | Fields | Route | Table(s) | Persists | Errors | Defect |
|---|---|---|---|---|---|---|---|
| Frameworks/Mappings | Reject this mapping? | (1 inputs) | — | — | — | ⚠️ | DEF-M01 |
| Frameworks/Soa | (untitled) | — | — | — | — | — | — |

## Improvements

| Screen | Modal | Fields | Route | Table(s) | Persists | Errors | Defect |
|---|---|---|---|---|---|---|---|
| Improvements/Index | Propose improvement | (10 inputs) | — | — | — | ✅ | — |

## Incidents

| Screen | Modal | Fields | Route | Table(s) | Persists | Errors | Defect |
|---|---|---|---|---|---|---|---|
| Incidents/Show | Raise an action | `action_type`, `description`, `due_at`, `is_mandatory`, `owner_id`, `title` | `incidents.actions.store` | — | Y | ⚠️ | DEF-M01 |
| Incidents/Show | Record the regulatory notification | `notes`, `notification_reference` | `incidents.notifications.record` | — | Y | ⚠️ | DEF-M01 |

## Investigations

| Screen | Modal | Fields | Route | Table(s) | Persists | Errors | Defect |
|---|---|---|---|---|---|---|---|
| Investigations/Report | (untitled) | `comment`, `to` | `investigations.reports.advance` | — | Y | ✅ | — |
| Investigations/Report | Return to the preparer | `returned_reason` | `investigations.reports.return` | — | Y | ✅ | — |
| Investigations/Show | (untitled) | `action`, `amount_recovered`, `implementation_note`, `rejection_reason` | `investigations.consequences.update` | — | Y | ✅ | — |
| Investigations/Show | Add a team member | `notes`, `role`, `user_id` | `investigations.team.store` | — | Y | ✅ | — |
| Investigations/Show | Archive the investigation | `archive_reason` | `investigations.archive` | — | Y | ✅ | — |
| Investigations/Show | Complete the investigation | `completed_date`, `conclusion`, `confirmed_financial_loss`, `risk_rating` | `investigations.complete` | — | Y | ✅ | — |
| Investigations/Show | Log a diary entry | `activity_date`, `activity_type`, `description`, `title` | `investigations.activities.store` | — | Y | ✅ | — |
| Investigations/Show | Move status | `note`, `status` | `investigations.status` | — | Y | ✅ | — |
| Investigations/Show | Name a subject | `account_number`, `department`, `name`, `notes`, `position`, `role_in_case` +3 | `investigations.subjects.store` | — | Y | ✅ | — |
| Investigations/Show | Raise remediation for {finding.reference} | `due_at`, `owner_id`, `priority`, `title` | `investigations.findings.improvement` | — | Y | ⚠️ | DEF-M01 |
| Investigations/Show | Recommend a consequence | `action_type`, `description`, `due_date`, `investigation_subject_id` | `investigations.consequences.store` | — | Y | ✅ | — |
| Investigations/Show | Record a finding of fact | `control_failure`, `control_id`, `description`, `financial_impact`, `recommendation`, `root_cause` +2 | `investigations.findings.store` | — | Y | ✅ | — |
| Investigations/Show | Record an outcome for {subject.name} | `account_number`, `department`, `name`, `notes`, `outcome`, `outcome_rationale` +5 | `investigations.subjects.update` | — | Y | ✅ | — |

## Metrics

| Screen | Modal | Fields | Route | Table(s) | Persists | Errors | Defect |
|---|---|---|---|---|---|---|---|
| Metrics/Index | New indicator | `aggregation`, `calculation_expression`, `category_id`, `code`, `currency`, `data_type` +13 | `metrics.store` | `metrics` | Y | ⚠️ | DEF-M01 |
| Metrics/Show | (untitled) | `action_taken`, `comment`, `period_label`, `root_cause`, `target`, `value` | `metrics.breaches.acknowledge`, `metrics.capture` | — | Y | ⚠️ | DEF-M01 |
| Metrics/Show | Acknowledge breach | `action_taken`, `comment`, `period_label`, `root_cause`, `target`, `value` | `metrics.breaches.acknowledge`, `metrics.capture` | — | Y | ⚠️ | DEF-M01 |

## Monitoring

| Screen | Modal | Fields | Route | Table(s) | Persists | Errors | Defect |
|---|---|---|---|---|---|---|---|
| Monitoring/Findings/Index | (untitled) | `finding_ids`, `review_notes`, `status` | `monitoring-findings.bulk-review`, `monitoring-findings.review` | — | Y | ⚠️ | DEF-M01 |
| Monitoring/Findings/Index | Review finding | `finding_ids`, `review_notes`, `status` | `monitoring-findings.bulk-review`, `monitoring-findings.review` | — | Y | ⚠️ | DEF-M01 |
| Monitoring/Rules/Index | New monitoring rule | `auto_create_exception`, `auto_create_incident`, `control_id`, `dataset_ids`, `description`, `frequency` +6 | `monitoring-rules.store` | — | Y | ⚠️ | DEF-M01 |
| Monitoring/Rules/Show | (untitled) | `reason` | — | — | — | ⚠️ | DEF-M01 |

## Objectives

| Screen | Modal | Fields | Route | Table(s) | Persists | Errors | Defect |
|---|---|---|---|---|---|---|---|
| Objectives/Index | New strategic objective | `code`, `description`, `entity_id`, `measures`, `owner_id`, `parent_objective_id` +7 | `objectives.store` | `objectives` | Y | ⚠️ | DEF-M01 |
| Objectives/Show | Add a measure | `baseline_value`, `budget_minor`, `currency`, `description`, `end_date`, `metric_id` +11 | `initiatives.store`, `objectives.measures.store`, `objectives.progress` | `initiatives` | Y | ⚠️ | DEF-M01 |
| Objectives/Show | New initiative | `baseline_value`, `budget_minor`, `currency`, `description`, `end_date`, `metric_id` +11 | `initiatives.store`, `objectives.measures.store`, `objectives.progress` | `initiatives` | Y | ⚠️ | DEF-M01 |
| Objectives/Show | Report progress | `baseline_value`, `budget_minor`, `currency`, `description`, `end_date`, `metric_id` +11 | `initiatives.store`, `objectives.measures.store`, `objectives.progress` | `initiatives` | Y | ⚠️ | DEF-M01 |

## Obligations

| Screen | Modal | Fields | Route | Table(s) | Persists | Errors | Defect |
|---|---|---|---|---|---|---|---|
| Obligations/InstanceShow | Record the submission | `acknowledgement_ref`, `notes`, `submission_reference` | `obligations.instances.submit` | — | Y | ✅ | — |
| Obligations/InstanceShow | Waive this filing | (1 inputs) | — | — | — | ⚠️ | DEF-M01 |
| Obligations/Show | Assign this obligation | `entity_id`, `obligation_id`, `owner_id`, `reviewer_id` | `obligation-assignments.store` | `obligation_assignments` | Y | ⚠️ | DEF-M01 |
| Obligations/Show | Declare this obligation not applicable | (1 inputs) | — | — | — | ⚠️ | DEF-M01 |

## Policies

| Screen | Modal | Fields | Route | Table(s) | Persists | Errors | Defect |
|---|---|---|---|---|---|---|---|
| Policies/Show | Request a policy waiver | `compensating_measures`, `justification`, `requested_from`, `requested_to`, `risk_assessment` | `policies.exceptions.store` | — | Y | ⚠️ | DEF-M01 |

## RegulatoryChanges

| Screen | Modal | Fields | Route | Table(s) | Persists | Errors | Defect |
|---|---|---|---|---|---|---|---|
| RegulatoryChanges/Index | Log a regulatory publication | `document_url`, `effective_at`, `published_at`, `reference`, `regulator_id`, `summary` +1 | `regulatory-changes.store` | — | Y | ⚠️ | DEF-M01 |
| RegulatoryChanges/Index | Record the impact | `impact_assessment` | `regulatory-changes.assess` | — | Y | ⚠️ | DEF-M01 |

## Residency

| Screen | Modal | Fields | Route | Table(s) | Persists | Errors | Defect |
|---|---|---|---|---|---|---|---|
| Residency/Index | Record a cross-border transfer | — | — | — | — | — | — |

## Risks

| Screen | Modal | Fields | Route | Table(s) | Persists | Errors | Defect |
|---|---|---|---|---|---|---|---|
| Risks/Appetite | New appetite statement | `appetite_level`, `capacity`, `effective_from`, `entity_id`, `metric_definition`, `review_due_at` +4 | `appetite.store` | — | Y | ⚠️ | DEF-M01 |
| Risks/Index | New risk | `category`, `description`, `description_rich`, `entity_id`, `inherent_impact`, `inherent_likelihood` +7 | `risks.store` | `risks` | Y | ✅ | — |
| Risks/Show | Propose a treatment plan | `alert_lead_days`, `alerts_enabled`, `assessment_type`, `benefit_description`, `benefit_description_rich`, `confidence` +23 | `risks.assessments.store`, `risks.treatments.store` | — | Y | ⚠️ | DEF-M01 |
| Risks/Show | Record a risk assessment | `alert_lead_days`, `alerts_enabled`, `assessment_type`, `benefit_description`, `benefit_description_rich`, `confidence` +23 | `risks.assessments.store`, `risks.treatments.store` | — | Y | ⚠️ | DEF-M01 |

## Settings

| Screen | Modal | Fields | Route | Table(s) | Persists | Errors | Defect |
|---|---|---|---|---|---|---|---|
| Settings/ExceptionRouting | (untitled) | (11 inputs) | `admin.exception-routing.store`, `admin.exception-routing.update` | `exception_routing_rules` | Y | ✅ | — |
| Settings/ExceptionSla | (untitled) | (9 inputs) | `admin.exception-sla.store`, `admin.exception-sla.update` | `exception_sla_policys` | Y | ✅ | — |

## Shared components

| Screen | Modal | Fields | Route | Table(s) | Persists | Errors | Defect |
|---|---|---|---|---|---|---|---|
| Components/AiReviewPanel | Suggestion for your review | (2 inputs) | — | — | — | ⚠️ | DEF-M01 |
| Components/ConfirmDialog | (untitled) | — | — | — | — | — | — |
| Components/EvidencePanel | Upload evidence | `classification`, `contains_personal_data`, `file`, `linked_id`, `linked_type`, `personal_data_categories` | `evidence.store` | — | Y | ✅ | — |

## Sod

| Screen | Modal | Fields | Route | Table(s) | Persists | Errors | Defect |
|---|---|---|---|---|---|---|---|
| Sod/Conflicts | (untitled) | `conflict_type`, `description`, `function_a`, `function_b`, `is_active`, `mitigating_control_id` +3 | `sod.conflicts.store`, `sod.conflicts.update` | `sod_conflict_rules` | Y | ⚠️ | DEF-M01 |
| Sod/Violations | (untitled) | `accepted_until`, `mitigating_control_id`, `notes` | — | — | — | ⚠️ | DEF-M01 |

## SpotChecks

| Screen | Modal | Fields | Route | Table(s) | Persists | Errors | Defect |
|---|---|---|---|---|---|---|---|
| SpotChecks/Show | (untitled) | (9 inputs) | `spot-checks.findings.store`, `spot-checks.findings.update` | — | Y | ✅ | — |

## Submissions

| Screen | Modal | Fields | Route | Table(s) | Persists | Errors | Defect |
|---|---|---|---|---|---|---|---|
| Submissions/Index | Generate a return | `entity_id`, `obligation_instance_id`, `pack_type`, `period_label` | `submissions.store` | — | Y | ⚠️ | DEF-M01 |

## Sustainability

| Screen | Modal | Fields | Route | Table(s) | Persists | Errors | Defect |
|---|---|---|---|---|---|---|---|
| Sustainability/Filings | (untitled) | (2 inputs) | `sustainability.filings.stages.submit`, `sustainability.filings.store`, `sustainability.filings.verify` | — | Y | ⚠️ | DEF-M01 |
| Sustainability/Filings | Confirm the filing deadlines | (1 inputs) | `sustainability.filings.stages.submit`, `sustainability.filings.store`, `sustainability.filings.verify` | — | Y | ⚠️ | DEF-M01 |
| Sustainability/Filings | Plan a filing | (7 inputs) | `sustainability.filings.stages.submit`, `sustainability.filings.store`, `sustainability.filings.verify` | — | Y | ⚠️ | DEF-M01 |
| Sustainability/Ghg | Capture an emissions figure | `activity_data`, `activity_unit`, `category`, `control_id`, `data_quality`, `emission_factor` +10 | `sustainability.ghg.defer`, `sustainability.ghg.store`, `sustainability.ghg.verify` | `ghg_data_points` | Y | ⚠️ | DEF-M01 |
| Sustainability/Ghg | Defer under the transition reliefs | `activity_data`, `activity_unit`, `category`, `control_id`, `data_quality`, `emission_factor` +10 | `sustainability.ghg.defer`, `sustainability.ghg.store`, `sustainability.ghg.verify` | `ghg_data_points` | Y | ⚠️ | DEF-M01 |
| Sustainability/Ghg | Verify the figure | `activity_data`, `activity_unit`, `category`, `control_id`, `data_quality`, `emission_factor` +10 | `sustainability.ghg.defer`, `sustainability.ghg.store`, `sustainability.ghg.verify` | `ghg_data_points` | Y | ⚠️ | DEF-M01 |
| Sustainability/Index | (untitled) | `basis`, `entity_id`, `financial_materiality_score`, `impact_materiality_score`, `is_material`, `period_label` +3 | `sustainability.materiality.store` | `materiality_assessments` | Y | ⚠️ | DEF-M01 |

## TestInstances

| Screen | Modal | Fields | Route | Table(s) | Persists | Errors | Defect |
|---|---|---|---|---|---|---|---|
| TestInstances/Show | Rate control effectiveness | `design_effectiveness`, `design_rationale`, `design_rationale_rich`, `operating_effectiveness`, `operating_rationale`, `operating_rationale_rich` | `test-instances.rate` | — | Y | ✅ | — |
| TestInstances/Show | Reopen locked test | `reason` | `test-instances.reopen` | — | Y | ⚠️ | DEF-M01 |
| TestInstances/Show | Return to tester | `approved`, `notes` | — | — | — | ⚠️ | DEF-M01 |
| TestInstances/Show | Submit for review | `population_size`, `sample_items`, `sample_size`, `sampling_method` | `test-instances.submit` | — | Y | ✅ | — |

## Treatments

| Screen | Modal | Fields | Route | Table(s) | Persists | Errors | Defect |
|---|---|---|---|---|---|---|---|
| Treatments/Show | (untitled) | `acceptance_expiry`, `acceptance_reason`, `action`, `due_at`, `note`, `owner_id` +3 | `treatments.approve`, `treatments.milestones.store`, `treatments.progress`, `treatments.verify` | — | Y | ⚠️ | DEF-M01 |
| Treatments/Show | Add milestone | `acceptance_expiry`, `acceptance_reason`, `action`, `due_at`, `note`, `owner_id` +3 | `treatments.approve`, `treatments.milestones.store`, `treatments.progress`, `treatments.verify` | — | Y | ⚠️ | DEF-M01 |
| Treatments/Show | Verify treatment | `acceptance_expiry`, `acceptance_reason`, `action`, `due_at`, `note`, `owner_id` +3 | `treatments.approve`, `treatments.milestones.store`, `treatments.progress`, `treatments.verify` | — | Y | ⚠️ | DEF-M01 |

## Vendors

| Screen | Modal | Fields | Route | Table(s) | Persists | Errors | Defect |
|---|---|---|---|---|---|---|---|
| Vendors/Index | Register a third party | `annual_spend_minor`, `category`, `criticality`, `data_access_classification`, `entity_id`, `jurisdiction` +13 | `vendors.store` | `vendors` | Y | ⚠️ | DEF-M01 |
| Vendors/Show | Disposition the screening hit | (3 inputs) | `vendors.assessments.store`, `vendors.contracts.store`, `vendors.notification.store`, `vendors.screenings.disposition`, `vendors.screenings.store` | `vendor_assessments`, `vendor_contracts` | Y | ⚠️ | DEF-M01 |
| Vendors/Show | Open a due-diligence assessment | (7 inputs) | `vendors.assessments.store`, `vendors.contracts.store`, `vendors.notification.store`, `vendors.screenings.disposition`, `vendors.screenings.store` | `vendor_assessments`, `vendor_contracts` | Y | ⚠️ | DEF-M01 |
| Vendors/Show | Record a screening | (6 inputs) | `vendors.assessments.store`, `vendors.contracts.store`, `vendors.notification.store`, `vendors.screenings.disposition`, `vendors.screenings.store` | `vendor_assessments`, `vendor_contracts` | Y | ⚠️ | DEF-M01 |
| Vendors/Show | Record the regulator notification | (3 inputs) | `vendors.assessments.store`, `vendors.contracts.store`, `vendors.notification.store`, `vendors.screenings.disposition`, `vendors.screenings.store` | `vendor_assessments`, `vendor_contracts` | Y | ⚠️ | DEF-M01 |
| Vendors/Show | Register a contract | (12 inputs) | `vendors.assessments.store`, `vendors.contracts.store`, `vendors.notification.store`, `vendors.screenings.disposition`, `vendors.screenings.store` | `vendor_assessments`, `vendor_contracts` | Y | ⚠️ | DEF-M01 |
| Vendors/Show | Return to the assessor | (1 inputs) | `vendors.assessments.reject` | — | Y | ⚠️ | DEF-M01 |

---

## DEF-M01 — a failed submission says nothing

**69 modals carry form fields and render no `<InputError>` anywhere.**

`FlashNotification` — the only global message surface — reads `flash.success`,
`flash.error`, `flash.warning` and `flash.info`. Those are *session* messages the
server chose to send. A validation failure sends none of them: Laravel returns
422 and Inertia puts the messages in `page.props.errors`, which nothing in the
application was reading.

So a user fills in a dialog, presses Save, and the dialog stays open with no
message and no change. Nothing is written and nothing is said. Reported as "the
modal does not save"; in fact the modal submitted, the server refused, and the
refusal was discarded.

**Fixed in** `resources/js/Components/ValidationNotification.jsx`, mounted in
`AuthenticatedLayout` beside `FlashNotification`. It reads the errors bag and
surfaces the messages. It is a net, not a replacement: where a form renders
`<InputError>` beside the offending field, that inline message is the better one
and stays.

**Still open — the inline pass.** The global surface guarantees a user is never
left in silence, but it does not point at the offending field. The ⚠️ rows above
are the worklist; longest forms first, because a 19-field dialog is where a
corner toast helps least:

- `Vendors/Index` — Register a third party (19 inputs)
- `Metrics/Index` — New indicator (18 inputs)
- `Objectives/Index` — New strategic objective (16 inputs)
- `Assurance/Map` — Record assurance coverage (13 inputs)
- `Monitoring/Rules/Index` — New monitoring rule (12 inputs)
- `Vendors/Show` — Register a contract (12 inputs)
- `Sustainability/Ghg` — Capture an emissions figure (11 inputs)
- `Admin/DataSources/Index` — Register a data source (10 inputs)
- `Objectives/Show` — New initiative (10 inputs)
- `Risks/Appetite` — New appetite statement (10 inputs)
- `Risks/Show` — Propose a treatment plan (10 inputs)
- `Risks/Show` — Record a risk assessment (10 inputs)
- `Cases/Index` — Open a case (9 inputs)
- `Sod/Conflicts` — (untitled) (9 inputs)
- `Admin/EscalationMatrix` — (untitled) (8 inputs)
