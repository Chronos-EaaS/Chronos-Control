import { test, expect, login, fitsViewport, type Page } from './fixture';

async function data(page: Page, path: string): Promise<any> {
  const response = await page.request.get(`/api/ui${path}`); expect(response.ok(), path).toBeTruthy(); return (await response.json()).data;
}
function card(page: Page, title: string) { return page.locator('section.card').filter({ has: page.getByRole('heading', { name: title, exact: true }) }); }
async function confirm(page: Page, action: () => Promise<unknown>): Promise<void> { page.once('dialog', dialog => dialog.accept()); await action(); }
async function saved(page: Page, message: string): Promise<void> { await expect(page.getByRole('status').filter({ hasText: message })).toBeVisible(); }

test('PROJECT-01/03/04/05/06: create, edit, search, archive and restore a real project', async ({ page }) => {
  await login(page); await page.goto('/projects'); await expect(page.getByRole('link', { name: 'Private Project', exact: true })).toHaveCount(0);
  await page.getByRole('button', { name: 'Create project', exact: true }).click(); const dialog = page.getByRole('dialog', { name: 'Create project', exact: true });
  await dialog.getByLabel('Name', { exact: true }).fill('V3 project Zürich & reproducibility'); await dialog.getByLabel('Description', { exact: true }).fill('First description <plain text>');
  await dialog.getByLabel('System', { exact: true }).selectOption({ label: 'Fixture System' }); await expect(dialog.getByLabel('Owner', { exact: true })).toHaveCount(0);
  const created = page.waitForResponse(response => new URL(response.url()).pathname === '/api/ui/projects' && response.request().method() === 'POST');
  await dialog.getByRole('button', { name: 'Create', exact: true }).click(); expect((await created).status()).toBe(201);
  await expect(page).toHaveURL(/\/projects\/[1-9][0-9]*$/); const id = Number(new URL(page.url()).pathname.split('/').at(-1));
  await expect(page.getByRole('heading', { name: 'V3 project Zürich & reproducibility', exact: true })).toBeVisible();
  await card(page, 'Project details').getByLabel('Name', { exact: true }).fill('Edited project – Zürich'); await card(page, 'Project details').getByLabel('Description', { exact: true }).fill('Edited & persisted');
  await page.getByRole('button', { name: 'Save project', exact: true }).click(); await expect(page.getByRole('status').filter({ hasText: 'Project saved' })).toBeVisible();
  expect(await data(page, `/projects/${id}`)).toMatchObject({ name: 'Edited project – Zürich', description: 'Edited & persisted', ownerId: 3 });
  await confirm(page, () => page.getByRole('button', { name: 'Archive project', exact: true }).click()); await expect(page.getByRole('button', { name: 'Restore project', exact: true })).toBeVisible();
  await page.goto('/projects');
  const archivedView = page.waitForResponse(response => {
    const url = new URL(response.url());
    return url.pathname === '/api/ui/projects' && response.request().method() === 'GET' && url.searchParams.get('archived') === 'true' && url.searchParams.get('search') === '';
  });
  await page.getByLabel('State', { exact: true }).selectOption('true'); expect((await archivedView).status()).toBe(200);
  const projectLink = page.getByRole('link', { name: 'Edited project – Zürich', exact: true });
  const loading = page.getByRole('status').filter({ hasText: /Loading/ });
  await expect(projectLink).toBeVisible(); await expect(loading).toBeHidden();
  let releaseSearch!: () => void; let searchStarted!: (request: import('@playwright/test').Request) => void; let held = false;
  let searched: { status: number; body: Buffer } | undefined;
  const gate = new Promise<void>(resolve => { releaseSearch = resolve; });
  const searching = new Promise<import('@playwright/test').Request>(resolve => { searchStarted = resolve; });
  const pattern = '**/api/ui/projects?**';
  await page.route(pattern, async route => {
    const url = new URL(route.request().url());
    if (!held && route.request().method() === 'GET' && url.searchParams.get('archived') === 'true' && url.searchParams.get('search') === 'Edited project') {
      held = true; searchStarted(route.request()); await gate;
      // Capture the real response before delivery so assertions do not depend
      // on Chromium retaining a continued request's CDP response body.
      const response = await route.fetch({ maxRedirects: 0, maxRetries: 0 });
      searched = { status: response.status(), body: await response.body() };
      await route.fulfill({ response, body: searched.body });
      return;
    }
    await route.continue();
  });
  try {
    const before = await projectLink.boundingBox(); expect(before).not.toBeNull();
    await page.getByLabel('Search projects', { exact: true }).fill('Edited project');
    const request = await searching; await expect(loading).toBeVisible();
    const during = await projectLink.boundingBox(); expect(during).not.toBeNull();
    for (const key of ['x', 'y', 'width', 'height'] as const) {
      expect(during![key], `Loading a search must preserve the project link's ${key}`).toBeCloseTo(before![key], 3);
    }
    await expect(page.locator('table')).toHaveAttribute('aria-busy', 'true');
    const filtered = page.waitForResponse(response => response.request() === request); releaseSearch();
    const response = await filtered; expect(response.status()).toBe(200);
    expect(searched?.status).toBe(200);
    const result = JSON.parse(searched!.body.toString('utf8'));
    expect(result.data.items).toEqual([expect.objectContaining({ id, name: 'Edited project – Zürich', archived: true })]);
    await expect(loading).toBeHidden(); await expect(page.locator('table')).toHaveAttribute('aria-busy', 'false');
    await expect(projectLink).toHaveAttribute('href', `/projects/${id}`); await expect(page.locator('tbody tr')).toHaveCount(1);
    await projectLink.click(); await expect(page).toHaveURL(new RegExp(`/projects/${id}$`));
    await expect(page.getByRole('heading', { name: /^Edited project – Zürich\s+Archived$/ })).toBeVisible();
  } finally {
    releaseSearch();
    if (!page.isClosed()) await page.unroute(pattern).catch(error => { if (!page.isClosed()) throw error; });
  }
  await confirm(page, () => page.getByRole('button', { name: 'Restore project', exact: true }).click());
  await expect(page.getByRole('button', { name: 'Archive project', exact: true })).toBeVisible();
  expect((await data(page, `/projects/${id}`)).archived).toBe(false); await fitsViewport(page);
});

