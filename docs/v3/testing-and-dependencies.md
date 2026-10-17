# Chronos v3: regression testing and dependency migration

Status: planning baseline, 17.10.2026. Source inspected: commit `1d349f20a716eac975dcf0c52969af6a9c79ed63`. This document specifies work to implement after the migration decisions are agreed. It does not claim that tests, dependency upgrades, or a working v3 UI already exist.

## Existing verification and dependency management

The repository has no application test suite, root `package.json`, root `composer.json`, or application dependency lockfile. The only workflow, `.github/workflows/lint.yaml:1`, installs the runner's default PHP CLI and runs `php -l` over PHP files. This detects syntax errors, but cannot verify authentication, authorization, forms, API behavior, charts, database compatibility, upgrades, or responsive layout. A dependency's own configuration such as `libraries/include/RandomCompat/composer.json` is not an application test harness.

The runtime is Apache/PHP/MySQL with Git installation and self-update: `README.md:15`, `install.php:75`, `libraries/vcs.php:58`. Browser libraries are committed under `webroot/`; PHP libraries are copied into `libraries/`. Record exact source provenance and licenses before replacing copied code. Do not infer an upstream release version from copyright years or file dates.

## Inspected browser dependencies

Versions below are observations from the checked-in files, not assertions about the latest upstream releases. Resolve the current stable release, license, support policy, and compatibility from each maintainer's official registry/repository at implementation time, then pin exact versions in lockfiles. The target AdminLTE version must satisfy the user's requirement for the current release at implementation time.

| Dependency | Inspected version and evidence | Actual use | Proposed decision |
| --- | --- | --- | --- |
| AdminLTE | 2.4.2, `webroot/design/default/css/AdminLTE.min.css:1`, `webroot/design/default/js/app.js:1` | Shared navigation, panels, timeline, menus, login, skins | Replace with the current free AdminLTE release and its matching Bootstrap version. Build Semi-dark Navy styling locally. Implement missing widgets in the chosen framework. |
| Bootstrap | 3.3.4, `webroot/design/default/bootstrap/js/bootstrap.js:1` | All old views and dynamic templates | Replace as part of AdminLTE migration; port markup and behavior instead of retaining two Bootstrap majors in the final app. |
| jQuery | 3.7.1, `webroot/design/default/jquery/jquery-3.7.1.min.js:1` | Navigation, AJAX forms, dynamic builders, tables, chart initialization, polling | Remove once all old UI and extension consumers are migrated. Do not introduce it into the new framework solely for old widgets. |
| jQuery Validation | 1.19.2, `webroot/design/default/jquery/jquery.validate-1.19.2.min.js:1` | Experiment form (`views/builder/create.php:158`) and globally included (`webroot/design/default/header.php:54`) | Replace with framework form validation and equivalent server validation. |
| DataTables | 1.10.7, `webroot/assets/datatables/jquery.dataTables.min.js:1` | Projects, experiments/evaluations/jobs, systems, CEM; `webroot/assets/datatables/asset.php:8` | Replace with responsive framework tables or a maintained free table package. Preserve sorting, filtering, paging, row actions, and configured page sizes; these features are product behavior. |
| Chart.js | 2.9.3, `webroot/assets/chartjs/js/Chart.bundle.min.js:1` | All five bundled plot types | Upgrade to the current compatible stable Chart.js release and migrate configurations. Keep backend aggregation semantics under tests. |
| Chart.BoxPlot | No trustworthy version found in the bundled minified file, `webroot/assets/boxplot/js/Chart.BoxPlot.min.js:1` | Box plot (`libraries/graphs/boxplot/config.json:4`) | Replace with a maintained free box-plot extension compatible with the selected Chart.js release, or implement a custom renderer. Preserve orientation, statistics, insufficient-data state, tooltips and updates. |
| ansi_up | 4.0.3, `webroot/assets/ansi_js/js/ansi_up.js:37` | ANSI-colored job logs (`views/job/detail.php:29`) | Upgrade or replace with a maintained ANSI-to-DOM renderer; test escape handling and incremental output. |
| GitGraph | 1.15.1, `webroot/assets/gitgraph/gitgraph.min.js:1` | System VCS history (`controllers/admin.php:379`) | Replace with a maintained free graph renderer or custom responsive history graph/list. Preserve branches, commit metadata, order, and details. |
| iCheck | 1.0.1, `webroot/assets/icheck/icheck.min.js:1` | Login, user edit, administrator settings | Remove; use accessible native/framework checkbox and switch components with equivalent values and keyboard behavior. |
| Font Awesome Free | 5.12.1, `webroot/assets/font-awesome/css/font-awesome.min.css:1` | Shared icon asset (`webroot/design/default/assets.php:4`) and views | Consolidate to the icon library chosen for the current AdminLTE UI. Bundle only required free icons and licenses locally. |
| Ionicons | 2.0.1, `webroot/assets/ionicons/css/ionicons.min.css:1` | Shared icon asset (`webroot/design/default/assets.php:3`) and views | Remove after icon mapping; preserve meaning and accessible labels. |
| Source Sans Pro / Google Fonts | Remote CSS URL; no pinned font version, `webroot/design/default/header.php:42`, `views/user/login.php:40` | Login and global text styling | Remove remote CSS requests. Choose a system font or bundle licensed font files locally. |

