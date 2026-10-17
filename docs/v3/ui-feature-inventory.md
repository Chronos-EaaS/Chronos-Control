# Chronos v3 UI feature inventory

## Baseline and verification status

This inventory was produced by a read-only source audit of Chronos Control commit
`1d349f2`. It covers the application views, their controllers, UI API handlers,
shared navigation, parameter and chart templates, and the standalone installer.
No application, database, worker, browser, or deployed system repository was run
for this audit. The listed outcomes are migration acceptance requirements derived
from source, not a claim that the existing implementation works correctly.

Subsequent authorized, read-only deployment inventory confirmed coexistence of
`parameters.json`, current `resultConfigurations.json`, and legacy
`results*.json` definitions. All built-in parameter identifiers and plot types
occur in deployed definitions. No custom `parameters/` or
`results/` extension directories, root PHP system pages, or `system_assets/`
directories were observed. This establishes relevant migration fixture categories;
they do not establish runtime parity, and they do not remove the requirement for
a supported extension contract. No private deployment locations, values, or
system identifiers are recorded here.

Confirmed migration constraints are Angular, installable frontend build packages
produced by CI, preserved branch selection with a rollback path, PHP 8.3 and
MySQL 8.0 support, and `Europe/Zurich` for displayed times.

**Implementation status for every feature and proposed scenario in this document:
Not yet verified.** A feature remains in that state until its v3 implementation
has an executable test and the relevant runtime evidence. Static inspection or a
passing source-presence check does not establish functional parity.

Feature IDs are stable identifiers for tests and migration tracking. Add new IDs
instead of renumbering existing ones. Source line references describe this
baseline and will move during implementation. Paths are repository-relative.

## Roles and data ownership

| Role or relationship | Intended scope derived from controllers |
| --- | --- |
| Visitor | Sign in and see public error pages. Protected navigation should lead to sign-in and return to the requested location afterwards. |
| User | Manage their profile; see systems they own; create projects using an allowed system; access projects of which they are a member and the related experiments, evaluations, jobs, and results. |
| Project owner | Project member capabilities plus managing project membership and archiving/restoring the project. |
| System owner | Configure their system, its parameters, environments, result definitions, logo, and supported repository operations. |
| Admin | Access all projects and owned systems; create systems; administer users and CEM; impersonate eligible accounts; create projects on another user's behalf. |
| Superadmin | Admin capabilities plus application update, data-directory mount, and demo maintenance. The backend additionally supports assigning user/admin/superadmin roles. |
| Impersonated account | Behaves as the selected account. A visible switch-mode indicator and a reliable way to return to the original admin are essential. |

Definitions: `libraries/auth.php:40`, `libraries/auth.php:195`,
`libraries/auth.php:230`, `libraries/auth.php:242`, `core/router.php:166`.
Several legacy handlers fail to enforce this intended scope; those discrepancies
are recorded below and must not become security acceptance requirements.

## Shared UI and authentication

| ID | Function and expected outcome | Access | Source |
| --- | --- | --- | --- |
| SHELL-01 | Navigate to Overview, Projects, Status, and Systems; show CEM and administration only to eligible roles. Highlight the active area. | Signed in; conditional admin entries | `webroot/design/default/menu.php:38`, `webroot/design/default/navbar.php:75` |
| SHELL-02 | Collapse/expand the sidebar and use navigation on narrow screens. The new navigation must remain keyboard and touch accessible. | Signed in | `webroot/design/default/navbar.php:34`, `webroot/design/default/header.php:100` |
| SHELL-03 | Use the brand/home link and display Chronos branding. Preserve a usable compact sidebar identity. The v3 login/logo design may improve. | All applicable views | `webroot/design/default/header.php:91`, `views/user/login.php:65` |
| SHELL-04 | Open the account menu to identify the current user, see admin status, open Profile, or log out. Display switch-user mode prominently. | Signed in | `webroot/design/default/navbar.php:41` |
| SHELL-05 | Follow contextual breadcrumbs through project → experiment → evaluation → job and system → builder. Direct links and browser back/forward must remain useful after migration. | Authorized resource users | `views/project/detail.php:104`, `views/experiment/detail.php:104`, `views/evaluation/detail.php:161`, `views/job/detail.php:224` |
| SHELL-06 | Display application version and attribution in the footer. The requested v3 stale-client notification is additional functionality, not present in the baseline. | Signed in | `webroot/design/default/footer.php:32` |
| SHELL-07 | Read contextual event timelines with event type icons, event text, actor, relative age, precise timestamp, and links to related entities. Limit initial event sets as the backend currently does. Use German-format dates/times in v3, including tooltips. | Authorized contextual pages | `libraries/event.php:51`, `libraries/event.php:186`, `core/util.php:381` |
| SHELL-08 | See useful 403, 404, application error, and recoverable process-error screens, with a route back to the dashboard or previous page. Do not expose debug traces in normal operation. | All | `views/home/error403.php`, `views/home/error404.php`, `views/home/error.php`, `views/home/handlingError.php`, `core/router.php:147` |
| AUTH-01 | Sign in with username/password and receive a clear error for invalid or missing credentials. Inactive/unconfirmed accounts follow configured activation rules. | Visitor | `controllers/user.php:37`, `libraries/auth.php:375`, `libraries/auth.php:404`, `views/user/login.php:77` |
| AUTH-02 | Select Remember Me and resume an eligible authenticated session through the persisted session token. | Visitor / signed in | `controllers/user.php:42`, `libraries/auth.php:82`, `libraries/auth.php:308` |
| AUTH-03 | Open a protected deep link while signed out, sign in, and return to that resource, retaining its parameters. | Visitor | `core/router.php:202`, `controllers/user.php:57` |
| AUTH-04 | Log out and clear the normal authenticated/remembered session. In soft impersonation, Log out returns to the original admin instead. | Signed in | `controllers/user.php:72`, `libraries/auth.php:287` |
| PROFILE-01 | View/edit first name, last name, gender, and email; validate values; show success/error feedback. A normal user cannot edit another profile or their username. | Self; admin for other users | `controllers/user.php:84`, `views/user/edit.php:112` |
| PROFILE-02 | Change own password using the old password and matching valid replacement/repetition. Invalid old passwords and mismatches must not change the password. | Self | `controllers/user.php:159`, `views/user/edit.php:161` |
| PROFILE-03 | Edit another user's username/identity and reset their password without their old password. Prevent editing the original administrator's profile while impersonating. | Admin for another account | `controllers/user.php:87`, `controllers/user.php:111`, `controllers/user.php:160` |
| PROFILE-04 | Enable/disable another account and mark it activated/unactivated. Persist flags and enforce them during subsequent login. | Admin for another account | `controllers/user.php:133`, `views/user/edit.php:77` |
| PROFILE-05 | Assign User, Admin, or Superadmin roles to another account. The backend supports this but the old UI condition is defective; see discrepancy D-04. | Superadmin | `controllers/user.php:147`, `views/user/edit.php:101` |
| HOME-01 | Display current user's counts for projects, experiments, completed/running evaluations, total jobs, active jobs, and failed jobs. Counts must agree with the same fixture's resource states. | Signed in | `controllers/home.php:46`, `views/home/main.php:44` |
| HOME-02 | Read the latest activity timeline and follow related resource links. The current controller fetches the latest 20 global events; v3 must explicitly settle visibility rules. | Signed in | `controllers/home.php:47`, `views/home/main.php:91`, `libraries/event.php:51` |

