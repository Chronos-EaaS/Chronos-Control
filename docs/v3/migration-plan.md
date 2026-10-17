# Chronos 3 responsive UI migration

Status: planning and source audit; implementation and runtime parity are not yet complete.
Planning date: 17.10.2026. Baseline: `master` at
`1d349f20a716eac975dcf0c52969af6a9c79ed63` (`APP_VERSION=2.1-dev`).

## Goal and completion criteria

Replace the complete PHP-generated Chronos interface with an Angular application
using the current free AdminLTE release and a new PHP JSON API. Preserve every
supported workflow, including system-defined extensions, while making the UI usable
on phones, tablets, and desktops. This work is the first part of version 3; a
planning pull request does not mean that version 3 is ready to use.

The migration is complete only when:

- Every feature in the [UI inventory](ui-feature-inventory.md) has an implementation
  and passing behavioral tests, with intentional defect corrections documented.
- The browser tests run against the real PHP API and a disposable MySQL database;
  frontend mocks alone do not establish parity.
- Existing agent and CEM clients continue to work through their existing APIs.
- AdminLTE and all shipped dependencies are updated, replaced, or removed with
  compatibility, licenses, and vulnerability checks recorded.
- Desktop, tablet, and phone workflows pass across Chromium, Firefox, and WebKit.
  Device emulation and physical-device testing are reported separately.
- All application assets are served locally, the semi-dark navy design is applied,
  and visible dates and times use German notation.
- The stale-version notice, login, branding, accessibility, error states, and
  installation/update/rollback flows are verified.
- The legacy UI and its unused assets are removed only after the parity gate passes.
- Documentation, CI, review, sensible commits, and GitHub pull requests are complete.

## Confirmed requirements and decisions

| Topic | Decision |
| --- | --- |
| Frontend | Angular with TypeScript; confirmed by Marco on 17.10.2026. |
| Styling | Current free AdminLTE, semi-dark navy. Light content with navy navigation/header is the initial interpretation. |
| Builds | Node.js is allowed in development and CI. Production receives built assets and does not require Node.js. |
| Backend | Retain PHP/MySQL/Apache for this migration and introduce a separate JSON API. |
| Assets | No runtime CDNs; include fonts, icons, chart assets, CSS, and JavaScript locally. |
| Language | English UI, source, documentation, errors, and test names. User-facing planning discussion may remain German. |
| Date display | `DD.MM.YYYY`, 24-hour `HH:mm` or `HH:mm:ss`; consistent in tables, forms, charts, tooltips, and human-readable exports. |
| Testing | Isolated local installation, synthetic data, simulated agent. Real-agent testing must be agreed later. |
| Runtime compatibility | PHP 8.3 and the existing MySQL 8.0 installation remain supported; confirmed on 17.10.2026. |
| Timezone | `Europe/Zurich` is authoritative. Inspect historical storage before any conversion; confirmed on 17.10.2026. |
| Distribution | CI-built packages; adapt the installer and self-update, preserving branch selection and adding rollback; confirmed on 17.10.2026. |
| Feature scope | Preserve all working capabilities. Improvements are welcome; material ambiguous behavior changes are discussed first. |
| Git | Work on `codex/` branches; small coherent commits and pull requests; keep generated noise and local data out of the source repository. |
| Release | Prepare pull requests; merging or deploying version 3 is a separate action. |

