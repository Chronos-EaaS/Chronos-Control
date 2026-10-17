# Chronos v3 backend and API migration assessment

Status: planning, source audit, and an authorized read-only inventory of deployed extension files. No application workflows, database mutations, agent execution, installer, configuration conversion, or production changes were performed for this assessment. Source findings and file inventory observations are not runtime validation results.

Audited baseline: `1d349f20a716eac975dcf0c52969af6a9c79ed63`. Source references below are relative to the repository root and refer to that baseline. No applicable `AGENTS.md` was found during the audit.

## Confirmed direction and pending decisions

Confirmed for this migration:

- Angular will provide the new UI. PHP-generated presentation must be replaced with Angular components and JSON contracts.
- The supported PHP minimum is 8.3. The existing MySQL 8.0 installation must remain compatible; support for additional database engines or versions is not implied.
- Node.js is available in development and CI only. Production must run the built frontend with the PHP backend without a Node.js runtime or build step.
- Distribution uses a CI-built release archive. Adapt both installer and self-update to consume it, preserve branch selection, and support rollback. Compiled frontend bundles are delivered in the archive rather than committed to the source repository.
- Tests will run locally in isolation with a simulated agent. Testing with a real agent will be arranged separately.
- UI, code, and documentation are English. User-visible dates and times use German notation. Static assets are served locally without CDNs.
- `Europe/Zurich` is the authoritative application/display timezone following legacy inspection. Preserve historical timestamp meaning and document transport conversion rather than relabeling naive values as UTC.
- The new responsive UI will use the current AdminLTE release and the Semi-dark Navy palette, preserve existing user capabilities, and display a bottom-centered update notice with an explicit reload button when a newer UI release is available.

The authorized read-only deployed-file inventory found no per-system `parameters/` or `results/` extension directories, root PHP system pages, or `system_assets` paths in the inspected installation. All bundled parameter controls and graph types remain required. Some systems still use legacy result-configuration files; conversion must be an explicit migration, never a GET side effect. These observations do not remove the repository's general extension-compatibility obligation and do not prove that every integration workflow is unused. No production counts, names, or identifiers are recorded here.

Pending decisions that affect backend/API implementation:

| Decision | Why it matters | Recommended next evidence |
| --- | --- | --- |
| General extension compatibility contract | The inspected installation has no custom extension directories/pages/assets, but the repository offers these capabilities to other installations and historical system repositories. | Define a versioned schema/renderer/processor contract with synthetic custom-extension fixtures and an explicit compatibility/deprecation policy. |
| Release archive implementation details | The CI-built archive, installer/self-update adaptation, preserved branch selection and rollback are confirmed. Source-only `git pull` cannot install a built UI, and compiled bundles will not be committed to source. | Design branch/revision-to-artifact resolution, artifact availability/integrity, layout, safe extraction, state preservation, coordinated migrations, atomic activation and rollback semantics. |

The suggested API and policies below are a design proposal. They do not silently decide unresolved role behavior, general extension retirement, broader database support, or deployment changes.

## Current backend architecture

### Request lifecycle

`webroot/index.php` establishes paths, loads `constants.php` and the ignored `config.php`, loads the database/templating libraries, sets the locale and timezone, starts authentication, and includes `core/router.php`.

The root `.htaccess` forwards traffic to `webroot/`. `webroot/.htaccess` rewrites non-file requests to `index.php?/<path>`. The router reads `QUERY_STRING`, identifies a controller/API/system, and interprets remaining path segments as `key=value` arguments. Examples are `/project/detail/id=42` and `/api/v1/job/id=next/supports=example`.

For ordinary views the route selects a controller method and `View`. For `/api/<version>/<resource>`, the method comes from the HTTP verb. `/system/...` and `/api/system/...` can dispatch installed system PHP code. The router dynamically includes source and checks a method-specific `<action>_access` property before dispatch. Authentication failures currently switch to an HTML login/error controller; this behavior is unsuitable for an independent JSON API.

References: `webroot/index.php:35`, `core/router.php:35`, `core/router.php:104`, `core/router.php:170`, `core/view.php`, `core/systemview.php`.

### Persistence and configuration

The backend is a small custom PHP MVC implementation rather than a packaged framework. `libraries/dba/Factory.class.php` provides singleton model factories. `AbstractModelFactory` constructs PDO/MySQL queries and gives each factory its own PDO connection. Cross-model operations therefore cannot obtain a single transaction merely by starting one on an arbitrary factory connection.