## Projects

| ID | Function and expected outcome | Access | Source |
| --- | --- | --- | --- |
| PROJECT-01 | List projects with ID, name, description, and system; sort columns; paginate using configured rows per page; open a project from its row/link. | Member; admin | `controllers/project.php:47`, `views/project/overview.php:30` |
| PROJECT-02 | Toggle between own projects and all projects as admin. For a user, retain membership-based visibility without leaking other projects through direct API calls. | Admin filter; user member scope | `controllers/project.php:49`, `views/project/overview.php:74` |
| PROJECT-03 | Switch between current and archived project lists, preserving the ownership filter. The legacy toggle selects archived projects rather than adding them to the current list. | Member; admin | `controllers/project.php:58`, `views/project/overview.php:82` |
| PROJECT-04 | Create a named/described project against an allowed, nonarchived system; automatically add its owner as a project member; create an event. Admin can choose another owner. | Signed in; admin owner selector | `controllers/project.php:94`, `views/project/create.php:44` |
| PROJECT-05 | Edit project name and description with required-name validation and visible save/error feedback. Preserve the intended 250-character name limit. | Authorized project users | `views/project/detail.php:35`, `api/ui/project.php:38` |
| PROJECT-06 | Archive or restore a project after confirmation and display its archived state. | Project owner; admin | `controllers/project.php:188`, `views/project/detail.php:118` |
| PROJECT-07 | View members, identify the owner, add a member without duplicates, and remove a nonowner member. A removed member loses access to project resources. | Project owner; admin | `controllers/project.php:168`, `views/project/detail.php:263` |
| PROJECT-08 | View current/archived experiments with internal ID, name, description; open, copy, create, archive, and restore experiments. Prevent archiving an experiment with active evaluations. | Authorized project users | `controllers/project.php:200`, `views/project/detail.php:151` |
| PROJECT-09 | View/open currently running evaluations and starred evaluations, including starred indicators and experiment/evaluation internal identifiers. | Authorized project users | `controllers/project.php:247`, `controllers/project.php:259`, `views/project/detail.php:207` |
| PROJECT-10 | Read the project event timeline with navigation to child resources. | Authorized project users | `controllers/project.php:274`, `views/project/detail.php:305` |

## Systems and configuration

| ID | Function and expected outcome | Access | Source |
| --- | --- | --- | --- |
| SYSTEM-01 | List system cards with logo, name, description, owner, archived state, and View action. Normal users see their own systems; admins see systems assigned to users. | System owner; admin | `controllers/admin.php:353`, `views/admin/systems.php:78` |
| SYSTEM-02 | Show archived systems as well as current systems when requested. Unlike the project filter, the current system toggle includes both. | System owner; admin | `controllers/admin.php:362`, `views/admin/systems.php:68` |
| SYSTEM-03 | Create a named/described system with an owner. Initialize a local system repository or, when remote repositories are enabled, clone Git/Mercurial using the configured URL, branch, and optional credentials. | Admin | `controllers/admin.php:663`, `views/admin/createSystem.php:63` |
| SYSTEM-04 | Edit system name/description; admins may change ownership. Changes remain visible after reload and to the new owner. | System owner; admin owner selector | `controllers/admin.php:392`, `views/admin/system.php:98` |
| SYSTEM-05 | View/configure remote repository type, account, password, and selected branch when enabled. Protect secrets in v3 responses and do not echo stored passwords. The old repository URL field is displayed but not saved by this handler. | Admin; feature flag | `controllers/admin.php:400`, `views/admin/system.php:126` |
| SYSTEM-06 | Archive a system after confirmation. Reject archiving while any current project uses it. Display archived status. No system restore action exists in the audited UI/controller. | System owner; admin | `controllers/admin.php:470`, `views/admin/system.php:101` |
| SYSTEM-07 | List/add/delete system-local environments, reject duplicates, confirm deletion, and expose available environments to experiment runs. Explain the difference between custom and CEM-managed environments. | System owner; admin | `controllers/admin.php:451`, `controllers/admin.php:482`, `views/admin/system.php:163` |
| SYSTEM-08 | List/add/edit/delete additional setup key/value settings, reject duplicate keys, and include settings in generated setup CDL. This panel is shown when CEM is enabled. | System owner; admin | `controllers/admin.php:432`, `controllers/admin.php:464`, `views/admin/system.php:198` |
| SYSTEM-09 | List/add/edit/delete additional evaluation key/value settings, reject duplicate keys, and include settings in generated evaluation CDL. | System owner; admin | `controllers/admin.php:412`, `controllers/admin.php:458`, `views/admin/system.php:500` |
| SYSTEM-10 | Save the default environment and checked/unchecked warm-up preference used when creating experiments. Test real propagation rather than only the settings response. | System owner; admin | `controllers/admin.php:408`, `controllers/builder.php:232`, `views/admin/system.php:467` |
| SYSTEM-11 | Enable/disable Chronos Environment Management through its dialog; reflect the current state and adjust setup/environment options. | System owner; admin | `controllers/admin.php:552`, `views/admin/system.php:364` |
| SYSTEM-12 | Open/close the System ID dialog and read the integration identifier needed by an evaluation client. | System owner; admin | `views/admin/system.php:265`, `core/system.php:63` |
| SYSTEM-13 | Open/close system revision history and read commit messages and abbreviated hashes. A custom accessible history view may replace the old GitGraph visualization. | System owner; admin | `controllers/admin.php:579`, `views/admin/system.php:45`, `views/admin/system.php:291` |
| SYSTEM-14 | Trigger update of a remote-backed system and see command/result output plus navigation back to the system. | System owner; admin; feature flag | `controllers/admin.php:703`, `views/admin/system.php:285`, `views/admin/systemUpdate.php` |
| SYSTEM-15 | Upload a system logo through its dialog; accept supported GIF/PNG/JPG/JPEG files; reject missing/oversized/unsupported uploads; display the new logo and record the system repository change. | System owner; admin | `controllers/admin.php:525`, `views/admin/system.php:340` |
| SYSTEM-16 | Export the system parameter and result definitions as a downloadable archive. The v3 archive must actually contain current definitions; legacy export omits the current result configuration file. | System owner; admin | `controllers/admin.php:592`, `views/admin/systemExport.php:31`, `views/admin/system.php:313` |
| SYSTEM-17 | Import valid parameter/result definitions from a file, validate the document, report errors, persist definitions, and return to the system. Baseline implementation accepts `parameters.json` and `resultConfigurations.json`, despite older error text. | System owner; admin | `controllers/admin.php:615`, `views/admin/system.php:316`, `views/admin/systemImport.php` |
| SYSTEM-18 | Open the system parameter builder. | System owner; admin | `controllers/builder.php:41`, `views/admin/system.php:396` |
| SYSTEM-19 | Create/copy/rename/delete result configurations; edit independent Overall Results and Job Results definitions; link experiment-specific configurations back to their experiment. Reject deletion of configurations in use by experiments. | System owner; admin | `controllers/admin.php:488`, `views/admin/system.php:401` |