DataTables also vendors extensions and examples not included by the application's asset loader: AutoFill 1.2.1, ColReorder 1.1.3, ColVis 1.1.2, FixedColumns 3.0.4, FixedHeader 2.1.2, KeyTable 1.2.1, Responsive 1.0.6, Scroller 1.2.2, and TableTools 2.2.4. Their versions are declared in `webroot/assets/datatables/extensions/<name>/js/dataTables.*.js`. There are also TableTools SWF files. No first-party UI include of these extensions was found. Remove unused distributions/examples once extension use has been checked against the supported installed systems; do not mistake a vendored Responsive extension for verified mobile support.

Gravatar is an external image service rather than a CDN dependency (`libraries/gravatar.php:42`, `webroot/design/default/navbar.php:51`). Decide whether to retain it explicitly or replace it with local initials/avatar rendering. Test that unavailable external images never prevent login or navigation. The requested no-CDN policy must at least cover every CSS, JavaScript, font, icon, and chart dependency.

## Inspected PHP dependencies and runtime requirements

| Component | Inspected evidence | Proposed treatment |
| --- | --- | --- |
| RandomCompat | 2.0.10 in `libraries/include/RandomCompat/lib/random.php:6`; only loaded for PHP below 7 in `libraries/auth.php:33` | Remove when the supported PHP floor is agreed; even the documented PHP 7.4 minimum already provides `random_bytes` and `random_int`. Keep remember-me behavior covered. |
| ZipStream | `VERSION = '0.3.0'` in `libraries/zip/src/ZipStream.php:77` | Upgrade to a maintained release compatible with the agreed PHP floor; adapt the wrapper (`libraries/zip.php:7`) and test streaming headers, contents, memory behavior and missing archives. Do not silently replace binary downloads with JSON. |
| PSR logging interfaces | Unversioned copied `libraries/include/Psr/Log/*`; loaded by `libraries/logger.php:29` | Adopt a pinned `psr/log` dependency and update the logger's method signatures for the selected major/PHP floor. Keep file naming, filtering, context and output behavior. |
| Logger implementation | Adapted local class, upstream attribution but no pinned release, `libraries/logger.php:33` | Treat as application-owned code unless a full maintained replacement is deliberately chosen. Test runtime compatibility and preserve useful operational logging. |
| DBA | Local bundled framework, `DBA_VERSION = '1.0.0'`, `libraries/dba/init.php:63` | Treat as owned code, not an automatically upgradeable package. Characterize PDO/query/model behavior on the selected PHP/MySQL versions before any replacement. |
| Templating | Local `libraries/templating/{Template,Statement}.class.php`, no release declared | Remove the old UI rendering consumers only after parity; preserve required backend configuration processing and define extension migration before deleting templates. |
| Settings file driver | `libraries/include/Settings/drivers/driver.file.php:28`; no first-party `SettingsDriver` caller found | Verify no external system relies on it, then remove if unused. Actual application settings are database-backed (`libraries/settings.php:85`). |
| Native PHP extensions/tools | PDO/MySQL (`install.php:120`, DBA), DOM (`libraries/cdl.php:41`), cURL (`libraries/rest.php:219`, `libraries/maas.php:34`), filesystem/session/JSON/cryptography, Git/Mercurial and process execution (`libraries/vcs.php`) | Document and preflight the complete selected runtime. An installer and CI PHP version need explicit pinning/matrix coverage. Audit upload/mount features for any additional installation-specific extensions or commands. |