`chronos.sql` defines these main entities:

- `User` and `Session`: identity, bcrypt password hash, numeric role, active/activated status, persistent remember-me tokens.
- `System` and `Setting`: system ownership, VCS metadata, CEM support and global/per-system settings.
- `Project` and `ProjectUser`: ownership, membership, system association and archive state.
- `Experiment`: parameter submission JSON, phase mask, project/system association, selected result configuration, archive/default-environment state.
- `Evaluation` and `Job`: campaign executions, concrete parameter configurations, phases, lifecycle/progress, raw results and grouping identifiers.
- `Event` and `Node`: user/system activity and CEM node telemetry/health.

`ExperimentView`, `EvaluationView`, `EvaluationRunningView`, and `JobView` join project memberships into queryable records. These views assist filtering but do not replace authorization at a service boundary.

System data also lives under ignored `webroot/systems/<systemId>/`: `config.json`, `parameters.json`, `resultConfigurations.json`, logos, and optional custom parameter/chart implementations. Uploaded results/logs are held separately under configured data paths. A database backup alone is not sufficient for migration or rollback.

The inspected installation does not have the optional custom extension directories/pages/assets listed above, but contains legacy result formats alongside the modern format. Fixtures and the upgrade procedure must cover both storage generations. Inventory was performed by reading files directly rather than calling `System` getters, which can trigger migration side effects.

References: `chronos.sql:13`, `chronos.sql:284`, `libraries/dba/init.php`, `libraries/dba/AbstractModelFactory.class.php:839`, `libraries/settings.php:84`, `core/system.php:46`, `.gitignore:6`.

### Runtime and dependency constraints

The baseline README documents PHP 7.4+, MySQL, Apache with `mod_rewrite`/`AllowOverride All`, and Git. The confirmed v3 target raises the minimum to PHP 8.3 and preserves the existing MySQL 8.0 installation. Source additionally uses PDO MySQL, DOM/XML, curl, mbstring and compression functionality. Mercurial support and Linux `stat`/`mount` commands occur in optional workflows. Required extensions and supported optional features must be verified on PHP 8.3 and the selected newer PHP versions with MySQL 8.0 rather than inferred from the README alone. MariaDB or additional database support requires a separately documented decision and validation.

There is no root Composer manifest/lockfile or frontend package manifest at the audited baseline. PHP and browser libraries are vendored into the tree; one nested RandomCompat Composer manifest does not provide application dependency management. Existing AdminLTE assets identify version 2.4.2. Current CI only performs `php -l` using the runner's installed PHP.

References: `README.md:18`, `libraries/cdl.php:39`, `libraries/rest.php:219`, `libraries/zip/src/ZipStream.php:397`, `libraries/mount.php:38`, `webroot/design/default/css/AdminLTE.min.css:2`, `.github/workflows/lint.yaml`.

## Authentication and object policy

### Existing identity behavior

`Auth_Library` starts a PHP session and stores the username in `$_SESSION['login']`. Password verification uses PHP's password API. Remember-me authentication uses a selector and authenticator cookie, stores the authenticator hash in `Session`, and renews successful tokens. Login validates alive/activated status and updates the last-login timestamp.

Role `0` is a regular user, role `1` is an administrator, and role `2` is a superadministrator. `isAdmin()` also returns true for role `2`. Ownership and project membership supply additional resource rights outside those global roles.

Administrators can impersonate another user, except a superadministrator, with either a reversible switch or a full identity change. In reversible switch mode logout returns to the original identity. Profile editing contains additional restrictions for editing the original administrator while impersonating. These behaviors require explicit tests and visible identity context in the new UI.

The audited implementation does not explicitly configure SameSite/HttpOnly/Secure session/remember-me cookie options, regenerate session IDs at login, or provide a CSRF token layer. These are source observations; effective server-level cookie settings were not inspected.

References: `libraries/auth.php:68`, `libraries/auth.php:82`, `libraries/auth.php:195`, `libraries/auth.php:230`, `libraries/auth.php:260`, `libraries/auth.php:287`, `libraries/auth.php:308`, `controllers/user.php:84`.

### Proposed v3 policy matrix

This matrix reflects the intended capability boundaries visible in controllers, with defects separated below. Before implementation, resolve discrepancies between owner/member checks and establish regression cases for every permitted role. A project member means membership of the actual parent project, not simply the existence of a `ProjectUser` row elsewhere.