test('PROJECT-07 and authorization: membership changes immediately affect an existing user session', async ({ page, browser, baseURL }) => {
  await login(page); await page.goto('/projects/1');
  const other = await browser.newContext({ baseURL }); const otherPage = await other.newPage();
  try {
    await login(otherPage, 'outsider'); expect((await otherPage.request.get('/api/ui/projects/1')).status()).toBe(403);
    await page.getByLabel('Add a member', { exact: true }).selectOption({ label: 'Outsider Fixture (outsider)' }); await page.getByRole('button', { name: 'Add member', exact: true }).click();
    await saved(page, 'Member added.'); await expect(page.getByRole('button', { name: 'Remove outsider', exact: true })).toBeVisible(); expect((await otherPage.request.get('/api/ui/projects/1')).ok()).toBeTruthy();
    await otherPage.goto('/projects/1'); await expect(otherPage.getByRole('heading', { name: 'Fixture Project', exact: true })).toBeVisible(); await expect(otherPage.getByRole('button', { name: 'Archive project', exact: true })).toHaveCount(0);
    await confirm(page, () => page.getByRole('button', { name: 'Remove outsider', exact: true }).click()); await saved(page, 'Member removed.'); await expect(page.getByRole('button', { name: 'Remove outsider', exact: true })).toHaveCount(0);
    expect((await otherPage.request.get('/api/ui/jobs/1/cdl')).status()).toBe(403); expect((await otherPage.request.get('/api/ui/jobs/5/download')).status()).toBe(403);
    expect((await data(page, '/projects/1/members')).items.filter((member: {id: number}) => member.id === 3)).toHaveLength(1);
  } finally { await other.close(); }
});

