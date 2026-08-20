# Content pack verification backlog

Part D §D.7 sets the rule: before a pack ships as `verified`, someone obtains the
regulator's primary document, confirms every section number, date, threshold and
penalty against it, and records `verified_by` and `verified_at` on the pack.
Anything still unconfirmed stays `unverified` (or `draft`) and is excluded from
generated regulatory submissions by `ObligationService::submissionEligibleInstances()`.

D.7 closes by asking for an owner and a date against each open item. Those were
previously only prose inside each pack's `changelog`, which is not a list anyone
can work from. This file is that list. **Owner and target are unassigned — they
are the product owner's to set, not the content author's.**

Status of the whole estate as it ships: COSO IC 2013, COSO ERM 2017 and the eight
ISO 31000 principles are `verified`; every other international pack and every
Nigerian pack is `unverified`; every pan-African pack is `draft`.

## The 13 known-unverified research items

| # | Item | Pack(s) | What is unconfirmed | Primary document needed | Owner | Target |
|---|---|---|---|---|---|---|
| 1 | CBN cyber incident reporting | `CBN-RBCF-2022` | The notification window to the CBN (commonly cited as 24 hours) and the returns cadence and format | CBN Risk-Based Cybersecurity Framework, DMB/PSP and OFI variants — blocks automated fetch, download manually | — | — |
| 2 | CBN AML/CFT/CPF detail | `CBN-AML-2022` | Section numbers, retention periods, CTR thresholds, training frequency, STR filing window | CBN AML/CFT/CPF Regulations 2022 and TFS Guidelines 2022 | — | — |
| 3 | CBN returns schedule | *(no pack)* | The complete eFASS/FinA return schedule. Nothing is seeded from it; a pack cannot be authored until the schedule is in hand | CBN returns circulars | — | — |
| 4 | ISA 2025 renumbering | `SEC-NG-ICFR` | Whether ISA 2025 renumbers ss.60–63 and whether the SEC has reissued its ICFR guidance | Investments and Securities Act 2025; SEC circular of 8 November 2021 and any successor | — | — |
| 5 | PenCom risk management framework | `PENCOM` | The contents of the Guidelines for Risk Management Framework for Licensed Pension Operators. Obligations ship as draft shells | PenCom guideline — blocks automated fetch, download manually | — | — |
| 6 | NIIRA 2025 detail | `NAICOM-NIIRA-25` | Minimum capital by class of business, claims-handling service levels, penalties. Deliberately not seeded | Nigerian Insurance Industry Reform Act 2025 | — | — |
| 7 | CBK prudential guideline numbers | `KE-CBK-PG` | The individual CBK/PG reference numbers for the 2013 set | CBK Prudential Guidelines 2013 | — | — |
| 8 | King V | `ZA-KING-V-DRAFT` | Final publication date and the final principle count | King V, once published by the IoDSA | — | — |
| 9 | Basel III/IV status per jurisdiction | `CBN-BASEL-III`, `ZA-BANKS-ACT` | Which Basel III/IV components are in force in each jurisdiction and on what timetable | BIS RCAP jurisdictional profiles | — | — |
| 10 | NIST CSF 2.0 counts | `NIST-CSF-2.0` | The category and subcategory counts (commonly cited as 22 and 106). Functions and categories are seeded; **subcategories are not seeded at all** pending this | NIST CSWP 29 | — | — |
| 11 | Nigeria Tax Act 2025 and e-invoicing phases | `FIRS-EINV-2025` | Commencement of the Nigeria Tax Act 2025 and the Nigeria Tax Administration Act 2025, later taxpayer-band dates, the IRN/QR/UBL technical specification, penalties | FIRS circular of 9 July 2025 and the 2025 tax Acts | — | — |
| 12 | Survey-level African jurisdictions | *(no packs)* | Egypt, Morocco, Rwanda, Tanzania, Uganda, Zambia. Correctly **not** encoded: D.6 says these need dedicated research first | Per-jurisdiction primary law | — | — |
| 13 | Nigerian local-vendor market scan | *(not content)* | Commercial research, not a pack | — | — | — |

## Further items found by the Part D conformance audit

| # | Item | Pack(s) | What is unconfirmed | Owner | Target |
|---|---|---|---|---|---|
| 14 | CBN board committee count | `CBN-CG-2023` | The research lists a Board Risk Committee *and* a Board Risk Management Committee as separate mandatory committees. These are very likely the same body under two names; the guidance record carries a warning until it is settled | — | — |
| 15 | CAC annual return due date | `CAMA-CAC-TAX` | CAMA ties the recurring annual return to the AGM, not to a calendar date. `CAC-ANNUAL-RETURN` ships as a **draft shell** with a 30 June placeholder | — | — |
| 16 | Statutory registration windows | `CAMA-CAC-TAX` | The filing windows for NIPC, NOTAP and SCUML registration. All three ship as **draft shells** anchored on incorporation or execution with a zero offset | — | — |
| 17 | NSITF remittance deadline | `CAMA-CAC-TAX` | The Employee Compensation Act's remittance deadline. The 10-days-after-month-end rule in the pack is a working assumption | — | — |
| 18 | ISO/IEC 27001 Amendment 1:2024 | `ISO-27001-2022` | Amendment 1:2024 adds climate considerations to clauses 4.1 and 4.2 and is **not yet reflected** in the requirement tree | — | — |
| 19 | PCI DSS sub-requirements | `PCI-DSS-4.0.1` | Only the 12 top-level requirements and 6 goals are seeded; sub-requirements are not. Requirement titles also need reconciling against the published standard | — | — |
| 20 | Joint Standard 1 of 2023 effective date | `ZA-JOINT-STANDARD-1-2023` | The effective date in the pack is a working assumption | — | — |
| 21 | POCAMLA thresholds | `KE-POCAMLA` | Reporting thresholds, filing windows, retention periods and penalties. No obligations are seeded | — | — |
| 22 | ZA Banks Act detail | `ZA-BANKS-ACT` | Section numbers and the BA-return schedule. No obligations are seeded | — | — |

## Two packs that sit outside Part D

`CBN-BASEL-III` and `COSO-ICSR-2023` have no entry in the specification —
Basel appears only in D.7's unverified list, and COSO's Internal Control over
Sustainability Reporting supplement appears nowhere. Both ship `unverified` and
`draft` respectively. Either extend Part D to cover them or record why they are
carried outside it.

## What is deliberately not seeded

Authoring content that has not been confirmed is the failure mode D.7 exists to
prevent, so the following gaps are choices rather than oversights, and each is
tracked above: NIST CSF subcategories (item 10), PCI DSS sub-requirements
(item 19), NAICOM capital and claims service levels (item 6), the PenCom risk
management framework detail (item 5), the eFASS/FinA schedule (item 3), and the
six survey-level African jurisdictions (item 12).

Separately, most packs carry **no suggested controls**. `controls` is an
optional section of the pack format (D.1) and the specification does not state
control content for any framework, so writing them is new authoring work rather
than a verification task. Twelve control templates ship today, all in Nigerian
packs.
