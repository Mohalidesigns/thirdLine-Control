# ThirdLine Development Standard

**A reusable engineering, design, and product standard derived from the ThirdLine Internal Audit application.**

This document captures the architecture, conventions, visual language, and access-control model that make ThirdLine efficient to build and consistent to use. Treat it as the baseline reference when starting a new application in the same family. Copy the patterns; keep the naming; reuse the component and CSS layer verbatim where you can.

- **Version:** 1.0
- **Source application:** ThirdLine — Internal Audit Solution (`internalaudit`)
- **Last updated:** July 2026
- **Audience:** Engineers, designers, and product owners building GRC-class web applications.

---

## Table of contents

1. [Philosophy & principles](#1-philosophy--principles)
2. [Technology stack](#2-technology-stack)
3. [Project structure](#3-project-structure)
4. [Backend architecture & conventions](#4-backend-architecture--conventions)
5. [Frontend architecture & conventions](#5-frontend-architecture--conventions)
6. [Theme, branding & design system](#6-theme-branding--design-system)
7. [Component library](#7-component-library)
8. [Roles, permissions & access control](#8-roles-permissions--access-control)
9. [Data & domain modeling conventions](#9-data--domain-modeling-conventions)
10. [Development workflow & tooling](#10-development-workflow--tooling)
11. [Localization & formatting standards](#11-localization--formatting-standards)
12. [Scaffolding playbook — starting a new app](#12-scaffolding-playbook--starting-a-new-app)

---

## 1. Philosophy & principles

ThirdLine is an enterprise GRC (Governance, Risk & Compliance) SPA. The patterns below exist because they keep a large, feature-dense application (100+ pages, 130+ models) navigable and consistent for both users and developers.

The guiding principles are:

- **Server-driven SPA.** The backend owns routing, authorization, validation, and data shaping. The frontend renders what the server hands it via Inertia. There is no separate REST/GraphQL API layer to keep in sync — one round trip, one source of truth.
- **Convention over configuration.** File and route names map predictably to controllers and page components. A developer who knows one feature module can navigate any other.
- **A thin, declarative UI.** Pages are composed from a small, fixed set of reusable primitives (tables, filter bars, page headers, badges, modals). New screens assemble existing parts rather than inventing new ones.
- **Business logic lives in services.** Controllers stay thin — they authorize, validate, delegate to a service, and render. Domain rules concentrate in `app/Services/`.
- **Access control is enforced in depth.** Roles gate routes, controllers scope queries, policies guard models, and the same role/permission data drives what the UI shows. The frontend never becomes the security boundary.
- **A single, tokenized visual language.** Colors, spacing, and component styles are defined once as CSS variables and Tailwind component classes, then reused everywhere. The app looks like one product, not fifty screens.
- **Determinism and resilience.** Cross-cutting concerns (licensing, offline state) are wrapped so a failure degrades gracefully and never takes down a page render.

---

## 2. Technology stack

| Layer | Technology | Version | Notes |
|-------|-----------|---------|-------|
| Language (backend) | PHP | 8.2+ | Typed properties, enums, readonly where useful |
| Framework | Laravel | 12.x | Standard directory layout |
| SPA bridge | Inertia.js | 2.0 | Server-side routing, no client router |
| Frontend | React | 18.2 | Function components + hooks only |
| Build tool | Vite | 7.x | via `laravel-vite-plugin` |
| Styling | Tailwind CSS | 3.x | + `@tailwindcss/forms`, custom component layer |
| Headless UI | `@headlessui/react` | 2.0 | Modals, dropdowns, transitions |
| Auth scaffolding | Laravel Breeze | 2.x | Inertia + React stack |
| API tokens | Laravel Sanctum | 4.0 | Session auth for the SPA |
| RBAC | `spatie/laravel-permission` | 7.x | Roles & permissions |
| Route helper | `tightenco/ziggy` | 2.0 | Named routes in JS via `route()` |
| HTTP client | Axios | 1.x | Auto-configured by Breeze |
| PDF export | `barryvdh/laravel-dompdf`, `spatie/browsershot` | — | Report generation |
| Spreadsheet | `phpoffice/phpspreadsheet` | — | Import/export |
| JWT | `firebase/php-jwt` | — | Licensing tokens |

**Standing rules:**

- No external React component library (no MUI/Radix/shadcn). The UI is built from a custom component set on top of Headless UI + Tailwind. This keeps the bundle small and the look fully owned. Reuse the existing components rather than adding a dependency.
- No client-side router. Inertia handles navigation; use its `<Link>` and `router` helpers.
- React is function-components-and-hooks only. No class components.

---

## 3. Project structure

Standard Laravel 12 layout. The parts that carry convention:

```
app/
├── Console/            Artisan commands
├── Exceptions/         Custom exceptions
├── Http/
│   ├── Controllers/    One controller per resource; thin
│   ├── Middleware/      Cross-cutting request concerns
│   └── Requests/        Form request validation classes
├── Jobs/               Queued work
├── Mail/               Mailables
├── Models/             Eloquent models (one per domain entity)
├── Notifications/      Laravel notifications
├── Policies/           Model authorization policies
├── Providers/          Service providers (register policies, bindings)
├── Services/           Business logic — the heart of the app
└── Support/            Helpers & utilities

config/                 Framework + custom config (one file per concern)
database/
├── migrations/         Timestamp-ordered schema; one table per file
├── factories/          Model factories for tests/seeds
└── seeders/            DatabaseSeeder provisions roles, users, demo data

resources/
├── css/app.css         Design tokens + Tailwind component layer
└── js/
    ├── app.jsx         Inertia entry point
    ├── bootstrap.js    Axios setup
    ├── utils.js        Formatting helpers
    ├── Components/      Reusable UI primitives (PascalCase.jsx)
    ├── Layouts/         App shell (Authenticated / Guest)
    ├── Pages/           Route-mapped page components
    ├── Contexts/        React context providers
    ├── Hooks/           Custom hooks
    └── constants/       Shared enums / option lists

routes/
├── web.php             All app routes, grouped by feature + role
├── auth.php            Breeze auth routes
└── console.php         Scheduled/console commands
```

**Feature-module convention.** A feature named `AuditPlans` appears consistently as:

- Route group: `Route::resource('audit-plans', AuditPlanController::class)`
- Controller: `app/Http/Controllers/AuditPlanController.php`
- Model: `app/Models/AnnualAuditPlan.php` (+ related models)
- Pages: `resources/js/Pages/AuditPlans/{Index,Create,Edit}.jsx`

Learn this mapping once and every module is discoverable.

---

## 4. Backend architecture & conventions

### 4.1 Controllers are thin

Controllers do four things: authorize, validate (via Form Requests), delegate to a service, and render an Inertia response. They inject services through the constructor.

```php
class AuditEngagementController extends Controller
{
    public function __construct(private NotificationService $notificationService) {}

    public function index(Request $request)
    {
        $query = AuditEngagement::query()
            ->with(['entity', 'auditType', 'leadAuditor'])   // eager-load relations
            ->when($request->status, fn ($q, $s) => $q->where('status', $s));

        return Inertia::render('AuditEngagements/Index', [
            'engagements' => $query->paginate(15)->withQueryString(),
            'filters'     => $request->only(['status', 'search']),
        ]);
    }
}
```

Conventions:

- **Always eager-load** the relations a page needs (`->with([...])`) to avoid N+1 queries.
- **Paginate list views** (default 15 per page) and call `->withQueryString()` so filters survive pagination.
- **Return `Inertia::render('Folder/Page', [...props])`** — the string maps to `resources/js/Pages/Folder/Page.jsx`.
- **Pass filters back** to the page so the UI can reflect current query state.

### 4.2 Services own business logic

Anything beyond trivial CRUD lives in `app/Services/`. Representative services in ThirdLine:

- `NotificationService` — user notifications and messaging
- `ApprovalWorkflowService` — multi-step approval orchestration
- `AuditReportWriterService` — report assembly
- `Licensing/LicenseManager` (+ `SyncManager`, `JwtValidator`, …) — license lifecycle
- Domain intelligence services (e.g. risk scoring, duplicate detection)

Rule of thumb: if a method touches more than one model, encodes a business rule, or calls an external system, it belongs in a service — not a controller and not a model.

### 4.3 Form Requests for validation

Validation lives in `app/Http/Requests/` classes, not inline in controllers. This keeps rules reusable and controllers readable, and centralizes authorization checks (`authorize()`) alongside rules (`rules()`).

### 4.4 Policies for model authorization

Model-level permissions use Laravel Policies, registered in a service provider:

```php
// AppServiceProvider::boot()
Gate::policy(AuditReport::class, ReportPolicy::class);
```

A policy's `before()` grants Super Admin blanket access; individual methods encode role + ownership rules. See [section 8](#8-roles-permissions--access-control).

### 4.5 Middleware for cross-cutting concerns

Custom middleware handles what every request needs:

- `HandleInertiaRequests` — shares auth, permissions, flash messages, and app state with every page (see 5.3).
- Domain middleware (e.g. licensing validity / feature gates) — guard access and run background sync on `terminate` so the response isn't slowed.

Wrap fragile cross-cutting logic in try/catch and degrade gracefully — a subsystem hiccup must never break a page render.

### 4.6 Models

- One model per domain entity in `app/Models/`, PascalCase singular.
- Use `HasFactory` for testability and `SoftDeletes` for user-facing records that shouldn't hard-delete.
- Declare `$fillable` (or `$guarded`) explicitly and `$casts` for dates, booleans, and JSON columns.
- Define relationships and query scopes on the model; keep business rules in services.

```php
class AuditEngagement extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = ['audit_id', 'planned_audit_id', 'entity_id', 'status', 'phase', /* … */];

    protected $casts = [
        'scope_inclusions' => 'array',
        'planned_start_date' => 'date',
    ];

    public function entity() { return $this->belongsTo(AuditableEntity::class, 'entity_id'); }
    public function leadAuditor() { return $this->belongsTo(User::class, 'lead_auditor_id'); }
}
```

---

## 5. Frontend architecture & conventions

### 5.1 Inertia page resolution

`app.jsx` auto-resolves pages from the `Pages/` directory. A controller returning `Inertia::render('AuditPlans/Index', …)` renders `resources/js/Pages/AuditPlans/Index.jsx`. Every page render is wrapped with the app-wide `LicenseNotice` overlay, and an offline service worker is registered globally.

### 5.2 Page component shape

Pages are default-exported function components. Props (the controller's data) are destructured in the signature with sensible defaults. Every page renders inside a layout and sets its document title with `<Head>`.

```jsx
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head } from '@inertiajs/react';

export default function Index({ engagements = { data: [] }, filters = {} }) {
    return (
        <AuthenticatedLayout header="Audit Engagements">
            <Head title="Audit Engagements" />
            {/* compose from shared components */}
        </AuthenticatedLayout>
    );
}
```

### 5.3 Shared props (the frontend's ambient state)

`HandleInertiaRequests::share()` injects a consistent envelope into every page. The frontend reads it with `usePage().props`:

```php
'auth' => [
    'user'        => $user,
    'roles'       => $user?->getRoleNames()->toArray() ?? [],
    'permissions' => $user?->getAllPermissions()->pluck('name')->toArray() ?? [],
],
'unreadNotifications' => /* count */,
'flash' => ['success' => …, 'error' => …, 'warning' => …, 'info' => …],
'license' => /* lazy, guarded app-state */,
```

This means any component can, without extra requests:

- Check the current user, their roles, and permissions.
- Render flash notifications (a `FlashNotification` component reads `flash`).
- Show an unread-notification badge.
- React to global app state (e.g. license status).

### 5.4 Forms

Forms use Inertia's `useForm` hook for state, submission, and server-error binding. Never hand-roll fetch calls for form posts.

```jsx
const { data, setData, post, processing, errors } = useForm({ plan_name: '' });

const submit = (e) => { e.preventDefault(); post(route('audit-plans.store')); };

<label className="form-label">Plan Name <span className="text-red-500">*</span></label>
<input className="form-input" value={data.plan_name}
       onChange={(e) => setData('plan_name', e.target.value)} />
<InputError message={errors.plan_name} className="mt-1" />
```

Conventions: server-side validation is the source of truth; bind errors with `<InputError>`; disable submit while `processing`; constrain form width with `max-w-4xl mx-auto`; group fields in cards with `space-y-4/6`.

### 5.5 Named routes

Use Ziggy's `route('name', params)` helper in JS rather than hardcoding URLs, so backend route changes propagate.

### 5.6 Naming

- Components & pages: `PascalCase.jsx`, default export.
- Hooks: `useThing.js`.
- Utilities: named exports in `utils.js`.
- Constants/enums shared with the UI: `constants/`.

---

## 6. Theme, branding & design system

This is the visual contract. It is defined **once** in `resources/css/app.css` as CSS custom properties plus a Tailwind component layer, and reused everywhere. Copy this file into a new app to inherit the entire look.

### 6.1 Brand identity

- **Product family:** ThirdLine (GRC suite). Set `APP_NAME` / `VITE_APP_NAME` per app.
- **Logo:** white SVG logo in the (dark) sidebar; sized `h-11` normal / `h-9` collapsed.
- **Favicons:** full set in `public/` (`favicon.ico`, 16/32/96px PNGs, `apple-touch-icon.png`, `android-chrome-192/512`).
- **Tone:** professional, dense, enterprise. Navy authority + gold accent + green for positive states.

### 6.2 Color tokens

Defined as CSS variables on `:root`. Reference them via `var(--token)` or the Tailwind arbitrary-value syntax `bg-[var(--color-primary)]`.

| Token | Hex | Role |
|-------|-----|------|
| `--color-primary` | `#1A365D` | Primary navy — main actions, sidebar, focus rings |
| `--color-primary-light` | `#2A4A7F` | Hover state for primary |
| `--color-primary-dark` | `#0F2340` | Deep navy |
| `--color-secondary` | `#2D7D46` | Forest green — success / positive |
| `--color-secondary-light` | `#38A169` | Green hover |
| `--color-accent` | `#D4AF37` | Gold — active nav indicator, highlights |
| `--color-accent-light` | `#E2C458` | Gold hover |
| `--color-bg` | `#F7FAFC` | App background (off-white) |
| `--color-card` | `#FFFFFF` | Card / surface |
| `--color-text-primary` | `#2D3748` | Primary text |
| `--color-text-secondary` | `#718096` | Secondary / muted text |
| `--color-error` | `#C53030` | Errors / danger |
| `--color-warning` | `#DD6B20` | Warnings |
| `--color-info` | `#319795` | Informational |
| `--color-success` | `#2D7D46` | Success |

Layout tokens: `--sidebar-width: 260px`, `--sidebar-collapsed-width: 72px`.

### 6.3 Typography

- **Body / UI:** Inter (loaded via Google Fonts; weights 300–800). Applied on `body`.
- **Monospace:** Roboto Mono (weights 400–600) via the `.font-mono` class — use for IDs, codes, and technical values (e.g. `ENG-2025-001`).
- **Page title:** `.page-title` → `text-2xl font-bold` in primary text color.

> Note: `tailwind.config.js` sets the Tailwind `sans` family to Figtree (Breeze's default), but the applied body font is **Inter** via the `body` rule in `app.css`. Standardize on Inter for new apps and keep the two in sync.

### 6.4 Spacing, radius, elevation

- **Spacing:** Tailwind's 4px scale. Cards use `p-5`/`px-6 py-4`; forms stack with `space-y-4`/`space-y-6`.
- **Radius:** `rounded-lg` for controls/buttons; `rounded-xl` for cards and stat cards; `rounded-full` for badges and progress bars.
- **Elevation:** `shadow-sm` for cards (hover `shadow-md`), `shadow-lg` for dropdowns, `shadow-xl` for modals. Borders are light (`border-gray-100/200`).
- **Transitions:** `transition-all/colors duration-200` on interactive elements; `duration-500` for progress fills.
- **Scrollbars:** thin (6px) custom-styled; light on light surfaces, translucent white on the dark sidebar.

### 6.5 The Tailwind component layer

`app.css` defines reusable classes under `@layer components`. Prefer these over ad-hoc utility soup — they are the design system's building blocks:

| Class | Purpose |
|-------|---------|
| `.card`, `.card-header`, `.card-body` | Standard surface container |
| `.stat-card` | Dashboard metric tile (hover-elevates) |
| `.page-header`, `.page-title` | Page heading row (responsive) |
| `.btn-primary/secondary/success/danger/warning` | Semantic buttons |
| `.badge` + `.badge-critical/high/medium/low` | Risk / severity chips |
| `.badge-status-draft/pending/active/completed/overdue` | Lifecycle status chips |
| `.data-table` (+ `thead th`, `tbody td/tr`) | Standard table styling |
| `.form-input`, `.form-select`, `.form-label` | Form controls |
| `.filter-bar` (+ `-inner/-group/-label/-input/-select/-reset`) | Filter toolbar |
| `.progress-bar`, `.progress-bar-fill` | Progress indicators |

Custom animations: `bell-ring` (notification bell) and `slide-in` (toast entrance).

### 6.6 Color semantics (memorize these)

- **Severity/risk:** critical = red, high = orange, medium = yellow/amber, low = green.
- **Status:** draft = gray, pending = blue, active = green, completed = purple, overdue = red.
- **Actions:** primary = navy, success = green, danger = red, warning = orange, secondary = white/bordered.
- **Accent gold** is reserved for the active-navigation indicator and sparing highlights — not for buttons.

### 6.7 App shell & layout patterns

The `AuthenticatedLayout` defines the shell used by every authenticated page:

```
┌───────────────────────────────────────────────┐
│ Logo | (search) |        Notifications | User ▾ │  ← sticky topbar
├────────┬──────────────────────────────────────┤
│        │  Page header (title • actions)        │
│ Side   ├──────────────────────────────────────┤
│ bar    │  Main content                         │
│ (navy) │   • stat cards (1–4 col responsive)   │
│ 260px  │   • cards / tables / charts           │
│ ↔ 72px │   • forms (max-w-4xl)                 │
│        │  Offline indicator • License overlay  │
└────────┴──────────────────────────────────────┘
```

- **Sidebar:** dark navy, white text; active item = subtle white background + 3px gold left border; collapsible to 72px on desktop; slide-over drawer on mobile. Nav items are declared as data with an `allowedRoles` array that filters visibility (see 8.5).
- **Topbar:** sticky, holds search, an animated notification bell with unread badge, and a user dropdown (avatar, name, email, profile, logout).
- **Content grids:** `grid-cols-1 sm:grid-cols-2 lg:grid-cols-3/4`; tables wrap in `overflow-x-auto`.
- **Responsive:** mobile-first; sidebar and dense tables adapt down; forms and grids collapse to single column.

---

## 7. Component library

Reusable primitives in `resources/js/Components/`. Build new screens by composing these; extend the set only when a genuinely new pattern appears.

**Layout & structure**

- `PageHeader` — `{ title, subtitle, actions, breadcrumbs }`. Breadcrumbs are `[{label, href?}]`; actions is a right-aligned slot.
- `Modal` — Headless UI dialog wrapper; `{ show, onClose, maxWidth: 'sm'…'2xl', closeable }` with scale/fade transitions.
- `ConfirmDialog` — `{ title, message, variant: 'danger'|'warning'|'primary'|'success', processing, onConfirm, onCancel }`.
- `Dropdown` — compound component: `Dropdown.Trigger`, `Dropdown.Content` (`align`, `width`), `Dropdown.Link`.
- `EmptyState` — `{ icon, title, description, actionLabel, actionHref|onAction }`.

**Data display**

- `DataTable` — `{ columns, data, emptyMessage, emptyAction, striped }`. Columns: `{ field, label, sortable, render, accessor, width }`; sorting is client-side.
- `Pagination` — renders Laravel paginator `links`/`meta` with Inertia `<Link>` and a "Showing X–Y of Z" summary.
- `FilterBar` — `{ filters, currentFilters, route, searchPlaceholder }`; filter types `search|select|checkbox`; syncs to URL query params via `router`; auto-shows a reset button.
- `StatCard` — `{ title, value, subtitle, icon, color, trend }` for dashboard metrics.
- `StatusBadge` / `RatingBadge` — map a status/severity string to the correct `.badge-*` class.
- `DonutChart` — lightweight chart visualization.

**Forms**

- `PrimaryButton`, `SecondaryButton`, `DangerButton`, `TextInput`, `InputLabel`, `InputError`, `Checkbox`.

**Feedback & app-state**

- `FlashNotification` — toast for `flash.*` shared props.
- `OfflineIndicator` — network status (paired with the service worker).
- `LicenseNotice` / `Licensing/*` — app-wide license overlay and feature-gate components.

---

## 8. Roles, permissions & access control

RBAC is built on `spatie/laravel-permission` with a web guard, enforced in depth: **route → controller → policy → query scope**, and mirrored to the UI via shared props. The frontend reflects permissions but is never the security boundary.

### 8.1 Authentication

Laravel Breeze (Inertia/React) + Sanctum, session-based on the `web` guard. The `User` model implements `MustVerifyEmail`; authenticated routes require `['auth', 'verified']`. Standard password reset via `password_reset_tokens`.

### 8.2 The seven roles

Roles are seeded in `DatabaseSeeder`. Hierarchy from most to least privileged:

| Role | Scope |
|------|-------|
| **Super Admin** | All permissions. System administration. |
| **Chief Audit Executive (CAE)** | All permissions (also assigned Super Admin in seed data). Top of the audit function. |
| **Audit Manager** | All permissions **except** `manage settings`, `manage roles`, `issue reports`. |
| **Senior Auditor** | Create/edit work programs, findings, reports; view plans/engagements; validate follow-up. No approvals, no settings. |
| **Auditor** | View most modules; create findings and time entries; upload evidence. Execution-level. |
| **Auditee** | Read-only on the three things they need: findings, follow-up, reports. |
| **Observer** | View-only across universe, plans, engagements, findings, reports, analytics. |

Design pattern to reuse: **two admin tiers** (system vs. functional head), **graded practitioner tiers** (manager → senior → staff), and **external-facing read-only roles** (subject/auditee + observer). Adapt the labels to the new domain, keep the shape.

### 8.3 Permission naming convention

Permissions are `"<verb> <resource>"` strings, e.g. `view audit-plans`, `create findings`, `approve reports`, `manage settings`. Verbs used: `view`, `create`, `edit`, `delete`, `approve`, `issue`, `manage`, `upload`, `assess`, `validate`, `process`, `respond`. Namespaced variants exist for workflow gates (`approvals.approve_report`).

This gives a predictable matrix: for a new resource `foo`, define `view foo`, `create foo`, `edit foo`, and whatever lifecycle verbs (`approve foo`, `manage foo`) it needs — then assign to roles.

### 8.4 Enforcement layers

**1. Route middleware** — coarse gating by role:

```php
Route::middleware('role:Super Admin|Chief Audit Executive|Audit Manager')->group(function () {
    Route::resource('audit-plans', AuditPlanController::class);
    Route::post('audit-plans/{plan}/approve', [AuditPlanController::class, 'approve']);
});
```

**2. Controller query scoping** — users see only what they should:

```php
if (! $user->hasRole('Super Admin') && ! $user->hasAnyRole(ReportPolicy::AUDIT_ROLES)) {
    $query->whereHas('auditEngagement', fn ($q) => $q->where('lead_auditor_id', $user->id));
}
```

**3. Policies** — model-level authorization with a Super Admin bypass:

```php
class ReportPolicy
{
    const AUDIT_ROLES = ['Chief Audit Executive', 'Audit Manager', 'Audit Supervisor', 'Senior Auditor', 'Auditor'];

    public function before(User $user) { return $user->hasRole('Super Admin') ? true : null; }
    public function view(User $user, AuditReport $r) { /* role + ownership */ }
}
```

**4. Ownership helpers on the model** — e.g. `User::isLeadership()` (Super Admin or CAE bypass team checks) and `User::isOnEngagementTeam()` (lead, manager, supervisor, follow-up officer, or member). Use these to scope "my records vs. all records" views.

### 8.5 Frontend reflection

`HandleInertiaRequests` shares `auth.roles` and `auth.permissions` with every page. Navigation and conditional UI read them:

```jsx
const userRoles = usePage().props.auth.roles;
const visible = !item.allowedRoles || item.allowedRoles.some((r) => userRoles.includes(r));
```

Nav items carry a declarative `allowedRoles` array; the layout filters them. Buttons/sections do the same. **Always pair a UI gate with a backend gate** — hiding a button is UX, not security.

### 8.6 Secondary role attribute

Users also carry a lightweight `audit_role` string (`cae`, `audit_manager`, `senior_auditor`, `it_auditor`, `staff_auditor`, `auditee`, `admin`) plus a `reports_to` self-reference for org hierarchy. This domain attribute is distinct from Spatie roles (which drive authorization) and is used for team composition and reporting lines. Keep authorization in Spatie; use domain attributes for domain logic.

---

## 9. Data & domain modeling conventions

### 9.1 Migrations

- One table per migration file; timestamp-prefixed name `YYYY_MM_DD_HHMMSS_create_<table>_table.php`, applied in order.
- Use `$table->id()` primary keys and `foreignId(...)->constrained()->nullOnDelete()` (or `cascadeOnDelete`) for relations.
- Prefer `enum(...)` columns for fixed lifecycle states with a `->default(...)`.
- Use `json(...)` for structured lists (e.g. `scope_inclusions`) and cast to `array` on the model.
- Add `timestamps()` always, `softDeletes()` for user-facing records.

```php
Schema::create('audit_engagements', function (Blueprint $table) {
    $table->id();
    $table->string('audit_id')->unique();                    // human-readable business key
    $table->foreignId('planned_audit_id')->nullable()->constrained()->nullOnDelete();
    $table->enum('status', ['draft','pending_approval','approved', /* … */])->default('draft');
    $table->enum('phase', ['planning','fieldwork','reporting'])->nullable();
    $table->json('scope_inclusions')->nullable();
    $table->timestamps();
    $table->softDeletes();
});
```

### 9.2 Human-readable business keys

Alongside the numeric PK, records carry a typed, human-friendly identifier: engagements `ENG-2025-001`, findings `FND-2025-001`, plans `AAP-2025-001`, entities `ENT-001`, work programs `WP-2025-001`. Pattern: `PREFIX-[YEAR-]NNN`. Render these in `font-mono`. Reuse this scheme for any new domain object users refer to by ID.

### 9.3 Lifecycle status fields

Domain records model an explicit lifecycle in a `status` enum (and sometimes a parallel `phase`). Statuses drive the status badges in the UI. Approvals flow through named transitions (draft → review → approve/reject → issue), each guarded by role. Model workflows as explicit states, not booleans.

### 9.4 Seeding

`DatabaseSeeder` provisions, in order: permissions → roles (with `syncPermissions`) → reference data (departments, types, factors) → users (with `assignRole` and `reports_to` lines) → demo domain records. Keep this order — roles must exist before users are assigned. Seed realistic demo data so every screen has something to show in development.

---

## 10. Development workflow & tooling

**Composer scripts** (backend):

```bash
composer setup      # first-time install/bootstrap
composer dev        # runs server + queue + logs (pail) + Vite concurrently
composer test       # config:clear then PHPUnit
composer lint       # Laravel Pint (--test)
composer lint:fix   # Pint auto-fix
composer analyze    # PHPStan static analysis
composer check      # lint + analyze
```

**npm scripts** (frontend): `npm run dev` (Vite HMR), `npm run build` (production bundle).

**Standards:**

- Format PHP with **Pint** before committing; keep **PHPStan** green (`composer check`).
- Write tests with the model factories; run `composer test` before pushing.
- One dev command (`composer dev`) brings up the whole stack — server, queue worker, log tailer, and Vite.

---

## 11. Localization & formatting standards

ThirdLine targets a Nigerian financial context; adapt per deployment but keep the discipline:

- **Locale:** `en-NG`. Currency formatted as NGN (₦) via `formatCurrency(amount, 'NGN')`.
- **Dates:** displayed `DD MMM YYYY` (en-GB style, e.g. `25 Jan 2025`) via `formatDate`; ISO-8601 UTC on the wire.
- **Numbers:** locale-aware thousands separators via `formatNumber`.
- **Frontend helpers** live in `resources/js/utils.js`: `formatDate`, `formatDateTime`, `formatCurrency`, `formatNumber`, `classNames`, `daysUntil`, `truncate`. Use them rather than inline formatting so presentation stays consistent.

---

## 12. Scaffolding playbook — starting a new app

A step-by-step path to a new application that inherits this standard.

**1. Foundation**

1. `laravel new <app>`, then install Breeze with the React + Inertia stack.
2. Add packages: `spatie/laravel-permission`, `tightenco/ziggy`, plus any export packages you need (dompdf, phpspreadsheet).
3. Publish and run the Spatie permission migration.

**2. Inherit the design system**

4. Copy `resources/css/app.css` (tokens + component layer) verbatim; adjust `--color-*` only if the brand differs.
5. Copy `tailwind.config.js`, `postcss.config.js`, `vite.config.js`. Standardize the font on Inter (align `app.css` and Tailwind config).
6. Copy the `Components/` directory and both `Layouts/`. Drop in the logo SVG and favicon set; set `APP_NAME`/`VITE_APP_NAME`.

**3. Wire the frontend contract**

7. Copy `HandleInertiaRequests` so every page gets `auth.user/roles/permissions`, `flash`, and notification count.
8. Confirm `app.jsx` auto-resolves `Pages/`, mounts the flash toast, and (if needed) registers the offline service worker.

**4. Define the access model**

9. In `DatabaseSeeder`, define permissions as `"<verb> <resource>"` for your domain, then create roles and `syncPermissions`. Start from the seven-role template (two admin tiers, graded practitioners, read-only external roles) and rename to fit.
10. Seed reference data, then users (`assignRole`, `reports_to`).

**5. Build features (repeat per module)**

11. Migration(s): `id`, a human-readable business key (`PREFIX-YEAR-NNN`), foreign keys with constrained deletes, a `status` enum, `timestamps`, `softDeletes`.
12. Model: `HasFactory` + `SoftDeletes`, `$fillable`, `$casts`, relationships, scopes.
13. Route group gated by `role:...` in `web.php`, using `Route::resource` + named lifecycle actions.
14. Thin controller: eager-load, filter, `paginate(15)->withQueryString()`, `Inertia::render`.
15. Form Request for validation; Policy for model authorization (register in a provider) with a Super Admin `before()` bypass.
16. Service class for any real business logic.
17. Pages `Index/Create/Edit` composed from `PageHeader`, `FilterBar`, `DataTable`, `Pagination`, `StatusBadge`, `Modal`, and the form primitives with `useForm`.
18. Add a sidebar nav entry with an `allowedRoles` array.

**6. Quality gate**

19. Write feature tests using factories.
20. Run `composer check` (Pint + PHPStan) and `composer test`; then `npm run build`.

**Definition of done for a feature:** routes are role-gated; the controller scopes queries by ownership where relevant; a policy guards the model; validation is in a Form Request; the UI composes existing components and reflects permissions; formatting uses the shared helpers; tests pass and static analysis is clean.

---

*This standard reflects the ThirdLine codebase as of July 2026. When the codebase and this document disagree, update this document — it should stay the living source of truth for the family of applications.*
