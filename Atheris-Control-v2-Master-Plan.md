# Atheris Control v2.0 — Master Plan
## From "SecondLine control testing platform" to Africa's leading Internal Control & GRC system

**Document:** Strategic + technical plan for the v2.0 update
**Baseline:** Atheris Control (SecondLine), BRD v1.0 Phases 0–6 delivered
**Target:** Corporater-parity GRC suite + Africa-first differentiators + full Anthropic AI layer
**Date:** 7 August 2026
**Companion document:** `02-CLAUDE-CODE-PROMPTS.md` (the executable build prompts)

---

## 0. Executive summary

Atheris Control today is a genuinely good **second-line control testing and exception management platform**. It has the hard parts right: maker–checker, segregation of duties with no admin bypass, an immutable audit trail, a configurable rating matrix, evidence retention with legal hold and dual-approval disposal, and a working integration layer with an OpenAPI spec. That foundation is worth more than it looks — most GRC products never enforce SoD properly.

What it is not yet is a **GRC platform**. Corporater sells eight things Atheris does not have at all (policy management, incident management, surveys/CSA, KRI/KPI metrics, strategy alignment, document management, SSO, dashboard configurability) and does five things Atheris does partially (control libraries at group *and* entity level, control distribution, risk heatmaps, data visualisation, report formats).

The strategy is not to out-build Corporater on generic capability. Corporater has a 20-year head start on platform configurability and will win any feature-count comparison in the long run. The strategy is:

> **Build Corporater-class capability, then win on the thing no global vendor will ever build: shipped, maintained, regulator-shaped Nigerian and African compliance content — running inside the country's borders, priced in naira, on a phone, over 4G.**

Every global GRC platform ships with SOX, GDPR, NIST and ISO content. **None ships CBN's 2023 Corporate Governance Guidelines, the FRC's 28-principle NCCG return (FRC/CG/001), the FRC's 2024 ICFR guidance, NDPC GAID 2025's Compliance Audit Return, NDIC's DPAS management factors, or CBN's returns calendar.** Nigerian buyers currently pay a global vendor six figures in USD and then pay a Big 4 firm again to build that content by hand. That double-payment is the wedge.

Two hard deadlines make this urgent and defensible:

- **CBN Circular PSS/DIR/PUB/CIR/001/004 (15 June 2026)** — all payment transaction data generated in Nigeria must be stored and processed in Nigeria from **1 January 2027**. Offshore multi-tenant SaaS becomes structurally non-compliant for a large slice of the buyer base. Atheris's existing **branch-per-client deployment model is already the compliant architecture** — that is an accident of good design that should now be marketed as the primary moat.
- **Bank of Ghana's new Cyber & Information Security Directive (launched 15 April 2026)** requires core systems and customer data to remain in Ghana. Zambia and Rwanda have localisation provisions. Data residency is becoming the African norm, not the exception.

---

## 1. Where Atheris Control stands today

### 1.1 What exists (verified in the codebase, 7 Aug 2026)

| Layer | Present |
|---|---|
| Stack | Laravel 13 · Inertia · React 18 · Tailwind 3 · Spatie Permission · Ziggy · MySQL · dompdf · PhpSpreadsheet |
| Tenancy | `BelongsToTenant` global scope, branch-per-client deployment, `tenant_id` on every domain table |
| RBAC | 7 roles, `"<verb> <resource>"` permissions, four enforcement layers (route → controller → policy → query scope) |
| Domain models | 30 models incl. Control, Risk, TestScript, TestInstance, ControlException, CompensatingControl, EffectivenessRating, SpotCheck, Finding, Evidence, EscalationMatrix, IntegrationConfig |
| Services | ControlService, TestingService, ExceptionService, EscalationService, EvidenceService, ResidualRiskService, DashboardService, ReportService, IntegrationService, ExcelExportService, AuditTrailService |
| Business rules | Maker–checker, SoD (no admin bypass), auto-exception from failed check items, lifecycle state machines, configurable rating matrix, residual risk calculation, recurrence detection |
| Compliance | Immutable audit trail, NDPA evidence workflow, retention policies, legal hold, dual-approval disposal, access-logged downloads, SHA-256 checksums |
| Scheduled jobs | 6 commands (test instance generation, ageing, compensating control expiry, evidence disposal, escalations, owner digests) |
| Reporting | Configurable report templates, PDF + Excel exports, board pack |
| Integration | Per-tenant ThirdLine/NexusRisk sync, 3 modes, replay on failure, `/api/v1` with hashed API keys + idempotency |
| Tests | 29 feature/unit tests including SoD failing-paths, legal hold, dual approval, API auth bypass |