## Parameter builder and experiment input types

Parameter definitions are stored as groups and elements in `parameters.json`.
The old UI edits a schema and later generates experiment input forms from it.
Migration must preserve the schema semantics and stored experiment data rather
than merely reproduce a static example form.

| ID | Function and expected outcome | Access | Source |
| --- | --- | --- | --- |
| PARAM-01 | Add a named group with optional dependency parameter and matching value; delete a group after confirmation. | System owner; admin | `views/builder/build.php:38`, `views/builder/build.php:98`, `templates/builder/group.template.html` |
| PARAM-02 | Add an element of a supported type to a chosen group, edit its definition fields, and delete it after confirmation. | System owner; admin | `views/builder/build.php:54`, `views/builder/build.php:92`, `api/ui/builder.php:57` |
| PARAM-03 | Reset unsaved changes by reloading; save the complete group/element structure, preserving IDs, ordering, Unicode values, dependencies, and type-specific settings; return to the system. | System owner; admin | `views/builder/build.php:104`, `api/ui/builder.php:38`, `core/system.php:176` |
| PARAM-04 | Boolean: configure label, parameter name, and true/false default; render/select a boolean value and preserve it when copying an experiment. | Builder owner; project users for input | `libraries/elements/boolean/config.json`, `libraries/elements/boolean/build.template.html`, `libraries/elements/boolean/render.template.html` |
| PARAM-05 | Integer: configure label, parameter name, and default; accept a numeric value, including zero, and preserve it when copying. | Builder owner; project users for input | `libraries/elements/integer/build.template.html`, `libraries/elements/integer/render.template.html` |
| PARAM-06 | String: configure label, parameter name, and default; input/copy a string including Unicode and punctuation without changing its value. | Builder owner; project users for input | `libraries/elements/string/build.template.html`, `libraries/elements/string/render.template.html` |
| PARAM-07 | Dropdown: configure stored values, corresponding display names, and default; select one option; preserve the selected value when copying. | Builder owner; project users for input | `libraries/elements/dropdown/build.template.html`, `libraries/elements/dropdown/render.template.html` |
| PARAM-08 | Checkbox selection: configure available/default choices; select one or more choices; require at least one; generate one configuration per selected option and preserve selections in copies. | Builder owner; project users for input | `libraries/elements/checkbox-selection/build.template.html`, `libraries/elements/checkbox-selection/render.template.html`, `libraries/elements/checkbox-selection/process.php` |
| PARAM-09 | Interval: configure/input minimum, maximum, and step; expand the range into jobs, preserve boundary behavior and copied values, and reject invalid/nonterminating intervals. | Builder owner; project users for input | `libraries/elements/interval/build.template.html`, `libraries/elements/interval/render.template.html`, `libraries/elements/interval/process.php` |
| PARAM-10 | Percentage: configure integer/float output, source parameter, and default percentage; recalculate read-only absolute value when percentage/source changes; flag sibling percentages whose sum differs from 100. | Builder owner; project users for input | `libraries/elements/percentage/build.template.html`, `libraries/elements/percentage/render.template.html`, `views/builder/create.php:29`, `views/builder/create.php:67` |
| PARAM-11 | Percentage interval: configure integer/float output, dependency, start/end percentages and step; show calculated absolute start/end/step; expand configurations and retain negative step support where needed; flag invalid totals. | Builder owner; project users for input | `libraries/elements/percentage-interval/build.template.html`, `libraries/elements/percentage-interval/render.template.html`, `libraries/elements/percentage-interval/process.php`, `views/builder/create.php:88` |
| PARAM-12 | Depending percentage: apply a percentage to the start/end/step of another percentage interval; recalculate read-only outputs and validate the group total; preserve copied values and dependent job calculations. | Builder owner; project users for input | `libraries/elements/percentage-dep/build.template.html`, `libraries/elements/percentage-dep/render.template.html`, `libraries/elements/percentage-dep/process.php` |
| PARAM-13 | Ratio: configure two parameter names, start ratio, end ratio, and step; accept ratio strings such as `0%:100%`; generate the paired parameter values and preserve copied input. | Builder owner; project users for input | `libraries/elements/ratio/build.template.html`, `libraries/elements/ratio/render.template.html`, `libraries/elements/ratio/process.php` |
| PARAM-14 | String list: configure default comma-separated values; enter/copy a list; expand it into configurations. | Builder owner; project users for input | `libraries/elements/string-list/build.template.html`, `libraries/elements/string-list/render.template.html`, `libraries/elements/string-list/process.php` |
| PARAM-15 | Software version list: configure label/parameter/repository; choose number of versions; dynamically add/remove required branch/version fields; preserve values in copied experiments; permit per-evaluation version overrides. | Builder owner; project users for input | `libraries/elements/software-version-list/build.template.html`, `libraries/elements/software-version-list/render.template.html`, `libraries/elements/software-version-list/process.php`, `core/util.php:588` |
| PARAM-16 | Dependent groups: show/enable a group only when its dependency has the configured value; hide/disable it otherwise, including nested disable propagation. Hidden fields must not contribute jobs or trigger validation. | Project users completing an experiment | `libraries/builder.php:139`, `templates/experiment/dependentGroup.template.html` |
| PARAM-17 | Discover/preserve system-supplied custom parameter element capabilities. Existing PHP build/render/process/copy extensions require a deliberate v3 extension contract and representative deployment fixtures. | System owner; authorized project users | `core/system.php:79`, `core/element.php:74`, `core/element.php:98`, `core/element.php:110`, `core/util.php:71` |

