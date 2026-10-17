import { readFile } from 'node:fs/promises';
import { test, expect, login, api } from './helpers';

// These scenarios deliberately remain visible as fixmes. A skipped case is an
// unresolved behavior, never evidence of parity or a passing source scan.

test.fixme('JOB-02: admin all-users plus all-statuses must render without an empty-filter SQL error', {
  annotation: { type: 'defect', description: 'Observed SQLSTATE42000/1064 near ORDER BY Job.jobId ASC when admin disables both own-user and active-status filters. controllers/job.php passes FILTER=[] into the database factory. Active all-user and own-user pagination are tested independently.' },
}, async ({ page }) => {
  await login(page, 'admin');
  await page.goto('/job/jobs/status=all');
  await page.locator('#showAllUser').click();
  await expect(page.locator('#finished')).toContainText('Private Evaluation');
  await expect(page.locator('body')).not.toContainText('SQLSTATE');
});

test.fixme('PARAM-06, JOB-10: CDL serialization must preserve ampersands and XML characters (legacy emits an empty element)', {
  annotation: { type: 'defect', description: 'Observed after a real16-job form submission: string label Zürich & XML <value> renders as <label/>. CDL_Library passes text directly to DOMDocument::createElement instead of a text node/escaped value; basic Unicode strings are tested independently.' },
}, async ({ page }) => {
  await login(page);
  const response = await page.request.post('/builder/create', { form: {
    projectId: '1', name: 'XML Text Regression', description: 'Character serialization contract.',
    runs: '1', 'run-distribution': 'alter', phase_warmUp: '1',
    'elements[]': 'fixture-label', 'fixture-label-type': 'string', 'fixture-label-parameter': 'label',
    label: 'Zürich & XML <value>',
  } });
  const experimentId = Number((await response.text()).match(/id="id" type="text" value="(\d+)"/)?.[1]);
  expect(experimentId).toBeGreaterThan(3);
  await page.goto(`/experiment/detail/id=${experimentId}`);
  await page.getByRole('button', { name: /Run Evaluation$/ }).click();
  await page.locator('#environment').selectOption('system-local');
  await page.locator('#modal-run-evaluation').getByRole('button', { name: /Run$/ }).click();
  const evaluationId = await page.locator('#id').inputValue();
  const generated = await api<{ jobs: Array<{ id: number }> }>(page.request, `/api/ui/evaluation/id=${evaluationId}/action=jobs`);
  await page.goto(`/job/detail/id=${generated.jobs[0].id}`);
  await page.getByRole('button', { name: /View CDL$/ }).click();
  await expect(page.locator('#modal-cdl code')).toContainText('Zürich &amp; XML &lt;value&gt;');
});

test.fixme('JOB-01: configured rows-per-page must be honored (legacy Setting object always falls back to 25)', {
  annotation: { type: 'defect', description: 'Runtime fixture config sets rowsPerPage=5, but core/view.php:346 checks is_numeric on a Setting object, so every table uses fallback25. Independent pagination behavior is exercised with more than25 real generated jobs.' },
}, async ({ page }) => {
  await login(page);
  await page.goto('/job/jobs/status=all');
  await expect(page.locator('#finished tbody tr')).toHaveCount(5);
});

test.fixme('PARAM-01: parameter group names containing spaces must survive legacy path routing (observed HTTP403)', {
  annotation: { type: 'defect', description: 'The UI sends a new-group name inside a rewritten URL path. The isolated current Apache stack rejects the resulting unescaped-space query with403. Basic group lifecycle uses a space-free name; this explicit case retains the missing behavior.' },
}, async ({ page }) => {
  await login(page);
  await page.goto('/builder/build/id=1');
  await page.locator('[data-target="#modal-group"]').click();
  await page.locator('#group-form [name="name"]').fill('Group With Spaces');
  await page.locator('#modal-group').getByRole('button', { name: /Add$/ }).click();
  await expect(page.locator('.box-title').filter({ hasText: /^Group With Spaces$/ })).toBeVisible();
});