| Operation | Anonymous | Regular user / object rights | Administrator | Superadministrator |
| --- | --- | --- | --- | --- |
| Login and release-version metadata | Login and non-sensitive release identifier | Available | Available | Available |
| Own identity/password and logout | Denied | Own profile; current password required for own password change | Own profile; separate rules for editing others | Same, plus role administration |
| List/edit other users, create accounts | Denied | Denied | Allowed subject to current role restrictions | Allowed; can assign roles |
| Impersonation | Denied | Denied | May switch to non-superadmin target; no nested switch | Same target/nesting constraints unless explicitly changed |
| System list/detail/configuration/import/export/logo | Denied | Owned systems | All systems | All systems |
| Create a system / change system owner / remote VCS settings | Denied | Denied | Allowed; remote VCS fields additionally depend on feature enablement | Allowed |
| Create a project | Denied | May use own eligible system; self as owner | May choose another owner and eligible system | Same |
| Read project/experiment/evaluation/job, results, events, downloads | Denied | Project member or owner according to confirmed policy | All eligible resources | All eligible resources |
| Add/remove project members; archive/unarchive project | Denied | Project owner | Allowed | Allowed |
| Create/copy experiment; run evaluation | Denied | Project member or owner | Allowed | Allowed |
| Edit experiment/evaluation/job metadata and supported job actions | Denied | Authorized parent-project participant; confirm exact member rights | Allowed | Allowed |
| Experiment/evaluation archive and result-configuration actions | Denied | Authorized participant, with lifecycle/reference checks | Allowed, with same integrity checks | Same |
| View CEM nodes and administer global settings/environments | Denied | Denied | Allowed | Allowed |
| Application self-update, mount data directory, demo reset | Denied | Denied | Denied by action access declarations | Allowed; explicit operational action |
| Legacy agent/CEM v1 traffic | Existing protocol remains available | Separate compatibility path | Separate compatibility path | Separate compatibility path |

Important nuances:

- System ownership does not automatically grant access to every project that uses that system. Resolve access through the resource's actual parent project.
- Some legacy detail controllers check membership alone, while job/run/create paths additionally accept project ownership. Normalize the desired owner rule explicitly; do not preserve accidental differences as product requirements.
- System-wide result configurations and experiment-specific configurations have different rename/delete restrictions. Deletion must check active selections/references before touching files.
- Admin list filters such as “all users” must be enforced by the API, independent of controls hidden by Angular.
- A role change, disabled account, session expiry, impersonation switch and return must invalidate/update frontend capability state. Frontend flags are explanatory only; server policies remain authoritative.
- Denied actions must cause no database, filesystem, event or command-execution side effects.

Policy source examples: `controllers/project.php:94`, `controllers/project.php:151`, `controllers/builder.php:69`, `controllers/builder.php:209`, `controllers/experiment.php:42`, `controllers/job.php:102`, `controllers/evaluation.php:209`, `controllers/admin.php:378`, `controllers/admin.php:592`, `controllers/admin.php:663`.

## Legacy API compatibility is a separate contract

The v3 UI migration must preserve functioning v1 consumers without requiring agent or CEM upgrades. Keep v1 routes, request encodings, response envelopes, field names/types, numeric status meanings, environment identifiers, XML CDL structure and binary-transfer behavior compatible. Security improvements that would require new agent credentials or alter the protocol need separate agreement and a migration path.

`core/api.php` currently parses raw input with `parse_str`. Responses use `{ "status": { "code": ..., "message": ... }, "response": ... }`; application status codes include `200`, `600`, `601`, `602`, `611`, `612`, `631` and `632`. These codes are envelope values, not an existing standards-based HTTP error contract. Empty response data may be omitted by the serializer. Do not globally replace this base class with JSON-only parsing or new envelopes while introducing v3.