### 1.2 Gap analysis — Corporater's 32 solution features vs Atheris

| # | Corporater feature | Atheris status | Where the work lands |
|---|---|---|---|
| 1 | Access control / permissions | ✅ Have (Spatie, 4-layer) | — |
| 2 | Alerts and notifications | 🟡 Partial (in-app + email, no preferences, no channels) | Phase 7, 15 |
| 3 | Alignment with strategic initiatives, objectives, performance goals | ❌ Missing | Phase 17 |
| 4 | Audit log | ✅ Have (immutable) — needs a UI | Phase 7 |
| 5 | Automated risk reporting | 🟡 Partial (fixed templates) | Phase 13 |
| 6 | Continuous and ad-hoc monitoring of controls | ❌ Missing (CCM is unbuilt Phase 7 in BRD v1) | **Phase 12** |
| 7 | Control consolidation & aggregation from internal + external sources | 🟡 Partial (integration layer only) | Phase 12 |
| 8 | Control dashboards | 🟡 Partial (one fixed dashboard) | **Phase 13** |
| 9 | Control data upload via integration and manual input | 🟡 Partial (no bulk import) | Phase 9, 12 |
| 10 | Control data visualisation | 🟡 Weak (stat cards, no charts) | **Phase 13** |
| 11 | Control libraries at group **and** entity level | 🟡 Partial (one flat library) | **Phase 9** |
| 12 | Control Self-Assessment (CSA) | ❌ Missing | **Phase 9** |
| 13 | Control test templates and improvement databases | 🟡 Partial (templates exist, no improvement DB) | Phase 9 |
| 14 | COSO framework implementation project support | ❌ Missing (one `coso_component` string field) | **Phase 8** |
| 15 | Customisable branding | ❌ Missing | Phase 7 |
| 16 | Dashboards for design and operating effectiveness | 🟡 Partial (fields exist, no dashboard) | Phase 13 |
| 17 | Document management | ❌ Missing (evidence ≠ documents) | Phase 9 |
| 18 | Frequency of testing with alerts | ✅ Have | — |
| 19 | Governance, management & assurance of compliance (CBN etc.) | ❌ Missing | **Phase 8 — the wedge** |
| 20 | Incident management connected to controls | ❌ Missing | **Phase 11** |
| 21 | ICS in accordance with relevant standards and taxonomies | 🟡 Partial (`framework_refs` JSON blob) | **Phase 8** |
| 22 | Intuitive user interface | ✅ Have (AEGIS design system) | Polish throughout |
| 23 | KRIs, KPIs and other metrics | ❌ Missing | **Phase 10** |
| 24 | Linking KRIs, incidents, risks, policies | ❌ Missing | Phase 10, 11 |
| 25 | Policy management (code of conduct, business integrity) | ❌ Missing | **Phase 11** |
| 26 | Risk analysis templates and heatmaps | ❌ Missing | **Phase 10** |
| 27 | Risk assessments, internal + external, qualitative + quantitative | 🟡 Partial (basic register) | **Phase 10** |
| 28 | Risk register | ✅ Have (basic) | Phase 10 upgrade |
| 29 | SSO | ❌ Missing | **Phase 7** |
| 30 | Status and alerts for risk treatment activities | ❌ Missing | Phase 10 |
| 31 | Surveys | ❌ Missing | **Phase 9** |
| 32 | Version control | 🟡 Partial (controls + scripts only) | Phase 9 |

**Score: 5 have · 12 partial · 15 missing.**

Corporater's nine headline capabilities map the same way: Internal Control Dashboards (partial), Automated Workflows (partial), Flexible Configuration (missing), Data Visualisation (weak), Data Integration (partial), Risk Assessments (partial), SSO (missing), Alerts & Notifications (partial), **Artificial Intelligence (missing entirely)**.