test.fixme('SYSTEM-05, SYSTEM-14: remote repository settings/update require a local remote-repository fixture and enabled flag', {
  annotation: { type: 'dependency', description: 'ENABLE_REMOTE_REPOSITORY is disabled in the baseline fixture; a local Git/Mercurial remote and command-isolated update adapter are not yet supplied. No live repository update is attempted.' },
}, async ({ page }) => {
  await login(page, 'admin');
  await page.goto('/admin/system/id=1');
  await expect(page.locator('[name="repository"]')).toBeVisible();
  await page.locator('[name="branch"]').selectOption('fixture-next');
  await page.locator('button[name="group"][value="general"]').click();
  await page.getByRole('link', { name: /Update$/ }).click();
  await expect(page.locator('.box-title')).toContainText('Output');
});

test.fixme('SYSTEM-16: modern configuration export must include resultConfigurations.json (legacy exporter omits it)', {
  annotation: { type: 'defect', description: 'views/admin/systemExport.php exports old results.json/resultsJob.json, while current storage and importer use resultConfigurations.json. D-05.' },
}, async ({ page }) => {
  await login(page);
  await page.goto('/admin/system/id=1');
  const [download] = await Promise.all([
    page.waitForEvent('download'), page.getByRole('link', { name: /Export$/ }).click(),
  ]);
  const bytes = await readFile((await download.path())!);
  expect(bytes.includes(Buffer.from('parameters.json'))).toBeTruthy();
  expect(bytes.includes(Buffer.from('resultConfigurations.json'))).toBeTruthy();
});

test.fixme('PARAM-15: fresh software-version inputs must initialize from an empty copy (legacy null.length crash)', {
  annotation: { type: 'defect', description: 'software-version-list/render.template.html evaluates copy.length when copy is null, before attaching its input listener. Multiple version elements also reuse DOM IDs and const names. D-08.' },
}, async ({ page }) => {
  await login(page);
  await page.goto('/builder/create/projectId=1');
  await page.locator('[name="version-numberOfVersions"]').fill('2');
  await expect(page.locator('[name="version-version-0"]')).toBeVisible();
  await expect(page.locator('[name="version-version-1"]')).toBeVisible();
  await page.locator('[name="version-version-0"]').fill('main');
  await page.locator('[name="version-version-1"]').fill('comparison');
  await page.locator('[name="version-numberOfVersions"]').fill('1');
  await expect(page.locator('[name="version-version-1"]')).toHaveCount(0);
  await expect(page.locator('[name="version-version-0"]')).toHaveValue('main');
});

test.fixme('PARAM-17: custom parameter extension lifecycle requires a representative extension fixture/contract', {
  annotation: { type: 'dependency', description: 'Deployment inventory found built-in definitions only. The isolated stack does not yet include a system-owned custom parameter build/render/process/copy extension or its agreed v3 schema adapter.' },
}, async ({ page }) => {
  await login(page);
  await page.goto('/builder/build/id=1');
  await page.locator('#fixture-basic').getByRole('button', { name: /Add Element$/ }).click();
  await expect(page.locator('#element-form [name="type"] option[value="fixture-custom"]')).toBeAttached();
});

test.fixme('RESULT-09: live chart data endpoint must use the selected results ID (legacy constructor call is incomplete)', {
  annotation: { type: 'defect', description: 'api/ui/evaluation.php constructs Results_Library with one argument although its constructor requires resultId. No request mocking or passing replacement data hides this failure. D-06.' },
}, async ({ page }) => {
  await login(page);
  const data = await api<{ plotData: { datasets: unknown[] } }>(page.request, '/api/ui/evaluation/id=2/action=getPlotData/plotId=plotbar');
  expect(data.plotData.datasets.length).toBeGreaterThan(0);
});