| Legacy surface | Behavior that compatibility tests must cover | Source |
| --- | --- | --- |
| `GET /api/v1/job` | Specific job retrieval; `id=next`; supported-system filtering; `system-<environment>` routing; no-job status; identity/status/progress/phase/timestamps; XML CDL in payload. | `api/v1/job.php:36` |
| `POST /api/v1/job` | Upload-target selection; local-network FTP option; HTTP option; append log; multipart result ZIP upload. Never log real credentials in fixtures/output. | `api/v1/job.php:108` |
| `PATCH /api/v1/job` | Status/progress/current-phase/result updates, phase validation, progress reset and generated activity events. | `api/v1/job.php:152` |
| `GET /api/v1/cem` | Unique node ID/version/environment inputs; environment/version rejection codes; specific/next job; CEM-capable system filtering; setup/CDL response and queue lock behavior. | `api/v1/cem.php:38` |
| `POST /api/v1/cem` | Job start/termination; node telemetry/health/current-job reconciliation; job log; registration/version/environment updates. | `api/v1/cem.php:116`, `api/v1/cem.php:254` |
| `/api/system/...` | No root PHP system pages were found in the inspected installation. Preserve or explicitly migrate the general system-handler capability; this inventory alone does not authorize removing it for other installations. | `core/router.php:58`, `core/systemview.php` |

Existing `/api/ui/*` endpoints are not the target v3 contract. They mix public mutations, polling data and base64-encoded HTML fragments. Replace their legitimate user capabilities with authenticated v3 resources, and remove the old UI endpoints only after call-site/parity verification. Do not infer that other clients can never use them; document deprecation and check known consumers before removal.

## Proposed `/api/v3` architecture

### Separation of responsibilities

1. A dedicated v3 request/response boundary recognizes its routes before legacy view dispatch and does not use the v1 destructor serializer. It parses JSON explicitly, handles multipart/binary requests separately, returns consistent JSON errors and proper HTTP status codes, and preserves v1 bootstrap behavior.
2. Authentication middleware establishes the active and original identity, validates the account/session, and enforces CSRF for browser mutations, including the chosen login/session-bootstrap flow. Same-origin session cookies avoid a second browser credential store.
3. An explicit route table maps resources and commands to handlers. Route and identifier validation must occur before dynamic file inclusion or filesystem access.
4. Central policies resolve ownership/membership through the actual object graph before exposing records or invoking operations. Handlers return DTOs instead of raw model dictionaries containing credentials or internal fields.
5. Domain services implement project membership, configuration, experiment generation, job transitions, results and admin workflows. These services own validation, invariants, transaction boundaries and event creation.
6. Infrastructure adapters encapsulate the shared database unit of work, filesystem storage, archive streaming, clock and process/VCS/mount execution. Tests can replace all external effects.
7. Angular components consume typed JSON schemas and resources. Parameter editors and charts render through a component registry; they do not execute PHP HTML fragments or arbitrary scripts supplied by API responses.

Keep the initial database structure compatible where possible. Introduce explicit, versioned migrations only for necessary schema changes, with a preflight/backup/rollback procedure. Do not combine a UI replacement with an unbounded backend-framework rewrite.

### Contract conventions

- Publish an OpenAPI description and schemas for all v3 routes, payloads, errors and file transfers; generate or verify Angular types from it.
- Use normal HTTP statuses (`400`, `401`, `403`, `404`, `409`, `422`, `500`) with a documented error body and field-level validation. Do not leak stack traces, SQL, credentials, raw shell output or storage paths.
- Treat IDs as stable identifiers; retain numeric lifecycle codes where needed and provide human-readable labels separately. Preserve `configurationIdentifier`, internal numbering and system/CEM environment prefixes needed by existing execution/results logic.
- Use JSON for structured requests/responses, multipart for file uploads, and authenticated streaming for downloads. GET requests must not make changes or trigger Git operations.
- Use pagination/filtering for lists and bounded log/result retrieval; preserve current search/sort/filter capabilities. Honor configured page-size and evaluation job limits.
- Validate names, text, schema versions, nested values, phase/run selections, ownership and references server-side. Establish a single escaping boundary: API data is text, Angular renders escaped text, and legacy HTML-escaped database values require a reviewed compatibility treatment.
- Define stale-write behavior for shared configuration and metadata. Prefer an explicit revision/precondition to silent lost updates; do not claim existing `lockColumn` fields already provide complete API concurrency control.
- Use timestamps with an explicit offset in new contracts and `Europe/Zurich` as the authoritative application/display timezone. Preserve legacy timestamp meaning based on inspection; do not relabel naive stored values as UTC. Format human-readable display/export dates as `dd.MM.yyyy` and 24-hour time without English/US fallbacks; preserve machine-readable v1 compatibility.
- Define cache policy per resource: session/authenticated records must not be shared-cacheable. Version metadata must be revalidated independently of immutable hashed assets.