Use Composer and a committed lockfile if external PHP packages remain. Build a production `vendor/` artifact in CI; production installation must not require Composer unless the user explicitly selects that deployment model. A new PHP runtime floor needs an explicit decision because “update all dependencies” can make continued PHP 7.4 support impossible. Do not claim compatibility from syntax lint alone.

## Extension compatibility is a migration gate

Parameter controls and plots are plugins, not just fixed forms:

* Default controls are scanned from `libraries/elements/` (`core/util.php:234`), then per-system controls are added from `webroot/systems/<id>/parameters` (`core/system.php:79`). An element has a config, arbitrary build/render HTML, an optional PHP processor, and optional copy logic (`core/element.php:47`, `core/element.php:77`, `core/element.php:110`).
* Plots likewise declare required assets and provide PHP processing plus HTML/JavaScript rendering (`core/plot.php:72`, `core/plot.php:79`). Results combine overall plots and grouped per-job plots (`libraries/results.php:245`).
* `System::getResultPlots($plots)` currently receives its array by value (`core/system.php:90`), so appended custom plots do not reach the caller. This is a code-level defect to characterize, not a reason to remove the intended extension capability. Custom type collisions also need a defined policy; existing loops do not consistently express override precedence.

Before final removal of the PHP UI, inventory the actual supported system repositories and agree whether each custom control/plot will migrate to a versioned JSON schema plus framework renderer, or a documented extension API. A safe default is server-side processor retention with explicit data schemas and frontend renderer registration. Arbitrary old HTML/inline script fragments cannot be assumed to run safely or correctly as framework components. A temporary compatibility adapter must be an explicit transitional decision, with a removal test and no hidden dependency on Bootstrap 3/jQuery in the final UI.

## Test data and isolation

Create disposable test installations for both the pinned legacy commit and the v3 branch using Apache with mod_rewrite, PHP, and MySQL. Use separate databases, temporary filesystem roots and local fixture VCS repositories. Never point tests at an existing installation, production database, configured CEM/MAAS host, network mount, or real evaluation agent. All services with side effects need local fakes or controlled disposable services.

Seed deterministic synthetic data from a fixture builder rather than reusing `libraries/demo/demo.sql`, whose historical, large result records are hard to review and do not cover the required permission and boundary cases. The fixture builder should use stable identifiers while allowing separate old/new database IDs; compare semantic records after ID mapping. Fixed UTC instants and an explicit display timezone make time assertions reproducible.