test.fixme('RESULT-10: custom plot discovery/rendering requires fixture and correction of pass-by-value discovery', {
  annotation: { type: 'defect', description: 'core/system.php getResultPlots($plots) appends to a value argument. No custom plot extension fixture or replacement extension contract exists yet. D-07.' },
}, async ({ page }) => {
  await login(page);
  await page.goto('/results/build/systemId=1/type=1/resultId=system-1');
  await page.locator('[data-target="#modal-plot"]').click();
  await expect(page.locator('#plot-form [name="type"] option[value="fixture-custom-plot"]')).toBeAttached();
});

test.fixme('EXTENSION-01: legacy system page/predefined parameter flows require a representative old system fixture', {
  annotation: { type: 'dependency', description: 'No root PHP system pages/system_assets directories were observed in the authorized inventory. The stack has no controller implementing savePredefined/getPredefined; do not invent passing mock pages.' },
}, async ({ page }) => {
  await login(page);
  await page.goto('/system/fixture/newJob');
  await expect(page.locator('#predefined')).toBeVisible();
  await page.locator('#predefined').selectOption('fixture-predefined');
  await expect(page.locator('.storable').first()).toHaveValue('fixture-value');
});

test.fixme('ADMIN-08: self-update and branch rollback need an isolated CI-package update service', {
  annotation: { type: 'dependency', description: 'The legacy updater changes source/repositories. A disposable updater substack and CI package/rollback implementation are required before executing this mutation; current tests never update the development checkout.' },
}, async ({ page }) => {
  await login(page, 'superadmin');
  await page.goto('/admin/main');
  await page.locator('[name="branch"]').selectOption('fixture-next');
  await page.getByRole('button', { name: /Save \& Update$/ }).click();
  await expect(page.locator('.box-title')).toContainText('Output');
});

test.fixme('ADMIN-09: actual mounting needs an injected mount adapter or dedicated disposable mount namespace', {
  annotation: { type: 'dependency', description: 'Mount_Library invokes sudo /bin/mount. The regression stack intentionally has no privileged host mount capability or fake backend service, so it cannot verify a successful mount yet.' },
}, async ({ page }) => {
  await login(page, 'superadmin');
  await page.goto('/admin/mountDataDirectory');
  await expect(page.locator('.box-title')).toContainText('Output');
  await page.goto('/admin/main');
  await expect(page.locator('.box').filter({ hasText: 'Mount Status' })).toContainText('mounted');
});

test.fixme('ADMIN-10, ADMIN-11: destructive demo resets require their own disposable demo-state substack', {
  annotation: { type: 'dependency', description: 'The seeded legacy regression stack is not the application demo dataset. Demo reset/local-system reset have filesystem-wide side effects inside the application and require a separate disposable lifecycle test environment.' },
}, async ({ page }) => {
  await login(page, 'superadmin');
  await page.goto('/admin/demo');
  await expect(page.getByRole('heading', { name: 'Demo Functions' })).toBeVisible();
  await expect(page.locator('button[onclick*="/admin/demo/reset=true"]')).toBeVisible();
  await expect(page.locator('button[onclick*="/admin/demo/local=true"]')).toBeVisible();
});

test.fixme('INSTALL-01: installer/package distribution needs an empty disposable installation target', {
  annotation: { type: 'dependency', description: 'The application stack is already installed. Executing install.php there can overwrite configuration/source and clone external repositories. An empty database/filesystem package-install substack is not supplied yet.' },
}, async ({ page }) => {
  await page.goto('/install.php');
  await expect(page.getByRole('heading', { name: 'Chronos Installation' })).toBeVisible();
  await page.locator('[name="dbServer"]').fill('fixture-db');
  await page.locator('[name="dbDatabase"]').fill('chronos_install_fixture');
  await page.locator('[name="dbUsername"]').fill('fixture');
  await page.locator('[name="dbPassword"]').fill('fixture-only');
  await page.getByRole('button', { name: /Set up$/ }).click();
  await expect(page.locator('body')).toContainText('Setup successful!');
});