### Resource and operation coverage

Illustrative resource names below establish scope, not a committed URL design. Every entry needs typed inputs, an object policy, success/error tests and a matching frontend capability before legacy removal.

| Resource family | Required operations/data | Legacy source coverage |
| --- | --- | --- |
| Session and profile | Login/remember me/logout; active/original user and capabilities; profile identity/password; impersonation and return; safe deep-link restoration. | `controllers/user.php`, `libraries/auth.php`, `controllers/admin.php:287` |
| Dashboard and activity | Counts, recent/running work, activity hierarchy, user/all filters and links. Model event content as safe text/link data, accounting for legacy titles containing HTML. | `controllers/home.php`, `libraries/event.php`, `core/util.php` |
| Users | Lists, create, profile edit, activation/alive flags, password override and role restrictions. | `controllers/admin.php:193`, `controllers/user.php:84` |
| Systems | Owned/admin lists, archived filtering, metadata/owner/CEM flag, identifier/logo; create; validated import/export; defaults, general settings, setup options, environments; VCS status/history/update where enabled. | `controllers/admin.php:353`, `controllers/admin.php:378`, `controllers/admin.php:592`, `controllers/admin.php:615`, `controllers/admin.php:663`, `controllers/admin.php:703` |
| Parameter schemas | List built-in/custom types, read/save groups/elements/order/dependencies, import/export, typed editor metadata and validation. | `controllers/builder.php:41`, `api/ui/builder.php`, `libraries/builder.php`, `core/element.php` |
| Projects | User/all/archived lists, create, metadata, members, archive/unarchive, running and starred evaluations, activity. | `controllers/project.php` |
| Experiments | Create/copy via dynamic parameters; phase/run/distribution settings; metadata/default environment; list/detail/archive; result configuration selection and experiment-specific copy/create/rename/delete. | `controllers/builder.php:209`, `controllers/experiment.php`, `api/ui/experiment.php` |
| Evaluations | User/all running lists and detail; metadata/star/archive; run from experiment with version/environment inputs; progress/jobs/activity; abort/reschedule eligible jobs; availability and download conditions. | `controllers/evaluation.php`, `controllers/builder.php:69`, `api/ui/evaluation.php` |
| Jobs | User/all/status lists; details and previous/next navigation; descriptions/configuration/CDL; lifecycle/progress/phases; log/result display; abort/reschedule/manual supported transitions. | `controllers/job.php`, `api/ui/job.php`, `core/define.php` |
| Result configurations | System-wide and experiment-specific configurations; overall/job plot editor; type/field options; copy/create/rename/delete; reference and namespace restrictions. | `controllers/results.php:38`, `controllers/admin.php:490`, `controllers/experiment.php:59`, `api/ui/results.php` |
| Result data and downloads | Overall/per-configuration/per-job series, grouped runs, plot refresh, finished-job counts, raw data and ZIP streaming with existing completion checks. | `controllers/results.php:77`, `libraries/results.php`, `libraries/graphs/`, `controllers/evaluation.php:209`, `views/evaluation/download.php` |
| CEM administration | Present/missing node lists, telemetry/health/version/current job, node detail and activity. Agent updates continue through v1. | `controllers/cem.php`, `api/v1/cem.php` |
| Application administration | Settings including meaningful empty/false values; CEM environment management; VCS branch/revision/release information; self-update, mount status/action and demo/reset if retained. Operational adapters must separate test execution from real effects. | `controllers/admin.php:42`, `controllers/admin.php:264`, `controllers/admin.php:329`, `controllers/admin.php:341` |
| Release version | Public, non-sensitive build identifier/API compatibility metadata for update detection; no user or infrastructure secrets. | New capability; current version is `constants.php` |

### UI release notice and production distribution

Build Angular and all AdminLTE/plugins/fonts locally in CI with a lockfile. Publish a release archive containing the matching PHP backend, required production dependencies, and compiled frontend with an immutable build identifier and hashed asset names. Production serves the bundle and PHP only. Compiled frontend bundles are not committed to the source repository.

The open UI compares its embedded build identifier with independently revalidated release metadata. On a meaningful newer release, show the requested bottom-centered notice and explicit “Load latest version” action. Account for focus/visibility changes, network failures, deployments during polling and stale HTML. Do not auto-reload or discard unsaved forms. Cache-bust/revalidate the shell on the explicit action, and keep compatible previous hashed assets available long enough that an open tab does not fail immediately after deployment.