## Experiments and evaluation creation

| ID | Function and expected outcome | Access | Source |
| --- | --- | --- | --- |
| EXPERIMENT-01 | Create an experiment with name, description, runs per setting, Alternating/Ascending distribution, warm-up preference, and system-defined inputs. Create its internal project sequence ID and event. | Project member/owner; admin | `controllers/builder.php:209`, `controllers/builder.php:252`, `views/builder/create.php:175` |
| EXPERIMENT-02 | Validate required fields, checked selections, supported parameter values, and positive run counts; show actionable feedback without losing entered values. Percentage totals currently only receive visual warnings; v3 validation policy needs explicit tests. | Authorized project users | `views/builder/create.php:154`, `libraries/elements/checkbox-selection/render.template.html:17`, `libraries/builder.php:52` |
| EXPERIMENT-03 | Copy a current or archived experiment into a new experiment for its project. Preserve parameter values, run count/distribution, description, and warm-up preference; enter a new name. Enforce source-project authorization. | Authorized project users | `views/project/detail.php:183`, `controllers/builder.php:237`, `libraries/builder.php:139`, `core/element.php:110` |
| EXPERIMENT-04 | Edit experiment name/description/default environment; validate name length; persist defaults and immediately preselect the saved environment in the Run dialog. | Project member/owner; admin | `views/experiment/detail.php:53`, `views/experiment/detail.php:129`, `api/ui/experiment.php:38` |
| EXPERIMENT-05 | Open the Run Configuration dialog; choose system-local or CEM environment; show CEM node counts; edit software version overrides; run or cancel/reset. | Authorized project users | `controllers/experiment.php:159`, `views/experiment/detail.php:302` |
| EXPERIMENT-06 | Generate a new evaluation and jobs for the parameter combination/run distribution, selected phases, environment, and version overrides. Enforce the maximum jobs per evaluation and reject zero-job/invalid-environment submissions without partial records. | Authorized project users | `controllers/builder.php:69`, `controllers/builder.php:121`, `controllers/builder.php:156`, `controllers/builder.php:179`, `libraries/builder.php:52` |
| EXPERIMENT-07 | List/open current and archived evaluations with internal ID/name/description/star state; archive a nonrunning evaluation and restore an archived evaluation; reject archiving a still-running evaluation. | Authorized project users | `controllers/experiment.php:100`, `views/experiment/detail.php:238` |
| EXPERIMENT-08 | List system-wide and experiment-local result configurations; select one; create/copy/rename/delete experiment configurations; edit Overall/Job definitions. System-wide definitions are read-only here but can be copied. Prevent deleting the selected configuration. | Authorized project users | `controllers/experiment.php:58`, `controllers/experiment.php:139`, `views/experiment/detail.php:154` |
| EXPERIMENT-09 | Read the experiment timeline and follow related project/evaluation/job links. | Authorized project users | `controllers/experiment.php:136`, `views/experiment/detail.php:289` |

## Evaluations, jobs, and live progress

| ID | Function and expected outcome | Access | Source |
| --- | --- | --- | --- |
| EVALUATION-01 | List running evaluations with hierarchical IDs, name, experiment, project, and system; open the evaluation. Failed jobs count as active in this baseline. | Project members; admin | `controllers/evaluation.php:44`, `views/evaluation/overview.php:99` |
| EVALUATION-02 | Admin switches own/all evaluations and can navigate to Jobs. Do not treat legacy arbitrary user filtering as authorization. | Admin | `controllers/evaluation.php:46`, `views/evaluation/overview.php:77` |
| EVALUATION-03 | Edit evaluation name/description; show its environment; enforce required/maximum-length name rules and save/error feedback. | Authorized project users | `views/evaluation/detail.php:30`, `views/evaluation/detail.php:173`, `api/ui/evaluation.php:39` |
| EVALUATION-04 | Fetch jobs immediately and refresh them every 10 seconds; show loading state, internal job ID, description, phase/progress, all six status labels, finished-job download, and job navigation. Handle loading/network failures visibly in v3. | Authorized project users | `views/evaluation/detail.php:65`, `views/evaluation/detail.php:303`, `api/ui/evaluation.php:119` |
| EVALUATION-05 | Re-schedule All affects failed/aborted jobs in the evaluation and creates an event for each affected job. Confirm expected phase/progress reset semantics consistently with individual rescheduling. | Authorized project users | `controllers/evaluation.php:110`, `views/evaluation/detail.php:262` |
| EVALUATION-06 | Abort All affects scheduled/setup/running/failed jobs, leaves finished jobs intact, and creates per-job events. | Authorized project users | `controllers/evaluation.php:127`, `views/evaluation/detail.php:272` |
| EVALUATION-07 | Star/unstar an evaluation, update its visible indicator, and reflect the selection in its project's starred evaluations. | Authorized project users | `controllers/evaluation.php:144`, `views/evaluation/detail.php:282` |
| EVALUATION-08 | Open Results when at least one job is finished and the system supports results. If no configuration is selected, show an explanatory dialog linking to experiment settings. | Authorized project users | `controllers/evaluation.php:179`, `views/evaluation/detail.php:216` |
| EVALUATION-09 | Download a completed evaluation as an archive containing each job's result archive. Block this action while any job is unfinished and handle missing archives clearly. | Authorized project users | `controllers/evaluation.php:209`, `views/evaluation/detail.php:254`, `views/evaluation/download.php:31` |
| EVALUATION-10 | Identify CEM-deployed evaluations and show their environment without requiring knowledge of internal environment prefixes. | Authorized project users | `controllers/evaluation.php:194`, `views/evaluation/detail.php:294` |
| JOB-01 | List scheduled/setup/running/failed jobs; optionally include a separate finished/aborted table; sort columns and paginate the finished table; show evaluation, user, CEM type, system, status, and progress. | Current user; admin all-user scope | `controllers/job.php:41`, `views/job/jobs.php:33` |
| JOB-02 | Toggle active/all jobs and, as admin, own/all jobs without losing the other filter; open job detail from a table row/link. | Current user; admin filter | `views/job/jobs.php:68`, `views/job/jobs.php:95` |
| JOB-03 | Read evaluation, ID, user, environment, status; edit the job description with save/error feedback. | Authorized project users | `controllers/job.php:102`, `views/job/detail.php:287`, `api/ui/job.php:57` |
| JOB-04 | Display phase progress for CEM setup and enabled Prepare/Warm-up/Execute/Analyze/Clean phases; refresh every 2 seconds; distinguish completed/current/failed phases and relevant terminal states. | Authorized project users | `views/job/detail.php:100`, `views/job/detail.php:107`, `views/job/detail.php:233` |
| JOB-05 | Read ANSI-colored logs, normalize newlines, scroll to the latest output, refresh manually, and enable/disable automatic log updates. Progress keeps refreshing while log auto-update is disabled. | Authorized project users | `views/job/detail.php:90`, `views/job/detail.php:163`, `views/job/detail.php:338`, `api/ui/job.php:35` |
| JOB-06 | Abort an eligible scheduled/setup/running/failed job, persist status, and add an event. | Authorized project users | `views/job/detail.php:61`, `views/job/detail.php:375`, `api/ui/job.php:75` |
| JOB-07 | Re-schedule an eligible finished/aborted/failed job, reset current phase/progress, persist status, and add an event. | Authorized project users | `views/job/detail.php:75`, `views/job/detail.php:382`, `api/ui/job.php:75` |
| JOB-08 | Download the result archive of a finished job. Authenticated download authorization must cover both application routes and file delivery. | Authorized project users | `views/job/detail.php:390`, `views/evaluation/detail.php:124` |
| JOB-09 | Navigate to the parent evaluation and previous/next internal job, respecting the beginning/end of the evaluation. | Authorized project users | `controllers/job.php:121`, `views/job/detail.php:368`, `views/job/detail.php:397` |
| JOB-10 | Open/close the View CDL dialog and read safely escaped generated XML for the job's setup/evaluation/parameters/phases. | Authorized project users | `controllers/job.php:143`, `core/util.php:466`, `views/job/detail.php:407` |
| JOB-11 | Read job event history with links to related evaluation, experiment, project, actor, and CEM node when present. | Authorized project users | `controllers/job.php:158`, `views/job/detail.php:430`, `libraries/event.php:186` |