The official latest AdminLTE release inspected on 17.10.2026 is
[4.10.1](https://github.com/ColorlibHQ/AdminLTE/releases/tag/v4.10.1).
[Angular's compatibility table](https://angular.dev/reference/versions) currently
lists Angular 22 as actively supported. Resolve exact package versions from official
registries at implementation time and lock compatible stable versions together;
do not select pre-releases or force incompatible peer dependencies to satisfy a
literal interpretation of "latest".

## Questions that affect the implementation

| ID | Question | Proposed direction | State |
| --- | --- | --- | --- |
| D-01 | May the deployed system extensions be inventoried read-only, or will sanitized examples be supplied? | Inspect files/configuration without invoking application getters or changing production data; derive synthetic fixtures. | Read-only inventory authorized and completed; fixture creation follows. |
| D-02 | Which minimum PHP/database versions must version 3 support? | Keep PHP 8.3 and the existing MySQL 8.0 deployment working; test newer supported PHP versions too. | Confirmed. |
| D-03 | How are legacy database/file timestamps interpreted, and which display timezone is authoritative? | `Europe/Zurich`; preserve legacy local wall times pending evidence for any conversion. | Timezone confirmed; source and deployment metadata inspected. |
| D-04 | How should source checkouts receive production frontend assets while keeping self-update usable? | CI-built distributions containing source, PHP dependencies, and compiled UI; preserve branch selection and add rollback. | Confirmed; implement artifact availability/integrity and upgrade semantics in slice 6. |
| D-05 | Are there additional required browser/device versions or institutional accessibility requirements? | Current supported desktop browsers plus iOS Safari/Android Chrome; keyboard access and WCAG 2.2 AA as the initial target. | Default pending contrary requirements. |
| D-06 | Which existing custom element/plot/page extensions require migration, and who owns changes in their repositories? | No extension silently omitted; document and test a versioned extension contract. | No custom extension directories/pages observed in the inspected installation; general extension support remains in scope. |

Compatibility defaults: retain existing agent/CEM protocol, account roles, project
membership behavior, administrator impersonation, system import/export and VCS
operations, superadministrator maintenance, browser self-update, and installer
capabilities. Fix unauthorized access and data-loss defects instead of reproducing
them as parity requirements. Do not remove a capability simply because it is
inconvenient to implement in the new frontend.

## Architecture

### Frontend

Use Angular standalone components, typed forms, a router, and a typed API client.
Separate account, overview, projects, experiments, evaluations/jobs, systems,
configuration builders, results, CEM, and administration into route-level features.
Use AdminLTE layout and Bootstrap-compatible components without letting direct DOM
plugins and Angular both own the same component state. Provide accessible native
or Angular components when the free template lacks a required widget.

Use reusable table, confirmation, alert, status, pagination, date, file-upload,
parameter-field, chart, and empty/loading/error components. Long tables may scroll
within their container while essential row actions stay available on narrow
screens. Menus, dialogs, touch targets, keyboard focus, and chart legends must be
tested at mobile widths. Charts must have an accessible data representation.

Parameter and result editors must consume versioned schemas, not HTML fragments
returned by PHP. Preserve dependent groups, copied experiment values, all built-in
parameter types, multiple result configurations, per-job and overall plots, and
system-provided extension behavior. Sanitized extension fixtures are a prerequisite
for claiming complete coverage.

### Backend and API

Add `/api/v3` alongside the frozen agent/CEM `/api/v1` contract. Do not globally
replace the old request parser or response envelope. Use explicit routes and
methods, typed validation, consistent JSON errors, pagination/filter/sort contracts,
and a documented OpenAPI specification for the new UI API.

Extract domain services and centralized policies from HTML controllers. Enforce
authentication, role and object access **before** reading protected resources or
performing mutations. Use same-origin server sessions, secure cookies, session-ID
rotation, CSRF protection for state changes, and explicit JSON `401`/`403`
responses. Avoid mutating GET requests. Do not expose existing public UI mutation
handlers as the new API.

Preserve experiment/job-generation algorithms, CDL/XML, identifiers, scheduling,
phases, logs, results, files, and agent status conventions. Introduce explicit
filesystem/process boundaries for Git, Mercurial, mounts, updates, archives, and
reset operations so tests can isolate these effects. Multi-record operations need
an explicit transaction/cleanup strategy.

See [backend and API audit](backend-and-api.md) for source references and contracts.

### Date and time

Separate stored timestamps, API transport timestamps, timezone interpretation, and
display formatting. Do not reinterpret legacy naive datetimes as UTC without
evidence. Document old storage conventions first. New API timestamps should carry
an unambiguous offset/UTC convention; the UI formats them with explicit locale and
timezone settings. Preserve machine-readable agent/import/export formats where
changing them would break compatibility. Include daylight-saving transition tests.

Read-only checks on 17.10.2026 found that the deployment OS uses `Europe/Zurich`,
MySQL global/session timezone is `SYSTEM` (currently `CEST`), and relevant timestamp
columns are `DATETIME`. Source writes use PHP local `date()` values after setting
`Europe/Berlin`. These observations support preserving local wall times; a dump's
`SET time_zone = '+00:00'` does not prove that stored naive values represent UTC.
No database timestamps were changed. Record ambiguous historical instants in the
compatibility strategy rather than guessing an offset during migration.

### Stale-browser notice

Generate an immutable build identifier from the release/source revision. Expose
current frontend version and compatible API contract through a small same-origin
version endpoint with caching disabled. Check at startup, at a bounded interval,
and when a tab becomes visible again. A failed/offline check must not falsely show
an update or block work.

When the current server build differs from the loaded build, show a fixed,
bottom-centered English notice with a `Load latest version` button. Keep it inside
phone safe areas, accessible by keyboard, announced appropriately, and clear of
important controls. Never automatically reload an active form. If work is unsaved,
ask before discarding it when the user clicks reload. Fetch a fresh application
shell; hashed assets are immutable, while the shell/version endpoint are
revalidated. Test two deployed builds and API compatibility transitions, including
an older browser tab and failed network checks.

### Branding

Improve the existing Chronos identity with an accessible SVG mark and wordmark,
using the navy palette, suitable contrast, compact navigation, favicon, and a
responsive login screen. Keep the identity recognizable. A vector asset fits this
task and avoids adding a raster-generation dependency. Preserve return-to-page,
remember-me, account validation, logout, and impersonation behavior.

## Delivery slices and pull requests

Each slice should be independently reviewable; separate an intentional behavior
change from unrelated formatting or dependency churn. Large feature slices may
contain several coherent commits. Do not mark a slice complete merely because a
stub page, source scan, or mocked frontend test exists.

| Slice | Deliverable | Required evidence |
| --- | --- | --- |
| 0 | Migration plan, detailed source inventory, risks, decisions, test strategy, dependency baseline. | Reviewed source references and explicit outstanding questions; planning PR. |
| 1 | Reproducible isolated legacy installation, synthetic systems/results, test harness, complete feature-to-test matrix. | Behavioral tests exercising each old UI function; expected legacy defects recorded separately. |
| 2 | PHP service/policy extraction and `/api/v3` contracts. | Unit/integration/authorization tests; legacy agent/CEM contract tests remain green. |
| 3 | Angular/AdminLTE foundation, local assets, login, navigation, navy theme, date utilities, version notice. | Production build, component tests, mobile/desktop/browser/accessibility checks. |
| 4 | Projects, membership, experiments, evaluation scheduling/status, jobs, logs and downloads. | Same synthetic workflows pass against old and new implementations; real API tests. |
| 5 | Systems, schema-driven parameter/result editors, all plots and extension adapters. | Golden job/CDL/result fixtures; import/export round trips; extension test fixtures. |
| 6 | CEM, users/settings, impersonation, maintenance, installer/self-update, release distribution. | Role matrix, simulated CEM/agent scenarios, isolated process tests, installation/update/rollback tests. |
| 7 | Dependency consolidation, final parity review, cleanup and legacy UI removal. | Complete test matrix, clean builds and repository, no old UI routes/assets required, reviewable final PR. |

Slices may be reordered where dependencies permit. Maintain a progress ledger and
link each feature ID to its implementation, tests, observed result, and pull
request. Baseline behavior tests should remain usable after cleanup by referencing
the immutable old revision in an isolated fixture checkout, rather than leaving
legacy presentation code in the shipped version.

## Test and parity gates

1. Inventory tracked screens and dynamic extensions; record intended behavior and
   known defects separately. Include error, empty, permission, and recovery paths.
2. Create deterministic synthetic data for all roles and ownership relationships,
   parameter types, graph types, job states, uploads and CEM phases.
3. Exercise the old UI with browser tests and characterize domain outputs. Save
   expected values, files, generated jobs/CDL, and result series rather than relying
   on screenshot similarity alone.
4. Run the same intent-level workflows against the new UI and actual API. Compare
   persisted effects, generated files, permissions, and result calculations.
5. Run responsive/browser/accessibility checks and inspect representative screenshots.
   Include 360px and 390px phones, 768px tablets, and 1280px/1440px desktops, both
   orientations where meaningful, zoom, slow networks, and long content.
6. Verify v1 client contracts with simulated agent/CEM processes; coordinate real
   agent and physical-device acceptance separately and report any unperformed tests.
7. Test fresh install, old-to-new upgrade, stale tabs, self-update, and rollback with
   disposable storage. Only then remove the old UI.

The detailed [testing and dependency plan](testing-and-dependencies.md) tracks the
baseline and proposed checks. Zero known test failures is a release gate; no finite
test suite is a promise that all possible failures have been eliminated.

## Build, dependencies, and repository hygiene

Introduce lockfiles and reproducible installs. Inventory actual use before updating
bundled libraries; replace abandoned plugins with maintained packages or focused
components. Remove obsolete demos, duplicate minified/source copies, fonts and
assets only once usage is removed. Keep license notices. Record exact versions and
intentional compatibility constraints in the dependency report.

Keep `node_modules`, local Composer installs, caches, test reports, screenshots,
uploaded data, credentials, database dumps, logs and local configuration ignored.
Publish compiled UI and required PHP dependencies in deterministic release
artifacts so production can install/update without development toolchains. Finalize
how Git-based source installations obtain matching artifacts before changing the
installer or browser self-update. A source-only `git pull` must not leave a broken
application or mismatched UI/API versions.

Marco selected package distribution on 17.10.2026. Source PRs do not commit built
frontend bundles. The replacement updater must resolve packages for supported
branches/revisions, verify their provenance/integrity, stage the complete matching
backend/frontend build, preserve local state, and offer rollback. Missing or failed
builds must be reported before changing the running installation.

Do not add production deployment notes, private hosts, SSH material, production
configuration, database dumps, or user data to the public repository. Development
and tests must not read a production `config.php`. Existing workspace deployment
notes stay outside this clone.

## Current audit status

- Upstream fetched with the user's authorized GitHub SSH access; baseline revision
  matches the existing local source and documented deployment revision.
- Dedicated clean working clone created under the project workspace; the existing
  source checkout and deployment records are unchanged.
- Source audits cover UI, backend/API, extension boundaries, test gaps, dependencies,
  and installation/update behavior.
- An authorized read-only file inventory found all built-in parameter and plot
  types in use, older result configuration formats still present, and no installed
  custom parameter/plot directories, custom root PHP pages or system assets.
  Only structural metadata was inspected; no application getter/migration was run.
- No application implementation, production changes, browser parity testing, or
  dependency upgrades have been performed in this planning slice.
- Full feature parity remains unverified. The overall goal remains active.