test('EXPERIMENT-01/02/03/05/06: copy all stored input types and schedule a new evaluation through the real form', async ({ page }) => {
  await login(page); await page.goto('/experiments/1'); await page.getByRole('link', { name: 'Copy experiment', exact: true }).click();
  await expect(page.getByRole('heading', { name: 'Copy experiment', exact: true })).toBeVisible(); await expect(page.getByLabel('Name', { exact: true })).toHaveValue('Fixture Experiment (copy)');
  await page.getByLabel('Name', { exact: true }).fill('V3 copied experiment'); await page.getByLabel('Description', { exact: true }).fill('Preserved inputs – Zürich'); await page.getByLabel('Repeated runs', { exact: true }).fill('1'); await page.getByLabel('Version 1', { exact: true }).fill('fixture-stable');
  await expect(page.getByRole('button', { name: 'Create experiment', exact: true })).toBeEnabled();
  const created = page.waitForResponse(response => /\/api\/ui\/projects\/1\/experiments$/.test(response.url()) && response.request().method() === 'POST');
  await page.getByRole('button', { name: 'Create experiment', exact: true }).click(); expect((await created).status()).toBe(201);
  await expect(page).toHaveURL(/\/experiments\/[1-9][0-9]*$/); const id = Number(new URL(page.url()).pathname.split('/').at(-1));
  await expect(page.getByRole('heading', { name: 'V3 copied experiment', exact: true })).toBeVisible();
  const copy = await data(page, `/projects/1/experiment-options?copy=${id}`); const original = await data(page, '/projects/1/experiment-options?copy=1'); expect(copy.values).toMatchObject({ quantity: original.values.quantity, enabled: 'false', 'version-numberOfVersions': 1, 'version-version-0': 'fixture-stable' });
  await page.getByRole('button', { name: 'Run evaluation', exact: true }).click(); const dialog = page.getByRole('dialog', { name: 'Run evaluation', exact: true });
  await dialog.getByLabel('Name', { exact: true }).fill('V3 scheduled evaluation'); await dialog.getByLabel('Environment', { exact: true }).selectOption('system-local');
  const scheduled = page.waitForResponse(response => response.url().endsWith(`/api/ui/experiments/${id}/evaluations`) && response.request().method() === 'POST');
  await dialog.getByRole('button', { name: 'Run evaluation', exact: true }).click(); expect((await scheduled).status()).toBe(201);
  await expect(page).toHaveURL(/\/evaluations\/[1-9][0-9]*$/); const evaluationId = Number(new URL(page.url()).pathname.split('/').at(-1));
  await expect(page.getByRole('heading', { name: 'V3 scheduled evaluation', exact: true })).toBeVisible(); const jobs = await data(page, `/evaluations/${evaluationId}/jobs`);
  expect(jobs.total).toBeGreaterThan(0); expect(jobs.items.every((job: {status: number; environment: string}) => job.status === 0 && job.environment === 'system-local')).toBeTruthy(); await fitsViewport(page);
});

test('PROJECT-08 and EXPERIMENT-07: archive guards remain visible and completed evaluations can be restored', async ({ page }) => {
  await login(page); await page.goto('/projects/1'); const experiments = page.getByRole('table', { name: 'Experiments', exact: true });
  await confirm(page, () => experiments.getByRole('button', { name: 'Archive Fixture Experiment', exact: true }).click()); await expect(page.getByRole('alert')).toContainText(/active evaluations|Finish or abort/);
  await page.getByLabel('Experiment state', { exact: true }).selectOption('true'); await confirm(page, () => experiments.getByRole('button', { name: 'Restore Archived Experiment', exact: true }).click());
  await saved(page, 'Experiment restored.');
  await page.getByLabel('Experiment state', { exact: true }).selectOption('false'); await expect(experiments.getByRole('link', { name: 'Archived Experiment', exact: true })).toBeVisible();
  await page.goto('/experiments/1'); const evaluations = page.getByRole('table', { name: 'Evaluations', exact: true });
  await confirm(page, () => evaluations.getByRole('button', { name: 'Archive Complete Evaluation', exact: true }).click()); await expect(evaluations.getByRole('link', { name: 'Complete Evaluation', exact: true })).toHaveCount(0);
  await page.getByLabel('Evaluation state', { exact: true }).selectOption('true'); await confirm(page, () => evaluations.getByRole('button', { name: 'Restore Complete Evaluation', exact: true }).click());
  await saved(page, 'Evaluation restored.');
  expect((await data(page, '/evaluations/2')).archived).toBe(false);
});