### 1.3 What Corporater does *not* have — the whitespace

1. **No Nigerian/African regulatory content.** Confirmed across the vendor landscape: MetricStream, Corporater, AuditBoard, Diligent, Workiva, LogicGate, SAI360, Riskonnect, Ideagen, Archer. All ship SOX/GDPR/NIST/ISO. None ships CBN, FRC, NDPC, NDIC, SEC-Nigeria, NAICOM, PENCOM, NCC content.
2. **No in-country data plane.** None has a Nigerian or Ghanaian region. From 1 Jan 2027 (CBN) and now (BoG), that is a compliance blocker, not a preference.
3. **No regulator-shaped outputs.** Global tools produce dashboards. African buyers must produce *submissions* — FRC/CG/001, the NDPC Compliance Audit Return, the ICFR management assertion, the annual internal-audit external-assessment pack due to CBN by 31 May.
4. **Pricing mismatch.** AuditBoard's ACV runs $40k–$150k (Vendr data: SOXHUB Professional ~$48.2k avg, CrossComply Essentials ~$32.8k avg). Against naira revenue and CBN FX constraints this excludes the entire MFB, PSP, fintech, insurance and mid-cap listed segment — a segment carrying *identical* obligations.
5. **No multi-regulator overlay.** A Nigerian bank answers simultaneously to CBN, NDIC, SEC, FRC, NDPC, FIRS and NFIU. No global tool models one obligation satisfying several regulators.
6. **Bandwidth and device blindness.** Sub-Saharan mobile internet penetration is ~27% with a ~60% usage gap. Control owners in a 200-branch Nigerian bank are on mid-range Android over 4G, with interrupted power. Enterprise GRC UIs assume broadband and desktop.
7. **Staffing assumption.** No-code platform configurability assumes a 40-person GRC team. African second-line functions are thin. The product must substitute for scarce expertise with opinionated, pre-mapped content — not hand the buyer a blank canvas.

### 1.4 Competitive positioning

| | Global GRC (Corporater, MetricStream, AuditBoard) | BarnOwl (SA incumbent) | **Atheris Control v2** |
|---|---|---|---|
| Framework content | SOX, GDPR, ISO, NIST | ISO, King IV | **+ CBN, FRC, NDPC, NDIC, SEC-NG, NAICOM, PENCOM, NCC, King IV/V, CBK, BoG** |
| Regulator submissions | ❌ | ❌ | **✅ Generated filing packs** |
| Data residency | Offshore only | SA | **✅ Per-country plane; branch-per-client already compliant** |
| Pricing | USD $40k–150k ACV | ZAR, mid | **NGN/GHS/KES/ZAR, per-entity not per-seat** |
| Mobile/offline | Desktop-first | Desktop-first | **✅ PWA, offline attestation, WhatsApp** |
| AI | Add-on, generic | ❌ | **✅ Native, RAG over tenant data, Nigerian-regulation-aware** |
| SoD enforcement | Configurable (often mis-configured) | Configurable | **✅ Hard-enforced, no admin bypass, tested** |

---

## 2. Africa-first differentiators (the moat)

These are the features to build that Corporater will not, and the order of their commercial value:

**Tier 1 — buy triggers**
1. **Nigerian Regulatory Content Packs** — shipped, versioned, maintained obligation libraries with pre-mapped controls, tests and evidence requirements for CBN, FRC, NDPC, NDIC, SEC-NG.
2. **Regulator submission generator** — the product produces the actual filing: FRC/CG/001 (28 principles, apply-and-explain, no "N/A" allowed, four signatories), NDPC Compliance Audit Return (due 31 March, ₦100k–₦1m fee band by tier, 50% late penalty), FRC ICFR management assertion (COSO 2013, three-tier deficiency taxonomy), CBN internal-audit external assessment pack (due 31 May).
3. **In-country data residency** with an auditable residency attestation the customer can hand to CBN/BoG/NDPC.
4. **Cross-framework "test once, satisfy many"** — one control test simultaneously evidences COSO P11, ISO 27001 A.8.x, NIST CSF PR.x, PCI DSS Req.x, CBN RBCF Part x and NCCG P17/18.