test.fixme('PROFILE-05 security: ordinary admin role selector must be hidden (legacy true == 2 exposes it)', {
  annotation: { type: 'defect', description: 'views/user/edit.php uses isAdmin() == 2. PHP compares booleans loosely, exposing the role selector to ordinary admins even though the backend ignores their role change. D-04.' },
}, async ({ page }) => {
  await login(page, 'admin');
  await page.goto('/user/edit/id=4');
  await expect(page.locator('[name="admin"]')).toHaveCount(0);
});

test.fixme('SHELL-07 date policy: all precise timestamps must use German formatting (legacy tooltips are ISO)', {
  annotation: { type: 'defect', description: 'Event timeline/node timestamp tooltips display SQL/ISO formats. They do not meet the requested German display policy until the v3 formatter replaces them. D-19.' },
}, async ({ page }) => {
  await login(page);
  await expect(page.locator('.timeline-item .time').first()).toHaveAttribute('title', /^\d{2}\.\d{2}\.\d{4} \d{2}:\d{2}$/);
});

test.fixme('PROJECT-02, JOB-08 security: private UI API/file reads must reject unrelated users (legacy routes are public)', {
  annotation: { type: 'defect', description: 'Legacy UI API reads/mutations are public and uploaded ZIP paths have no application authorization. Intended authorization is recorded as unresolved rather than blessing the insecure response. D-01/D-03.' },
}, async ({ page }) => {
  await login(page, 'member');
  const response = await page.request.get('/api/ui/job/id=14');
  expect([401, 403, 404]).toContain(response.status());
  const archive = await page.request.get('/data/evaluation/14.zip');
  expect([401, 403, 404]).toContain(archive.status());
});

test('DORMANT-01: dormant MaaS handler stores synthetic key components in real global settings', async ({ page }) => {
  await login(page, 'admin');
  const response = await page.request.post('/admin/main', {
    form: { group: 'maas', maas_key: 'fixture-consumer:fixture-key:fixture-secret' },
  });
  expect(response.ok()).toBeTruthy();
  await page.goto('/admin/main');
  await expect(page.locator('[name="maas###consumer_key"]')).toHaveValue('fixture-consumer');
  await expect(page.locator('[name="maas###key"]')).toHaveValue('fixture-key');
  await expect(page.locator('[name="maas###secret"]')).toHaveValue('fixture-secret');
});

test('DORMANT-02: backend accepts randomized run distribution and generates the complete multiset', async ({ page }) => {
  await login(page);
  const response = await page.request.post('/builder/create', {
    form: {
      projectId: '1', name: 'Random Distribution Regression', description: 'Latent backend distribution contract.',
      runs: '2', 'run-distribution': 'rand', phase_warmUp: '1',
      'elements[]': 'fixture-size', 'fixture-size-type': 'interval', 'fixture-size-parameter': 'size',
      'size-min': '10', 'size-max': '20', 'size-step': '10',
    },
  });
  expect(response.ok()).toBeTruthy();
  const html = await response.text();
  const experimentId = Number(html.match(/id="id" type="text" value="(\d+)"/)?.[1]);
  expect(experimentId).toBeGreaterThan(3);
  await page.goto(`/experiment/detail/id=${experimentId}`);
  await page.getByRole('button', { name: /Run Evaluation$/ }).click();
  await page.locator('#environment').selectOption('system-local');
  await page.locator('#modal-run-evaluation').getByRole('button', { name: /Run$/ }).click();
  const evaluationId = await page.locator('#id').inputValue();
  const data = await api<{ jobs: Array<{ id: number; status: string }> }>(page.request, `/api/ui/evaluation/id=${evaluationId}/action=jobs`);
  expect(data.jobs).toHaveLength(4);
  const configurations: string[] = [];
  for (const job of data.jobs) {
    await page.goto(`/job/detail/id=${job.id}`);
    await page.getByRole('button', { name: /View CDL$/ }).click();
    configurations.push((await page.locator('#modal-cdl code').textContent())!);
  }
  expect(configurations.filter(cdl => /<size>10<\/size>/.test(cdl))).toHaveLength(2);
  expect(configurations.filter(cdl => /<size>20<\/size>/.test(cdl))).toHaveLength(2);
});