test('EVALUATION-03/05/06/07/09: save metadata, star, reschedule, abort and download completed archives', async ({ page }) => {
  await login(page); await page.goto('/evaluations/1');
  await card(page, 'Evaluation details').getByLabel('Name', { exact: true }).fill('V3 edited evaluation'); await card(page, 'Evaluation details').getByLabel('Description', { exact: true }).fill('Current result state');
  await page.getByRole('button', { name: 'Save evaluation', exact: true }).click(); await expect(page.getByRole('status').filter({ hasText: 'Evaluation saved' })).toBeVisible();
  await page.getByRole('button', { name: 'Star evaluation', exact: false }).click(); await saved(page, 'Evaluation starred.'); expect((await data(page, '/evaluations/1')).starred).toBe(true);
  await confirm(page, () => page.getByRole('button', { name: 'Reschedule failed / aborted', exact: true }).click());
  await saved(page, 'Jobs rescheduled.');
  let jobs = await data(page, '/evaluations/1/jobs?pageSize=100'); expect(jobs.items.find((job: {id: number}) => job.id === 4)).toMatchObject({ status: 0, currentPhase: null, progress: 0 }); expect(jobs.items.find((job: {id: number}) => job.id === 5).status).toBe(3);
  await confirm(page, () => page.getByRole('button', { name: 'Abort jobs', exact: true }).click()); await saved(page, 'Jobs aborted.'); jobs = await data(page, '/evaluations/1/jobs?pageSize=100'); expect(jobs.items.filter((job: {status: number}) => job.status === -1)).toHaveLength(5); expect(jobs.items.find((job: {id: number}) => job.id === 5).status).toBe(3);
  await page.goto('/evaluations/2'); const archive = page.getByRole('link', { name: 'Download evaluation ZIP', exact: true }); await expect(archive).toBeVisible();
  const response = await page.request.get(await archive.getAttribute('href') || ''); expect(response.ok()).toBeTruthy(); expect(response.headers()['content-type']).toContain('zip'); expect((await response.body()).subarray(0, 2).toString()).toBe('PK'); await fitsViewport(page);
});

test('JOB-03/04/05/07/08/09/10/11: safe colored logs, CDL, job navigation and rescheduling retain exact outcomes', async ({ page }) => {
  await login(page); await page.goto('/jobs/5');
  await expect(page.getByRole('link', { name: 'Download result ZIP', exact: true })).toHaveAttribute('href', '/api/ui/jobs/5/download');
  await expect(page.getByRole('link', { name: 'Previous job', exact: false })).toHaveAttribute('href', '/jobs/4'); await expect(page.getByRole('link', { name: 'Next job', exact: false })).toHaveAttribute('href', '/jobs/6');
  const log = page.getByRole('region', { name: 'Job log', exact: true }).or(page.locator('pre.log-viewer')); await expect(log).toContainText('Completed fixture step'); await expect(log).toContainText('Literal <script>fixture</script>'); await expect(log.locator('script')).toHaveCount(0);
  await expect(log.locator('span').filter({ hasText: 'Completed fixture step' })).toHaveCSS('color', 'rgb(113, 198, 147)');
  await page.getByLabel('Automatically update log', { exact: true }).uncheck(); await page.getByLabel('Follow new log output', { exact: true }).uncheck();
  const refreshed = page.waitForResponse(response => /\/api\/ui\/jobs\/5\/log\?/.test(response.url())); await page.getByRole('button', { name: 'Refresh log', exact: true }).click(); expect((await refreshed).ok()).toBeTruthy();
  await page.getByLabel('Description', { exact: true }).fill('Edited job – Zürich'); await page.getByRole('button', { name: 'Save description', exact: true }).click(); await saved(page, 'Job description saved.'); expect((await data(page, '/jobs/5')).description).toBe('Edited job – Zürich');
  await page.getByRole('button', { name: 'View CDL', exact: true }).click(); const dialog = page.getByRole('dialog', { name: 'Chronos Description Language', exact: true }); await expect(dialog.locator('code')).toContainText('<?xml'); await dialog.locator('.dialog-footer').getByRole('button', { name: 'Close', exact: true }).click();
  await confirm(page, () => page.getByRole('button', { name: 'Reschedule', exact: true }).click()); await saved(page, 'Job rescheduled.'); expect(await data(page, '/jobs/5')).toMatchObject({ status: 0, currentPhase: null, progress: 0 });
  await expect(card(page, 'Recent activity')).toContainText('Job status changed'); await fitsViewport(page);
});