The user confirmed the CI-built archive direction and adaptation of installer/self-update with preserved branch selection and rollback. Implementation details still need design: resolve a selected branch/revision to an available matching archive; verify artifact integrity; extract into staging; preserve configuration, system repositories and runtime data; coordinate database/configuration migrations; activate backend/assets/version metadata together; and define rollback behavior. Neither production Node.js nor committing compiled assets is a fallback. Test a missing/incompatible archive before changing the served installation. A version endpoint by itself does not solve stale shell/service-worker caching or missing chunks. A service worker is not required for this feature.

## Side effects and test isolation

Never point tests or UI previews at a production `config.php`, database, mounted data directory, system checkout or remote VCS repository. Fixture generation itself must be isolated because some apparent read methods mutate data.

| Source | Observed side effect | Required isolation/design response |
| --- | --- | --- |
| `core/system.php:117` and `core/system.php:134` | Missing modern result configuration triggers conversion, file writes, deletion of old result files and VCS commit through setters. | Build fixtures in disposable directories; test conversion explicitly; move migration out of normal GET paths in v3. |
| `core/system.php:176` through `core/system.php:225` | Parameter/results writes, rename/create/delete invoke VCS commit. | Inject storage and command adapters; no real remote during automated tests. |
| `libraries/vcs.php:96` | `commit()` runs pull/add/commit/push whenever Git is installed. It is not gated by `ENABLE_REMOTE_REPOSITORY`. | Stubbing process execution is necessary; setting the feature flag alone is insufficient. |
| `libraries/systems.php:166` | System initialization makes directories/config/logo and initializes/commits a Git repository. | Use throwaway system storage and deterministic fake process results. |
| `controllers/admin.php:264`, `libraries/demo.php:36` | Demo/reset paths remove system directories and rebuild/clear data. | Verify adapter intents only in normal tests; destructive integration tests require an explicit disposable environment. |
| `libraries/mount.php:38` and `libraries/mount.php:64` | Mount inspection invokes OS commands; mounting may use sudo. | Simulate status/results; never invoke host mount commands in browser tests. |
| `libraries/vcs.php:58`, `controllers/admin.php:329` | Self-update changes the application checkout. | Use a fake release provider/updater; test real packaging in a disposable installation. |
| `install.php:67` | Installer may remove an existing `chronos` directory, clone a repo, create schema/user/settings and write config/rewrite files. | Test against dedicated filesystem/database fixtures only. |
| `api/v1/job.php:217`, `controllers/admin.php:615`, `controllers/admin.php:532` | Result upload, configuration import and logo upload write filesystem content. | Use controlled file sizes/types/paths; test invalid input, failure and authorization without production data. |
| `libraries/settings.php:106` | Debug logging includes setting values. | Redact secret fields in the new boundary/adapters; fixtures must contain dummy values and logs must not expose real credentials. |

Database writes and event creation must be checked together. A denied or failed operation must not leave a partial job set, membership change, filesystem configuration or Git command. Keep a shared transaction boundary for database work and define recovery for non-transactional file/process effects.

## Existing defects and audit limitations

These observations are input to regression tests and remediation; no claim is made that any was reproduced in a running installation.

1. `api/ui/project.php:32`, `api/ui/experiment.php:32`, `api/ui/evaluation.php:34` and `api/ui/job.php:43` declare public mutations without parent-object policies. v3 must implement proper authorization, not preserve unauthorized capability as functional parity.
2. `controllers/evaluation.php:107` processes reschedule/abort/star operations before the membership check at `controllers/evaluation.php:155`. Move authorization before all changes.
3. `controllers/results.php:38` and `controllers/results.php:77` rely on login without the same explicit object membership checks used by project/evaluation detail. Builder/results APIs similarly need system/experiment policies.
4. `api/ui/evaluation.php:90` constructs `Results_Library` without the required result ID accepted by `libraries/results.php:42`. Confirm and repair intended plot refresh behavior through fixtures.
5. `chronos.sql:53` single-quotes the `defaultEnvironment` column declaration. Fresh-install SQL must be validated on the supported database versions before relying on this baseline as a runnable fixture.
6. Legacy list access/filtering is inconsistent across controllers. For example, explicit `user=all` branches must not grant a regular account global data access just because the UI hides that link.
7. Model factories independently acquire PDO connections; `Evaluation_Library::generateJobs()` writes jobs, events and labels in separate steps. Existing code does not establish one transaction around that complete operation.
8. Authentication/session and cookie hardening described above is absent at application level. Server configuration and live cookies were not inspected.
9. The authorized file inventory narrows deployed extension scope but does not validate database contents, runtime integrations, application behavior or deployment compatibility. General extension behavior remains subject to an explicit contract and synthetic fixtures. No old/new browser parity or real agent compatibility has yet been demonstrated.