| Fixture | Required contents |
| --- | --- |
| `FX-IDENTITY` | Administrator, super-administrator if distinct in effective access, ordinary owner, project collaborator, unrelated user, disabled user, unactivated user, remembered session and expired session. All credentials synthetic. |
| `FX-SYSTEMS` | Local-only system without VCS; local Git system with two branches, merge history and tagged revisions; Mercurial fixture if support remains; two environments; defaults/settings; logo; a deliberately incomplete system; one custom parameter plugin and one custom plot. |
| `FX-PROJECTS` | Owned project, shared project, unrelated private project, empty project, archived/deleted states as supported. Long English text, punctuation, Unicode and HTML-like strings. |
| `FX-BUILDER` | At least one instance of every bundled control; two instances of the same control type; dependent group chain; zero/false/empty defaults; scalar and multi-job dependencies; two software-version fields; copied historical experiment data. |
| `FX-EVALUATIONS` | Empty, scheduled, running, partly complete, complete, aborted, failed, suspended/locked states as supported; every phase-mask combination; repeated runs; result presets; environments from system and CEM. |
| `FX-RESULTS` | Two varying parameters, three or more runs per configuration, scalar results, numeric strings, arrays, unequal array lengths, zero/negative/decimal values, missing metric in some runs, invalid JSON, and outliers. Known independent expected averages and statistics. |
| `FX-ARTIFACTS` | Small valid per-job ZIP files with fixed entries/content, empty ZIP, missing ZIP, UTF-8 filenames where supported, log containing ANSI colors, incomplete escape sequences, HTML-like text and a sufficiently large log. Large-file download test separate from normal CI. |
| `FX-CEM` | Healthy/busy/missing nodes, unknown health, outdated agent version, zero and full memory, CPU boundary values, multiple environments and deterministic last-update times. |
| `FX-SCALE` | More rows than every configured page size, long names, wide parameter/result tables, many groups/plots. Keep normal tests small; use an explicit large dataset for rendering/performance checks. |

The fixture reset must recreate database and filesystem state together. Authentication state should be stored outside source control. A test-only seeding interface must be unavailable in production. Compare every mutating operation's database/filesystem effects, not only toast messages.

## Executable test roadmap

The following paths/commands are proposed contracts to create in the testing implementation slice; they do not exist yet. Each feature in the separate UI inventory should map to one or more stable test IDs, permission cases, fixture, layer, expected result, and final evidence. Use an explicit `pending` state until implemented; a written test case is not a passing test.

1. Add `tests/fixtures/`, a deterministic fixture command, disposable Apache/PHP/MySQL orchestration, and a test configuration with environment-only secrets. Publish a single `scripts/test-stack` entry point that starts isolated services and cleans them on success or failure.
2. Add PHP domain characterization tests (for example PHPUnit after agreeing the PHP floor), test helpers for Element/Plot processors, and a database integration suite. Proposed commands: `composer test:unit` and `composer test:integration`.
3. Add API tests against the real disposable HTTP server for authentication, permissions, validation, mutation and binary response contracts. Proposed command: `composer test:api`. A versioned API schema should be checked and used by frontend contract tests; raw HTTP assertions remain necessary for cookies, status codes and downloads.
4. Add Playwright browser tests with separate legacy/v3 base URLs and common scenario semantics. Proposed commands: `npm run test:e2e:legacy`, `npm run test:e2e:v3`, and `npm run test:parity`. Route/locator adapters may differ while expected business results remain shared. Avoid snapshots of entire volatile pages as a proxy for function coverage.
5. Add component tests for interactive controls and loading/error states, type-check/lint/build commands, and accessibility checks. Proposed commands: `npm run test:components`, `npm run typecheck`, `npm run lint`, and `npm run build`.
6. Add a release smoke suite that installs the built artifact from scratch and upgrades a legacy fixture installation without Node/Composer. Proposed command: `scripts/test-release`. The suite also verifies back/forward/deep links, every local asset, UI version updates and agent compatibility.

### High-risk domain characterization