test('EXTENSION-01 and PARAM-17: data pages render local charts, false/zero metrics and escaped text for project members', async ({ page }) => {
  await login(page, 'member'); await page.goto('/projects/1'); const extensions = card(page, 'System extensions');
  await extensions.getByRole('link', { name: 'Fixture extension overview', exact: true }).click(); await expect(page.getByRole('heading', { name: 'Fixture extension overview', exact: true })).toBeVisible();
  await expect(page.locator('main')).toContainText('Zürich & XML <value> stays plain text.'); await expect(page.locator('main')).toContainText('Enabled'); await expect(page.locator('main')).toContainText('false'); await expect(page.getByRole('table')).toContainText('Synthetic');
  await expect(page.locator('app-result-chart canvas')).toHaveCount(1); await expect(page.getByRole('button', { name: 'Download PNG', exact: true })).toBeEnabled();
  expect((await page.request.get('/api/ui/systems/1/pages/private')).status()).toBe(403); await page.goto('/extensions/systems/1/pages/live'); await expect(page.locator('main')).toContainText('4'); await fitsViewport(page);
});

test('EXTENSION-01 presets: project members save/load/update/delete rich named parameter values without sharing across projects', async ({ page }) => {
  await login(page); await page.goto('/projects/1/experiments/new?copy=1'); await page.getByLabel('Version 1', { exact: true }).fill('fixture-stable'); const presets = card(page, 'Named parameter presets');
  await expect(page.getByRole('button', { name: 'Create experiment', exact: true })).toBeEnabled(); await presets.getByLabel('Preset name', { exact: true }).fill('Zürich zero & false');
  await presets.getByRole('button', { name: 'Save as new preset', exact: true }).click(); await expect(presets.getByRole('status')).toContainText('Parameter preset saved');
  let records = await data(page, '/projects/1/parameter-presets'); expect(records.items).toHaveLength(1); const saved = records.items[0]; expect(saved.name).toBe('Zürich zero & false'); expect(Object.keys(saved.values).length).toBeGreaterThan(0);
  await confirm(page, () => presets.getByRole('button', { name: 'Load preset', exact: true }).click()); await expect(presets.getByRole('status')).toContainText('Preset loaded');
  await presets.getByLabel('Preset name', { exact: true }).fill('Updated preset'); await confirm(page, () => presets.getByRole('button', { name: 'Update selected preset', exact: true }).click()); await expect(presets.getByRole('status')).toContainText('Parameter preset saved');
  records = await data(page, '/projects/1/parameter-presets'); expect(records.items[0]).toMatchObject({ id: saved.id, name: 'Updated preset', values: saved.values });
  expect((await page.request.get('/api/ui/projects/2/parameter-presets')).status()).toBe(403);
  await confirm(page, () => presets.getByRole('button', { name: 'Delete preset', exact: true }).click()); await expect(presets.getByRole('status')).toContainText('Parameter preset deleted'); expect((await data(page, '/projects/1/parameter-presets')).items).toHaveLength(0);
});