## Result builders and charts

| ID | Function and expected outcome | Access | Source |
| --- | --- | --- | --- |
| RESULT-01 | Open a result builder for a particular system/experiment, configuration ID, and either Overall Results or Job Results. Preserve independent definitions and contextual return navigation. | System owner/admin, or authorized experiment users | `controllers/results.php:38`, `views/results/build.php:26` |
| RESULT-02 | Add a graph of a supported type; edit its fields; delete after confirmation; reset unsaved changes; save the complete ordered graph definition, IDs, and Unicode values. | Authorized result-definition editors | `views/results/build.php:46`, `views/results/build.php:78`, `api/ui/results.php:38` |
| RESULT-03 | Bar plot: edit title and job result parameter; render all expected labels/datasets with a zero-based vertical scale and legend. | Definition editors; authorized evaluation viewers | `libraries/graphs/bar-plot/build.template.html`, `libraries/graphs/bar-plot/process.php`, `libraries/graphs/bar-plot/render.template.html` |
| RESULT-04 | Line plot: edit title and result parameter names; render series and index tooltips. | Definition editors; authorized evaluation viewers | `libraries/graphs/line-plot/build.template.html`, `libraries/graphs/line-plot/process.php`, `libraries/graphs/line-plot/render.template.html` |
| RESULT-05 | Multi-parameter line plot: edit title, configuration iteration parameters, and plotted result parameter; aggregate repeated configurations correctly. | Definition editors; authorized evaluation viewers | `libraries/graphs/line-plot-multi/build.template.html`, `libraries/graphs/line-plot-multi/process.php`, `libraries/graphs/line-plot-multi/render.template.html` |
| RESULT-06 | Pie plot: edit title and comma-separated result parameters; display the baseline's doughnut-style result and legend with accurate values. | Definition editors; authorized evaluation viewers | `libraries/graphs/pie-plot/build.template.html`, `libraries/graphs/pie-plot/process.php`, `libraries/graphs/pie-plot/render.template.html` |
| RESULT-07 | Box plot: edit title, iteration parameters, result parameter, and horizontal/vertical orientation; show distribution values/tooltips; display a useful warning when fewer than three runs exist. | Definition editors; authorized evaluation viewers | `libraries/graphs/boxplot/build.template.html`, `libraries/graphs/boxplot/process.php`, `libraries/graphs/boxplot/render.template.html`, `libraries/results.php:126` |
| RESULT-08 | Show overall charts and per-configuration groups of job charts; group repeated runs by configuration identifier; use finished jobs only; display graph/configuration titles. | Authorized evaluation viewers | `controllers/results.php:77`, `libraries/results.php:196`, `views/results/show.php:31` |
| RESULT-09 | Refresh results as more jobs finish: poll the finished-job count every 5 seconds and update chart data. Newly appearing configurations and all chart types need runtime coverage. | Authorized evaluation viewers | `libraries/results.php:201`, `api/ui/evaluation.php:80` |
| RESULT-10 | Discover/preserve system-supplied result plot extensions and local assets through a defined v3 extension contract. Do not silently discard unknown stored plot types. | Definition editors; authorized viewers | `core/system.php:90`, `core/plot.php:76`, `core/util.php:200`, `core/systemview.php:106` |

## CEM and administration