| Test IDs | Required assertions and source evidence |
| --- | --- |
| `BLD-001`–`BLD-012` | One case per bundled type: boolean, checkbox-selection, dropdown, integer, interval, percentage-dep, percentage-interval, percentage, ratio, software-versions-list, string-list, string. Assert entered/default/copied values, serialized names/types and generated configurations (`libraries/elements/*`). |
| `BLD-020` | Cartesian products, exact job count, parameter types and ordering across multiple multi-job elements (`controllers/builder.php:113`). Keep an independently calculated expected list for a small fixture. |
| `BLD-021` | Run distributions `order`, `alter`, `rand`; deterministic expected order for the first two and same multiset/no loss/no duplicates beyond configured repeats for random (`controllers/builder.php:131`, `libraries/builder.php:43`). |
| `BLD-022` | Runs 0/negative/non-numeric, job-limit exactly reached and exceeded, no jobs, enormous products rejected before side effects. Assert no partial evaluation/jobs/events after a validation failure (`controllers/builder.php:154`). |
| `BLD-023` | Interval inclusive min/max, non-dividing steps, equal endpoints, zero step, negative step, reversed bounds and integer coercion (`libraries/elements/interval/process.php:17`). Run legacy pathological inputs only in a bounded subprocess to avoid an infinite loop. |
| `BLD-024` | Ratio `0:100` → `100:0`, valid step, `%` notation, bad totals, wrong parameter count and reversed bounds (`libraries/elements/ratio/process.php:19`). |
| `BLD-025` | Percentage dependency on earlier multi-job values, integer floor versus float, missing dependency, zero/empty/negative/non-numeric values. The simple percentage relies on browser-computed absolute values, whereas percentage-dep computes server-side; v3 must validate or compute authoritative values server-side (`libraries/elements/percentage/render.template.html:13`, `libraries/elements/percentage-dep/process.php:17`). |
| `BLD-026` | Linked percentage intervals follow the same step instead of multiplying independently; sums, step signs, equal endpoints and invalid dependencies (`libraries/elements/percentage-interval/process.php:21`). |
| `BLD-027` | Checkbox selection none/one/many; string lists with whitespace, repeated/empty/trailing values. Record the current lack of trimming/deduplication rather than silently changing experiment semantics (`libraries/elements/string-list/process.php:17`). |
| `BLD-028` | Software version count add/remove, two independent version controls, copy legacy comma-separated values, override branches for a new run. Assert exact jobs and no debug output (`libraries/elements/software-version-list/*`, `controllers/builder.php:95`). |
| `BLD-029` | Dependent group visibility/enabled state, nested dependency propagation, initial copied value and omission of hidden inputs (`libraries/builder.php:142`). |
| `BLD-030` | All 32 combinations of five excluded phases survive create/copy/run; environment validation and assignment, descriptions, internal sequence, run-independent configuration identifiers and title grouping (`libraries/evaluation.php:41`, `core/util.php:270`). |
| `PLT-001`–`PLT-005` | Bar, pie, line, multi-parameter line, boxplot: assert normalized labels, series, values, grouping, orientation, axis options, tooltip precision and empty/error states. Compare data first, then visual output (`libraries/graphs/*`). |
| `PLT-010` | Mean aggregation across runs; array element averages and unequal-length handling; omission/missing metrics; more than three series; labels omit `run` as intended (`libraries/graphs/line-plot/process.php:27`). |
| `PLT-011` | Multi-parameter grouping does not duplicate metric contributions; independent expected numbers catch coupled legacy/new bugs (`libraries/graphs/line-plot-multi/process.php:49`). |
| `PLT-012` | Boxplot under three runs shows the warning and later transitions to a chart; odd/even samples, quartiles, median rounding and whisker/outlier policy (`libraries/results.php:115`, `libraries/graphs/boxplot/process.php:60`). Agree any statistical correction explicitly; changing the chart library must not silently change research results. |
| `PLT-013` | Live completion count changes refresh the relevant datasets without duplicate charts; transient network failure recovers and route exit stops polling (`libraries/results.php:203`). |
| `DL-001` | Completed evaluation download is a valid outer ZIP named `evaluation<id>.zip`, containing each job ZIP under `<jobId>.zip`; compare entries/content, not timestamps/compression bytes (`controllers/evaluation.php:209`, `views/evaluation/download.php:30`). |
| `DL-002` | Incomplete/unauthorized/missing evaluation and missing artifact produce a deliberate error without corrupt ZIP output; large archive transfer does not exhaust PHP memory. |
| `EXT-001` | Custom control can be configured, used, copied and executed; unknown type/config gives a useful recoverable error; duplicate identifiers follow the agreed policy. |
| `EXT-002` | Custom plot discovery and data/render schema work; characterize the old by-value bug separately and test the accepted repair. |

### Browser and API coverage

For every UI inventory operation, test the normal path, displayed validation failures, cancellation, duplicate submission, loading state, server rejection and persisted outcome. Prioritize these shared scenarios:

| Test IDs | Coverage |
| --- | --- |
| `AUTH-001`–`AUTH-010` | Login success/failure, remember me, refresh/deep link, expired session, logout, inactive/unactivated identity, password change, user switching and return to real identity. |
| `ACL-001` | Cross every resource read/mutation/download with anonymous, unrelated user, collaborator, owner and administrator. Compare the UI's affordances with actual API enforcement; hiding buttons is insufficient. |
| `API-001` | Versioned response schemas, real HTTP status codes/content types, stable error format, malformed JSON, unexpected fields, input limits and URL/path parameters. |
| `API-002` | Cookie/session behavior, CSRF protection on every browser mutation, unsafe request rejection and harmless behavior of GET/HEAD where defined. Existing vulnerabilities are defects to fix, not successful parity expectations. |
| `CRUD-001` | Systems, projects, experiments, evaluations, jobs, users and settings: create/read/edit/delete or applicable archive operations, events and dependent-record effects according to the inventory. |
| `TABLE-001` | Search, sort numeric/date/text columns, pagination, configured page size, empty states, row links/actions and refresh while retaining useful state. |
| `OPS-001` | System import/export, VCS history/branch/update, application update, mount, CEM and MAAS workflows using local fakes. No real infrastructure side effects. |
| `LOG-001` | Live job log, ANSI color, safe text rendering, append updates, scroll behavior and reconnect; result/artifact links still resolve. |
| `NAV-001` | Direct links to each resource, reload, back/forward, unknown route, forbidden route, expired login redirect and preserved intended destination. |
| `LAYOUT-001` | Menu/sidebar, tables, all forms, builders, charts, modals, confirmations, login and logo at 320/375/390/768/1024/1440 px widths, portrait and landscape where meaningful. No clipped primary actions or page-wide overflow; wide data may use labeled local scrolling. |
| `A11Y-001` | Keyboard navigation/focus, form labels/error associations, dialog focus return, status announcements, 200% zoom, usable contrast and touch targets. Do not disable pinch zoom as the old viewport does (`webroot/design/default/header.php:41`). |
| `TIME-001` | Visible dates `dd.MM.yyyy`, times `HH:mm` or `HH:mm:ss` where precision matters, explicit timezone, DST transition and date sorting. English labels/docs remain English; machine API timestamps should be unambiguous and formatted only at display boundaries. |
| `ASSET-001` | New UI loads with all non-origin asset requests blocked. Assert no requests for CDN fonts/scripts/styles/icons and no missing local bundle assets. Handle optional Gravatar according to the agreed decision. |
| `VER-001` | A tab running build A detects server build B with an uncached request, shows a bottom-centered accessible notice and reload button, and only reloads when the user acts. Same-version check is quiet; no automatic reload/data loss while editing. |
| `VER-002` | Offline/error detection is quiet/retryable; multiple tabs, backend restart, retained old chunks, failed deploy and a second update behave predictably. The reload reaches build B even through cache. |

Run desktop Chromium, Firefox and WebKit plus mobile browser emulation. Emulation verifies responsive behavior but does not replace at least one real iOS Safari and Android Chrome acceptance pass if those devices are available. Record tested engine/OS/version, viewport and evidence; disclose any missing real-device coverage rather than calling an emulation run a physical device test.

## Legacy/new comparison and defect policy

Run the pinned legacy build and v3 from the same synthetic starting state, with equal roles and inputs. Build a parity ledger linking each inventory feature to test IDs and evidence. For reads, compare normalized records, visible meaning, counts, enabled actions and result datasets. For writes, compare database rows, files, events, generated jobs and authorization outcomes. Ignore implementation details such as CSS classes, framework DOM structure, generated IDs, ZIP compression or random ordering where these have no product meaning.

Use screenshots to review layout and intended design changes, not to demand pixel equality with the old UI. Check actual numeric chart data and independently calculated expected results; matching two identical wrong implementations is not correctness. Add approved improvements as explicit new expectations in the ledger.