## Migration verification plan

Implementation should create executable tests, not mark this planning document as test coverage. Use one traceable capability ID from the UI inventory per user operation and map it to policy, domain/API and browser assertions. A capability is complete only when the new UI performs it against the real local backend and fixture data.

### Isolated environment

- Provision a disposable MySQL 8.0 database with PHP 8.3 and the selected newer PHP versions, plus separate config/upload/system/log/temp directories. Do not treat MariaDB as supported without a separate decision. Make it impossible to resolve a production config by accident.
- Seed multiple regular users, an administrator, a superadministrator, owned and shared projects, an unrelated project, active/archived systems, mixed job states and both system/CEM environments.
- Include every bundled parameter/plot type, dependent/nested groups, multiple runs/distributions, selected/unselected result configurations and both legacy/modern result formats. Use synthetic custom-extension fixtures to define general compatibility despite the absence of custom extension files in the inspected installation. Assert that reading each legacy format causes no write, deletion, conversion or VCS command.
- Replace VCS/mount/process execution and any outbound integration with adapters recording intended operations. Dummy FTP/VCS/settings values must remain obviously fake.
- Run a simulated agent/CEM through HTTP against the disposable backend. Real agent validation remains a separately agreed activity.

### Required API and domain tests

| Test family | Assertions |
| --- | --- |
| Identity/session | Login failure and account flags, remember me/expiry, logout, session rotation, CSRF rejection, impersonation target/nesting/return, profile/password and role restrictions. |
| Object policy matrix | Anonymous/owner/member/outsider/admin/superadmin for every read and write; tampered resource IDs/parent IDs; no side effects on denial; unauthorized list filters and file downloads. |
| Configuration | Valid/invalid schemas, unknown/custom types, dependency references/order, imports/exports, round trip, meaningful zero/false/empty values, copy and result namespace/reference rules. |
| Experiment and job generation | Stable parameter Cartesian products, runs/order/distribution, phase masks, limits, environment/version inputs, identifiers/CDL, event creation and transactional failure behavior. |
| Job lifecycle | Allowed/invalid transitions, abort/reschedule, reset of progress/phase, aggregate state counts, archive restrictions, concurrent/polling updates and events. |
| Results | Grouping repeated runs, each chart processor and option, empty/partial/completed results, refreshed series, numeric edge cases, raw display/export and ZIP contents/completion checks. |
| v1 compatibility | Freeze known request/response fixtures for job and CEM flows, including URL-encoded and multipart bodies, codes/types, environment filtering, no-job/version/environment errors, XML and uploads. |
| Storage/operations | Failed writes/imports, invalid files, rejected paths/IDs, command adapter intent, safe conversion, update/mount/demo simulation and no unexpected outbound requests. |
| Time | Inspected historical interpretation, `Europe/Zurich` DST boundary/null dates, German formatting, sorting and human-readable exports, explicit new API offsets and preserved machine-readable v1 timestamps. |
| Release handling | Same/new/offline version metadata, stale shell/chunks, explicit reload, preserved unsaved work, compatible asset retention and rollback artifact coherence. |

### Browser and deployment gates

Test Angular workflows at mobile, tablet and desktop dimensions with fixture-backed HTTP responses from the local PHP API. Include navigation/deep links, keyboard operation, validation/error states, dialogs, tables, dependent parameter forms, builders, charts, log polling, upload/download and role-dependent controls. Confirm real backend mutations and resulting state rather than only checking button visibility.

Before removing the old UI, compare the same capability/fixture set against old and new behavior, resolve intended-behavior differences explicitly, and close all required gaps. Test fresh installation and upgrade/rollback of a built production package with no Node.js available. Verify offline/local asset availability and no CDN requests. Preserve agent contracts and operational controls through release deployment.

Required gates remain unexecuted at this planning stage. Remaining decisions and fixture availability must be resolved before claiming complete feature parity or a production-ready migration.