| ID | Function and expected outcome | Access | Source |
| --- | --- | --- | --- |
| CEM-01 | List currently reporting nodes; include missing nodes on request using the configured missing-node timeout. Show hostname, ID, idle/current-job state, and health-warning marker. | Admin | `controllers/cem.php:38`, `views/cem/overview.php:64` |
| CEM-02 | Search, sort, and paginate nodes; open node detail from a table row/link. | Admin | `views/cem/overview.php:30` |
| CEM-03 | Read node hostname, ID, environment, version, OS, IP, and last-status-update age/precise timestamp. | Admin | `controllers/cem.php:57`, `views/cem/detail.php:46` |
| CEM-04 | Display CPU and memory utilization with progress and warning colors. Preserve meaningful thresholds: CPU above 35/50%; memory above 60/85%. Handle missing values and zero totals safely. | Admin | `views/cem/detail.php:65`, `views/cem/detail.php:87` |
| CEM-05 | Read multiline node health warnings or OK state and node event history, following related job/node links. | Admin | `views/cem/detail.php:111`, `libraries/event.php:205` |
| ADMIN-01 | List users with active state, identity, admin/superadmin badge, Edit, and eligible Switch action; default to alive accounts and allow including deactivated accounts. | Admin | `controllers/admin.php:127`, `views/admin/main.php:233` |
| ADMIN-02 | Create a user with username, names, gender, email, password and matching repeat; validate duplicate/invalid input; record an event and return to administration. | Admin | `controllers/admin.php:193`, `views/admin/newUser.php:54` |
| ADMIN-03 | Confirm/cancel a soft user switch; prohibit recursive switches, switching to self, and switching to superadmin; visibly identify impersonation and return to the original account through logout. | Admin | `controllers/admin.php:287`, `libraries/auth.php:195`, `views/admin/switchUser.php:35` |
| ADMIN-04 | Select hard switch when confirmed; replace the session with the target account and stop offering a return to the original admin session. | Admin | `views/admin/switchUser.php:44`, `libraries/auth.php:214` |
| ADMIN-05 | Display/edit global settings grouped by section, with a save action per group. VCS settings have a dedicated update interface; CEM environments have a dedicated list. Preserve zero/empty values when valid in v3. | Admin | `controllers/admin.php:108`, `controllers/admin.php:140`, `views/admin/main.php:156` |
| ADMIN-06 | List/add/delete global CEM environment names; reject duplicates and confirm deletion. Changes feed experiment environment selectors and node counts. | Admin | `controllers/admin.php:64`, `views/admin/main.php:108`, `controllers/experiment.php:163` |
| ADMIN-07 | Show the configured data-directory mount state (mounted/not mounted/unknown) and inspection error when relevant. Mount action is separately restricted to superadmin. | Admin inspection; superadmin action | `controllers/admin.php:172`, `views/admin/main.php:74`, `controllers/admin.php:341` |
| ADMIN-08 | Select a configured application branch and Save & Update, or trigger Update directly; display update output and navigation. Isolate these operations from production during tests. | Superadmin | `controllers/admin.php:116`, `controllers/admin.php:329`, `views/admin/main.php:73`, `views/admin/update.php` |
| ADMIN-09 | Trigger data-directory mounting and view the operation result. Test with an injected fake mount service or disposable environment. | Superadmin | `controllers/admin.php:341`, `views/admin/mountDataDirectory.php` |
| ADMIN-10 | Open the unlinked Demo Functions page and reset the demo dataset to its initial state, logging out afterwards. This is real reachable UI; destructive production execution is not needed for parity testing. | Superadmin | `controllers/admin.php:264`, `views/admin/demo.php:37` |
| ADMIN-11 | Reset local systems through the Demo Functions page, leaving remote-backed systems excluded. Use an isolated fixture and verify affected paths. | Superadmin | `controllers/admin.php:269`, `views/admin/demo.php:47` |
| ADMIN-12 | Read the administration systems list with display names and integration identifiers. | Admin | `controllers/admin.php:157`, `views/admin/main.php:275` |

## Installer, dormant capabilities, and extension boundaries

| ID | Function and expected outcome | Access | Source |
| --- | --- | --- | --- |
| INSTALL-01 | Standalone installer accepts repository URL/optional credentials/branch and database connection inputs; reports setup progress/failure and links to the installed application. Decide whether this is included in the v3 UI replacement or retained as a separate setup tool. | Uninstalled deployment | `install.php:192` |
| EXTENSION-01 | Legacy system pages and system APIs can provide their own views/assets. The old New Job template also supports loading/saving named predefined parameter sets. None of these custom page/asset directories were observed in the deployment inventory, but supported extension behavior still needs an explicit replacement or compatibility contract. | Extension-defined access | `core/router.php:68`, `core/systemview.php:47`, `webroot/template/newJob/template.php:29`, `webroot/template/results/template.php` |
| DORMANT-01 | The MaaS credential handler exists, but its administration form is commented out. Do not describe it as an available screen; decide explicitly whether to retain the backend integration. | Admin backend only | `controllers/admin.php:49`, `views/admin/main.php:185` |
| DORMANT-02 | The run generator supports randomized distribution (`rand`), but the experiment form only offers Alternating and Ascending. This is latent backend behavior, not a currently selectable UI option. | Backend-supported input | `controllers/builder.php:150`, `views/builder/create.php:188` |
| DORMANT-03 | Prepare, Execute, Analyze, and Clean phase flags are accepted by the controller; only Warm-up is selectable in the current experiment form. The other phases default enabled. Preserve existing stored phase semantics. | Backend-supported input | `controllers/builder.php:267`, `views/builder/create.php:197` |

## Proposed meaningful end-to-end scenarios

These are test designs, not test execution results. Use a disposable seeded
database, deterministic system repositories, local downloads/uploads, and a fake
worker/CEM adapter. Do not use production data, run demo reset against a live
installation, or invoke actual host mounts/self-updates in routine CI.