**Tier 2 — retention and expansion**
5. **NDIC DPAS premium optimiser** — DPAS prices deposit insurance with *management* add-ons that are directly software-addressable: poor internal controls +0.02%, late return submission +0.01%, financial misreporting +0.03%, weak risk management +0.02%, non-compliance with examiners' recommendations +0.02%, against a 0.30% cap. A module that tracks examiner-recommendation closure and return timeliness has a **quantifiable, boardroom-ready ROI in basis points**. No competitor has this.
6. **Multi-regulator obligation overlay** — one obligation register, many regulators, one calendar, deduplicated evidence.
7. **CBN Consumer Protection complaints module** — 24-hour acknowledgement SLA with unique tracking ID; exposure calculator showing ₦500,000 per complaint per week of breach.
8. **Mobile/offline/WhatsApp attestation** — control owners attest and capture evidence from a phone, offline, syncing later; reminders and one-tap confirmations over WhatsApp Business Cloud API.

**Tier 3 — expansion into adjacent budget**
9. **IFRS S1/S2 sustainability controls** — Nigeria's FRC roadmap mandates all PIEs from periods beginning 1 Jan 2028, SMEs from 1 Jan 2030, with three-stage pre-reporting filings. COSO ICSR (2023) is the control framework. This budget does not yet have an owner — get there first.
10. **Core banking connectors** — Finacle (11 Nigerian banks, ~37%) + FLEXCUBE (9, ~30%) covers ~67% of the market; T24 (4, incl. the CBN itself); BankOne for the OFI/MFB volume tier.
11. **Pan-African content packs** — South Africa (King IV, draft King V with its new AI-governance chapter, POPIA, JSE 3.84), Kenya (CBK Prudential Guidelines, DPA 2019, CMA's biennial independent governance audit), Ghana (BoG CGD 2018, the 2026 cyber directive).

---

## 3. Target architecture

```
┌──────────────────────────────────────────────────────────────────────┐
│  PRESENTATION   React 18 + Inertia · AEGIS design system             │
│                 PWA shell · offline queue · low-bandwidth mode       │
│                 Dashboard builder · chart library · report designer  │
├──────────────────────────────────────────────────────────────────────┤
│  CHANNELS       Web · PWA · WhatsApp Cloud API · SMS · Email · API   │
├──────────────────────────────────────────────────────────────────────┤
│  AI LAYER       Anthropic Claude · RAG over tenant data              │
│                 AiGateway → PII redaction → prompt registry →        │
│                 model call → confidence → human-in-loop → audit      │
├──────────────────────────────────────────────────────────────────────┤
│  DOMAIN         Control · Risk · Test · Exception · Policy ·         │
│                 Incident · Obligation · KRI · Survey · Assessment ·  │
│                 Document · Entity · Objective · Vendor               │
├──────────────────────────────────────────────────────────────────────┤
│  CONTENT        Framework packs (COSO/COBIT/ISO/NIST/PCI/SOX)        │
│                 Regulatory packs (CBN/FRC/NDPC/NDIC/SEC/NAICOM/…)    │
│                 Cross-framework mapping graph                        │
├──────────────────────────────────────────────────────────────────────┤
│  AUTOMATION     CCM rule engine · connectors · schedulers ·          │
│                 escalation engine · workflow engine                  │
├──────────────────────────────────────────────────────────────────────┤
│  PLATFORM       Laravel 13 · MySQL · Redis · Horizon · S3-compatible │
│                 SSO (SAML2/OIDC) · MFA · immutable audit · RBAC      │
├──────────────────────────────────────────────────────────────────────┤
│  DATA PLANE     Per-country: NG (Rack Centre / MainOne-Equinix LG1 / │
│                 Galaxy Backbone) · GH · KE · ZA (af-south-1)         │
│                 Branch-per-client · residency attestation            │
└──────────────────────────────────────────────────────────────────────┘
```

**Principles that must not be violated:**
- Nothing regulatory is hard-coded. Frameworks, obligations, calendars, penalties, matrices, workflows are all **data**, versioned, seeded from content packs, tenant-overridable.
- SoD and maker–checker survive every new module. Any new approval gate gets an explicit failing-path test.
- The audit trail is append-only, forever. Every AI interaction is audited like a user action.
- Tenant data never crosses a country boundary without an explicit, logged, authorised export.
- Every list endpoint is paginated, every payload is budgeted, every form autosaves.

---

## 4. Delivery roadmap

Eleven phases, ~9 months at a steady pace. Phases 7–9 are the credibility floor; 8 is the commercial wedge; 12–14 are the differentiation.

| Phase | Name | Weeks | Why it matters |
|---|---|---|---|
| **7** | Platform Foundations v2 | 3 | SSO, MFA, branding, notification channels, i18n/multi-currency, audit UI, PWA shell. Enterprise procurement blockers. |
| **8** | **Framework & Regulatory Obligation Engine** | 5 | **The wedge.** COSO/COBIT/ISO/NIST/PCI/SOX + all Nigerian packs + cross-framework mapping + regulatory calendar. |
| **9** | Control Library v2, CSA & Surveys | 4 | Group/entity libraries, distribution, CSA campaigns, surveys, attestation, doc management, bulk import, version control. |
| **10** | Risk Management v2 | 4 | Quantitative + qualitative assessment, appetite, heatmaps, treatment plans, KRI/KPI engine, linkage graph. |
| **11** | Policy, Incident, Complaints & Case | 4 | Policy lifecycle + attestation, incident/loss events, whistleblowing, CBN complaints SLA engine, investigations. |
| **12** | Continuous Controls Monitoring & Connectors | 5 | CCM rule engine, automated testing, Finacle/FLEXCUBE/T24/BankOne/NIBSS/SAP/D365/Sage/M365/FIRS connectors. |
| **13** | Dashboards, Analytics & Reporting v2 | 4 | Dashboard builder, charts, drill-down, org rollups, report designer, Word/PPT/Excel/PDF, **regulator submission packs**. |
| **14** | **AI Layer (Anthropic)** | 5 | AI gateway, 9 capabilities, Atlas assistant with RAG, AI governance and audit. |
| **15** | Mobile, Offline & Omnichannel | 3 | PWA offline attestation, WhatsApp Cloud API, SMS, resumable uploads, low-bandwidth mode. |
| **16** | Multi-entity, Residency & Enterprise Readiness | 4 | Group consolidation, per-country data planes, residency attestation, scale, DR, observability, ISO 27001 self-evidence. |
| **17** | Extended GRC (strategy, TPRM, ESG, assurance) | 4 | Objectives/KPI alignment, vendor risk, IFRS S1/S2 controls, combined assurance map. |

**Critical path to first new revenue:** 7 → 8 → 9 → 13. That subset alone (16 weeks) produces a demonstrably better product than anything sold into Nigeria today, because Phase 8 + Phase 13 together yield generated regulator submissions.

**Suggested release train**
- **v2.0-beta** after Phase 9 — pilot with 2–3 friendly banks/PSPs
- **v2.0 GA** after Phase 13 — the commercial launch
- **v2.1** after Phase 15 — AI + mobile, the differentiation release
- **v2.2** after Phase 17 — full GRC suite

---

## 5. Commercial model

**Pricing metric:** per regulated entity + per active module, **not per seat**. Control owners number in the hundreds; per-seat kills adoption and produces shadow spreadsheets.

**Currency:** invoice in NGN/GHS/KES/ZAR with an internal hard-currency reference and contractual FX-drift repricing. Naira devaluation destroys USD-pegged multi-year deals. Payment via bank transfer, Remita and NIBSS direct debit — enterprise buyers will not put a six-figure subscription on a card.

**Indicative tiers**
| Tier | Target | Modules |
|---|---|---|
| **Essential** | MFBs, PSSPs, PTSPs, super agents, insurance brokers | Controls, testing, exceptions, 1 regulatory pack, dashboards |
| **Professional** | Commercial banks, PSBs, insurers, PFAs, listed mid-caps | + Risk v2, policy, incident, CSA, surveys, all NG packs, submissions |
| **Enterprise** | Tier-1 banks, FHCs, telcos, groups | + CCM, connectors, AI, multi-entity consolidation, in-country residency, SLA |

**Sales prerequisites (start now, they take months):** ISO 27001:2022 certification, an NDPA-compliant DPA template, a CBN material-outsourcing notification pack, and a penetration test report. Bank vendor due diligence will demand all four. Note the ISO/IEC 27001:2013 transition closed 31 Oct 2025 — certify against the 2022 edition (93 Annex A controls in 4 themes) directly.

---

## 6. Risks to the plan

| Risk | Mitigation |
|---|---|
| **Regulatory content goes stale** — CBN issues circulars constantly | Phase 8 builds a versioned content-pack system with an effective-date model and a diff workflow; Phase 14's AI circular-parser drafts the diff for human approval. Budget a part-time regulatory analyst from day one. |
| **Unverified regulatory detail encoded as fact** | 13 items are flagged unverified in the research (CBN incident-reporting window, AML/CFT section detail, eFASS schedule, whether ISA 2025 renumbers ss.60–63, PenCom RM guideline, NIIRA capital, CBK PG numbers, King V final, per-jurisdiction Basel, NIST CSF counts, Nigeria Tax Act commencement, six survey-level countries, local vendor scan). **Every one must be sourced from the primary document before it is seeded.** Content packs carry a `verification_status` field; unverified items ship as `draft` and are not surfaced in submissions. |
| **Scope inflation** — eleven phases is a lot | Phases ship independently and each has a hard definition of done. Cut Phase 17 and half of 12 without harming the core proposition. |
| **AI hallucination in a compliance product** | Every AI output is a *draft* requiring human approval, is confidence-scored, cites its retrieved source, and is written to the audit trail. No AI output ever auto-approves a control, closes an exception, or signs a submission. This is a hard architectural rule, enforced in `AiGateway`. |
| **Data residency costs** | Branch-per-client already isolates tenants. Start with a Nigerian data plane only; add GH/KE/ZA on first paying customer in each. |
| **Big 4 incumbency** | Partner rather than compete — position Atheris as the platform their implementation teams deploy. Their ICFR guides are a demand signal, not a competing product. |

---

## 7. How to use the companion prompt pack

`02-CLAUDE-CODE-PROMPTS.md` contains:

- **Part A — Master Context Brief.** Paste this at the start of *every* Claude Code session, before the phase prompt. It carries the architecture, conventions, non-negotiable rules and the definition of done.
- **Part B — Phase prompts 7 through 17.** One self-contained prompt per phase. Each specifies objective, files to read first, data model, backend, frontend, business rules, acceptance criteria, tests and DoD.
- **Part C — AI layer specification** (referenced by Phase 14).
- **Part D — Regulatory content pack seed specification** (referenced by Phase 8).

Run one phase per session. Do not run two. Each phase ends with `composer test`, `composer lint`, `npm run build` green and a commit.

---

## ⚠️ Security note — act on this before anything else

The Anthropic API key was shared in plain chat. **Treat it as compromised: rotate it at console.anthropic.com now.** The replacement belongs in `.env` as `ANTHROPIC_API_KEY`, referenced only via `config/services.php`, with `.env` in `.gitignore` (it already is) and a placeholder line in `.env.example`. It must never appear in a prompt, a commit, a seeder, a test fixture, or client-side code. Phase 14 assumes this.

---

## Sources

Key primary and secondary sources behind §1–§2:

- [Corporater Internal Control System Software](https://corporater.com/solution/internal-control-system-software/)
- [CBN Circular and Guidelines for Corporate Governance 2023](https://www.cbn.gov.ng/Out/2023/FPRD/Circular%20and%20Guidelines%20for%20Corporate%20Governance.pdf) · [PwC summary](https://www.pwc.com/ng/en/assets/pdf/2023-cbn-governance-guidelines.pdf)
- [CBN payment data localisation directive analysis](https://complyan.com/nigerias-cbn-data-localisation-directive-what-banks-and-fintechs-must-do-before-2027/) · [Privalex](https://www.privalexadvisory.com/insights/the-cbn-payment-data-localisation-directive-legal-tensions-market-consequences-and-the-road-to-1-january-2027)
- [NDIC DPAS premium assessment matrix](https://ndic.gov.ng/deposit-insurance/premium-assessment-rate-dpas-matrix/) · [NDIC Act 2023](https://ndic.gov.ng/wp-content/uploads/2023/09/NDIC-Act-2023-LATEST.pdf)
- [FRC Guidance on Management Report on ICFR (2024)](https://frcnigeria.gov.ng/wp-content/uploads/2024/07/FRC-Guidance-on-Management-Report-on-ICFR-RR-1.pdf)
- [Nigerian Code of Corporate Governance 2018](https://icsan.org/wp-content/uploads/2024/11/Nigerian-Code-of-Corporate-Governance-2018.pdf) · [FRC FAQs](https://frcnigeria.gov.ng/faqs/)
- [FRC amended IFRS S1/S2 adoption roadmap (2026)](https://frcnigeria.gov.ng/wp-content/uploads/2026/02/Roadmap-Report-for-the-Adoption-of-IFRS-Sustainability-Disclosure-Standards-in-Nigeria-Amended-2026-.pdf)
- [NDPC GAID 2025 analysis, DLA Piper](https://privacymatters.dlapiper.com/2025/06/nigeria-ndpc-issues-gaid-key-compliance-insights/) · [UUBO regulatory update](https://uubo.org/wp-content/uploads/2025/03/REGULATORY-UPDATE-NDPC-ISSUES-GAID-2025.pdf)
- [SEC Nigeria guidance on ISA ss.60–63 (ICFR)](https://sec.gov.ng/documents/32/SEC-Guideline-on-Sec-60-63-of-ISA-2007.pdf) · [SEC returns filing calendar](https://sec.gov.ng/about/resources/checklists/regulatory-returns-filing-calendar-for-public-companies/)
- [NIIRA 2025](https://naicom.gov.ng/wp-content/uploads/2025/08/NIIRA-2025.pdf)
- [CBN Risk-Based Cybersecurity Framework analysis](https://securiti.ai/blog/cbn-issues-risk-based-cybersecurity-framework/)
- [Bank of Ghana 2026 Cyber & Information Security Directive](https://www.bog.gov.gh/wp-content/uploads/2026/03/SPEECH-BY-GOVERNOR-DR.-JOHNSON-PANDIT-ASIAMA-AT-THE-LAUNCH-OF-THE-CYBER-AND-INFORMATION-SECURITY-DIRECTIVE-1.pdf)
- [Draft King V Code, Cliffe Dekker Hofmeyr](https://www.cliffedekkerhofmeyr.com/en/news/publications/2025/Practice/Corporate-Commercial/corporate-and-commercial-alert-16-april-staying-ahead-of-governance-trends-key-changes-in-the-draft-king-v-code)
- [NIST CSF 2.0 (NIST CSWP 29)](https://nvlpubs.nist.gov/nistpubs/CSWP/NIST.CSWP.29.pdf) · [ISO 27001:2022 transition](https://www.secureaudit.nl/en/knowledge-base/iso-27001-2022-transition-deadline) · [PCI DSS v4.0.1](https://blog.pcisecuritystandards.org/just-published-pci-dss-v4-0-1)
- [COSO 2013 17 principles](https://weaver.com/resources/coso-frameworks-17-principles-effective-internal-control/) · [COSO ERM 2017 principles](https://erm.ncsu.edu/resource-center/cosos-erm-framework/)
- [Core banking software in Nigeria 2024, Adedeji Olowe](https://dejiolowe.com/2024/10/01/core-banking-software-in-nigeria-as-of-2024/)
- [AuditBoard pricing data](https://www.smartsuite.com/blog/auditboard-pricing) · [BarnOwl integrated GRC](https://barnowl.co.za/solutions/integrated-grc-solution)
- [GSMA Mobile Economy Sub-Saharan Africa](https://www.gsma.com/about-us/regions/africa/wp-content/uploads/2025/11/GSMA-SmartPhone_Adoption_Report_sm.pdf) · [NIBSS BVN registrations](https://nibss-plc.com.ng/nigerias-bvn-database-hits-67-8-million-registrations-by-end-of-2025-nibss/)