Record existing failures as baseline defects with a minimal reproducer and proposed corrected behavior. Examples found by static inspection include the custom plot by-value issue, global DOM IDs and `const copy` in multiple software-version controls (`libraries/elements/software-version-list/render.template.html:10`), debug `var_dump` output (`controllers/builder.php:103`), negative interval steps that can fail to terminate, and multi-parameter plot loops that may double-count a metric. These are test targets, not claims of runtime reproduction. Do not freeze unsafe behavior, infinite loops or incorrect statistics as mandatory v3 behavior.

Removal gate: every available legacy feature is mapped, all approved functional expectations pass, defects are resolved or explicitly agreed, browser/API/domain tests pass, install/upgrade/agent smoke tests pass, and the parity ledger has no unexplained omissions. Only then delete legacy routes, PHP UI views/templates, old assets and obsolete dependencies. Preserve historical data/configuration import capability where required.

## Build, release and self-update without production Node

The existing installer clones a selected branch (`install.php:107`) and the self-updater checks out and pulls that branch (`libraries/vcs.php:78`). Shipping only frontend source would break these installations. Keep Node and frontend package resolution confined to development/CI, and ship a tested static production UI plus its matching PHP/API code.

Recommended release contract:

1. Pin Node/build tools, frontend dependencies and PHP dependencies in the source tree. CI performs clean locked installs, tests and a production build; include free third-party licenses and dependency provenance in the artifact.
2. Produce an immutable, checksummed release archive containing PHP code, production PHP dependencies if used, built HTML/CSS/JS/fonts/icons/logo, API contract/version metadata and installation/upgrade instructions. Exclude node_modules, caches, development secrets, uploaded data, logs, test traces and local IDE files.
3. For the initial v3 transition, preserve branch-based installation/update by committing generated production assets to the release branch/tag the updater actually consumes, or explicitly implement artifact download/extraction in the PHP updater. Choose one before implementing the UI; the existing updater cannot consume an attached archive automatically. If a separate release branch is chosen, document source-to-release traceability and keep normal source PRs reviewable.
4. Upgrade in a staging directory, verify the complete artifact and compatible PHP requirements, apply versioned database migrations with a documented backup/rollback policy, then switch the served release atomically. Avoid a new index/API serving against half-updated assets during an in-place Git pull. Keep `config.php`, system repositories, uploaded data, logs and runtime state outside replacement paths or explicitly preserve them.
5. Include a build identifier known to both the loaded UI and an authenticated or appropriately public lightweight version endpoint. Return `Cache-Control: no-store` for version metadata and the entry document; use content hashes and long immutable caching for bundle assets. Keep the previous release's hashed chunks long enough for existing tabs until update/reload. A service worker is optional and should be omitted unless there is a clear requirement and tested cache/update strategy.
6. The new UI polls version metadata at a modest interval and when returning to the tab, pauses unnecessary work in background, handles failures quietly and offers the bottom-centered reload action. No forced refresh. Preserve or warn about unsaved changes when the user chooses reload.
7. CI proves a clean installation and a legacy-to-v3 upgrade using the delivered production artifact on Apache/PHP/MySQL with Node/Composer absent. Verify existing system config files, remembered sessions where intended, data, downloads, CEM/agent protocol compatibility and self-update selection. Test rollback with a prior static asset/API contract pair.

Dependency updates should be separate reviewable slices where possible: test/fixture baseline; PHP compatibility/packages; locked frontend build/AdminLTE; API and feature migrations; parity fixes; release/update support; legacy cleanup. Automated security/license checks and scheduled update PRs can follow once reproducible manifests exist. Avoid treating a bulk file replacement as proof that every dependency is current or compatible.

## CI acceptance stages

Every PR should run PHP lint on supported versions, domain/integration/API tests, frontend locked install/type/lint/component tests/build, and the browser scenarios relevant to the change. Run the full parity suite before removal, plus release/install/upgrade checks on release candidates. Preserve failure-only screenshots, traces, relevant logs, schema diffs and test results as CI artifacts; never commit them to the repository. Label destructive infrastructure scenarios separately and execute them only against disposable fakes.

The planning PR may contain this roadmap without executable tests. Later PRs must report what actually ran, the exact versions tested, feature IDs covered and remaining gaps. “Everything works” is not a verifiable acceptance record.