| Scenario ID | Feature IDs | Fixture and observable assertions |
| --- | --- | --- |
| E2E-AUTH-01 | AUTH-01, AUTH-03, SHELL-08 | Visit a protected experiment deep link while signed out, fail login, sign in successfully, and arrive at the same experiment; inactive/unconfirmed account variants fail without creating a session. |
| E2E-AUTH-02 | AUTH-02, AUTH-04 | Sign in with/without Remember Me using isolated browser contexts; verify eligible session restoration and that logout prevents another restoration. |
| E2E-PROFILE-01 | PROFILE-01, PROFILE-02 | Edit own identity, reload, change password, sign out/in; wrong old password, mismatch, invalid email, and another-user URL/API access leave data unchanged. |
| E2E-ADMIN-USER-01 | PROFILE-03, PROFILE-04, PROFILE-05, ADMIN-01, ADMIN-02 | Admin creates and edits a user, filters inactive accounts, resets password, disables/reactivates the account; superadmin changes roles and role-restricted controls/endpoints respond accordingly. |
| E2E-IMPERSONATION-01 | SHELL-04, AUTH-04, ADMIN-03, ADMIN-04 | Soft switch shows target data and banner, blocks recursive/superadmin switches, and logout returns to original admin; separate hard-switch case ends the original session. |
| E2E-PROJECT-01 | PROJECT-01–PROJECT-07, HOME-01 | Create project, edit Unicode name/description, add collaborator twice, remove collaborator, archive/restore; test member/owner/admin/outsider/visitor access and unchanged dashboard/list counts after failed actions. |
| E2E-PROJECT-02 | PROJECT-08–PROJECT-10, EXPERIMENT-07, EVALUATION-07 | Seed current/archived experiments and active/completed/starred evaluations; verify toggles, child navigation, star propagation, archive rejection while running, restore, and event links. |
| E2E-SYSTEM-01 | SYSTEM-01–SYSTEM-06, SYSTEM-12–SYSTEM-14 | Admin creates local system, transfers ownership, edits settings, reads identifier/history; reject unauthorized access and archive while a current project references the system; fake remote update validates output and return navigation. |
| E2E-SYSTEM-02 | SYSTEM-07–SYSTEM-11, ADMIN-06 | Add/remove local and CEM environments, toggle CEM, edit setup/evaluation settings/defaults, create/run an experiment, and inspect generated CDL and selected environment. Include zero and empty settings where valid. |
| E2E-SYSTEM-FILES-01 | SYSTEM-15–SYSTEM-17 | Upload supported/unsupported/missing logos; export a known schema/results configuration; import into another disposable system; compare semantic definitions and rendered forms/charts. Malformed imports must not overwrite valid data. |
| E2E-PARAM-01 | PARAM-01–PARAM-09, PARAM-14, EXPERIMENT-01–EXPERIMENT-03 | Build groups with scalar, dropdown, checkbox, interval, and string-list inputs; save/reload, create experiment, copy it, run it, and assert exact parameter values and number/order of generated jobs. Include zero/false/Unicode values. |
| E2E-PARAM-02 | PARAM-10–PARAM-13, PARAM-16 | Build percentage/interval/dependent-percentage/ratio inputs and nested dependent groups; change source values; assert calculated values, warnings, hidden/disabled exclusions, copied data, and exact resulting job configurations. |
| E2E-PARAM-03 | PARAM-15, EXPERIMENT-05, EXPERIMENT-06 | Seed at least two version elements, resize each version list independently, copy experiment, override versions in Run dialog, and verify correct version/repository pairing in generated jobs. |
| E2E-RUN-01 | EXPERIMENT-01, EXPERIMENT-04–EXPERIMENT-06 | Run known combinations under Alternating/Ascending with warm-up on/off, system/CEM environment, and version override; assert job order/count/phase mask/CDL and German-format generated evaluation name. |
| E2E-RUN-02 | EXPERIMENT-02, EXPERIMENT-06 | Submit zero runs, invalid range/step, no selections, unknown environment, and a combination exceeding max jobs; assert understandable feedback and no partial evaluation/job/event records. |
| E2E-EVALUATION-01 | EVALUATION-01–EVALUATION-07, JOB-01, JOB-02 | Worker fixture moves jobs through scheduled/setup/running/failed/finished/aborted; assert list membership, 10-second refreshed table, status/progress, star propagation, bulk abort/reschedule affected jobs and event records. |
| E2E-JOB-01 | JOB-03–JOB-07, JOB-09–JOB-11 | Visit each job boundary, edit description, feed phase/status changes and ANSI logs, toggle log auto-update, refresh manually, abort/reschedule, inspect CDL and events. Assert progress refresh continues when log auto-update is off. |
| E2E-DOWNLOAD-01 | EVALUATION-09, JOB-08 | Download a finished job and completed evaluation; inspect ZIP entries and contents. Reject unfinished/missing/unauthorized resources both through app endpoints and directly requested file paths. |
| E2E-RESULTS-01 | SYSTEM-19, EXPERIMENT-08, RESULT-01–RESULT-08 | Create/copy/rename/select configurations; configure each graph type in overall and job scope; seed known result values; assert datasets, grouping, orientation, labels, and box-plot insufficient-run feedback. Reject deleting selected/in-use configurations. |
| E2E-RESULTS-02 | EVALUATION-08, RESULT-09 | Open results after one finished job, finish additional jobs/new configurations, and assert 5-second updates add the correct data/groups without stale charts; cover missing result configuration dialog and recovery link. |
| E2E-CEM-01 | CEM-01–CEM-05 | Seed idle/busy/missing/warning nodes, search/sort/page/filter, open details, verify metadata/date formatting/utilization thresholds and associated job/timeline links. Include zero/missing metrics. |
| E2E-SETTINGS-01 | ADMIN-05–ADMIN-09, ADMIN-12 | Save each global settings group and CEM environment change; fake mounted/unmounted/unknown states, successful/failed mount, branch change/update; assert output, access controls, and data persistence. |
| E2E-DEMO-01 | ADMIN-10, ADMIN-11 | In disposable data/filesystem only, invoke each demo operation and assert its defined dataset/local-system scope and session outcome; reject ordinary user/admin access. |
| E2E-EXTENSIONS-01 | PARAM-17, RESULT-10, EXTENSION-01 | Load representative system-owned custom parameter/plot and legacy-page fixtures through the agreed v3 extension mechanism; verify their input, generated jobs, rendered results, and local assets. |
| E2E-INSTALL-01 | INSTALL-01 | If in scope, install into an empty disposable database/filesystem and verify first login and useful failure recovery without persisting installer credentials in output. |
| E2E-RESPONSIVE-01 | All shipped screens; SHELL-01–SHELL-05 | Execute core user/admin workflows at narrow phone, wide phone, tablet, and desktop widths; verify navigation, forms, dialogs, tables, plots, logs, focus order, and touch controls without viewport overflow or inaccessible actions. |
| E2E-VERSION-01 | New v3 requirement | Load build A, expose build B's version metadata, show a bottom-centered stale-client notice, keep it unobtrusive on mobile, and use its button to fetch B. Include unavailable version endpoint and an unsaved form. |
| E2E-ASSETS-DATES-01 | All shipped screens; SHELL-06, SHELL-07 | Block external origins and exercise complete routes; no CDN/font/asset request is required. Verify all visible absolute dates/times and tooltips use the agreed German format and timezone, while labels/docs stay English. |

The authorization matrix should apply visitor, unrelated user, project member,
project owner, system owner, admin, and superadmin variants to each relevant
mutation and download. UI hiding alone is not an authorization test. The new API
also needs direct HTTP tests for forbidden reads/writes, CSRF/session behavior,
validation errors, and atomic failed operations.

## Source discrepancies and parity risks

These are source observations or risks inferred from the baseline. They are not
runtime-confirmed bugs unless separately reproduced. Correct the intended user
workflow rather than preserve insecure or broken implementation details.

| ID | Observation and migration implication | Source |
| --- | --- | --- |
| D-01 | UI project, experiment, evaluation, and job metadata mutations are declared public; evaluation/job reads and log access are also public. The new API must authenticate and authorize per resource. Builder/result APIs require login but omit system/resource ownership checks. | `api/ui/project.php:35`, `api/ui/experiment.php:35`, `api/ui/evaluation.php:37`, `api/ui/job.php:9`, `api/ui/job.php:52`, `api/ui/builder.php:33`, `api/ui/results.php:33` |
| D-02 | Evaluation bulk reschedule/abort/star/unstar executes before project-membership validation. Authorize before any write and prove forbidden requests leave records unchanged. | `controllers/evaluation.php:110`, `controllers/evaluation.php:162` |
| D-03 | Many mutations use GET links, and server-rendered forms lack an evident CSRF token. Direct uploaded ZIP paths can bypass controller authorization. Replace with authenticated, CSRF-protected mutations and authorized file delivery. | `views/project/detail.php:120`, `views/admin/system.php:178`, `views/job/detail.php:391`, `views/evaluation/detail.php:124` |
| D-04 | User role selector checks `isAdmin() == 2`, although `isAdmin()` returns boolean; the controller correctly checks `isSuperAdmin()`. The intended superadmin role-edit capability should work in v3. | `views/user/edit.php:101`, `libraries/auth.php:230`, `controllers/user.php:147` |
| D-05 | Export packs `parameters.json`, `results.json`, and `resultsJob.json`; current storage/import uses `resultConfigurations.json`, and conversion deletes the old result files. A successful download is insufficient evidence of round-trip parity. | `views/admin/systemExport.php:37`, `controllers/admin.php:644`, `core/system.php:134` |
| D-06 | Plot-refresh code constructs `Results_Library` without its required result ID. It also reassigns datasets on existing charts rather than rebuilding result groups for newly finished configurations. Live result updates need real regression tests. | `api/ui/evaluation.php:90`, `libraries/results.php:45`, `libraries/results.php:223` |
| D-07 | Custom result plots are appended to a value parameter, unlike parameter elements passed by reference. Discovery appears ineffective for callers. Existing custom deployed artifacts must be inspected before claiming extension parity. | `core/system.php:79`, `core/system.php:90`, `libraries/results.php:61` |
| D-08 | Software-version render template uses fixed DOM IDs and top-level `const copy`; multiple elements can conflict. Defaults/copy rendering in several element types also uses `empty()`, risking loss of zero values. | `libraries/elements/software-version-list/render.template.html:9`, `libraries/elements/integer/render.template.html:7`, `libraries/elements/percentage/render.template.html:15` |
| D-09 | Percentage-interval total validation searches `-min`/`-max` IDs while its rendered fields use `-start`/`-end`. Percentage warnings mostly style borders and do not themselves prevent submission. Define and test authoritative backend validation. | `views/builder/create.php:88`, `libraries/elements/percentage-interval/render.template.html:14` |
| D-10 | Evaluation is persisted before environment validation, and version override handling includes `var_dump($data)`. Validate first, create data atomically, and prevent request/secret data appearing in pages. | `controllers/builder.php:101`, `controllers/builder.php:172`, `controllers/builder.php:179` |
| D-11 | Bulk rescheduling updates only status; individual job rescheduling also clears phase and progress. Decide consistent reset semantics and verify them across both actions. | `controllers/evaluation.php:113`, `api/ui/job.php:81` |
| D-12 | Global/system settings update loops use `!empty()`, preventing zero/empty values from being saved. Several page forms therefore cannot represent all plausible settings correctly. | `controllers/admin.php:112`, `controllers/admin.php:416`, `controllers/admin.php:436` |
| D-13 | System general form shows an editable repository URL, but its save handler does not change the URL. Archived systems have no restore action; project/system archive filter semantics differ. Document intentional v3 changes explicitly. | `views/admin/system.php:129`, `controllers/admin.php:392`, `controllers/admin.php:470`, `controllers/project.php:58`, `controllers/admin.php:362` |
| D-14 | Admin status table initializes nonexistent DataTable IDs (`evaluation`, `f-evaluation`) while rendering `evaluations`. Source options alone do not prove pagination/sorting actually works. Several detail tables also duplicate HTML IDs. | `views/evaluation/overview.php:33`, `views/evaluation/overview.php:103`, `views/project/detail.php:213`, `views/project/detail.php:241` |
| D-15 | Project member filtering constructs `allUsers` by checking each user against the IDs of that same list, removing every entry; the visible selector instead uses the separate unfiltered `users` collection. Retain usable add-member behavior, not the dead intermediate list. | `controllers/project.php:264`, `views/project/detail.php:292` |
| D-16 | Direct copy-experiment loading does not validate the source experiment's project authorization. New API must authorize both source and destination. | `controllers/builder.php:237` |
| D-17 | Login/shared header loads Google Fonts remotely; avatars use Gravatar; viewport disables zoom. The no-CDN/local-asset requirement and responsive accessibility require auditing these independent external/zoom dependencies. | `views/user/login.php:40`, `webroot/design/default/header.php:41`, `webroot/design/default/navbar.php:54`, `libraries/auth.php:425` |
| D-18 | Home loads 20 global events without user filtering, and legacy list handlers accept `user=all` without consistently enforcing admin scope. Define authorized visibility once and use it for dashboard, list, chart, and download endpoints. | `controllers/home.php:47`, `controllers/project.php:49`, `controllers/job.php:50`, `controllers/evaluation.php:46` |
| D-19 | Node precise timestamps and timeline tooltips use SQL/ISO formatting while evaluation default names use `d.m.Y - H:i`. Enforce one German-format display policy without conflating API serialization with UI formatting. | `views/cem/detail.php:58`, `libraries/event.php:170`, `controllers/builder.php:171` |
| D-20 | Old extension paths and predefined-job template still exist. Subsequent deployment inspection found no custom parameter/plot directories, root PHP system pages, or custom asset directories; the inspected definitions use all built-in input and plot types. Preserve a supported extension contract without claiming these dormant paths are in production use. | `core/router.php:68`, `core/systemview.php`, `webroot/template/newJob/template.php` |

## Required decisions and evidence before removing the old UI

1. Convert the authorized deployment inventory into sanitized, representative
   fixtures covering every built-in parameter/plot type, current result schemas,
   and legacy result files. Retain the extension compatibility
   decision even though no custom extension directories/pages were observed.
2. Preserve installer and superadmin self-update/mount/demo capabilities. The
   confirmed CI-package distribution requires adapting installer and self-update
   while preserving branch selection and adding rollback. Test destructive
   operations only in disposable environments.
3. Verify German-format date/time display using the confirmed `Europe/Zurich`
   timezone, including daylight-saving boundaries; specify supported
   browsers/devices and session/API compatibility for existing Chronos Agent/CEM
   integrations.
4. Agree on intended authorization, failed-run/reschedule semantics, dependency
   validation, and import/export compatibility where the baseline contradicts
   itself. These need executable acceptance tests.
5. Complete runtime test evidence for every shipped feature ID; distinguish
   automated coverage, manual device/browser checks, unavailable integration
   fixtures, and accepted scope changes. Preserve baseline data/results and prove
   migration/rollback behavior before deleting the old views and their assets.

