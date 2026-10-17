import { test, expect, login, fitsViewport, type Page, type Locator } from './fixture';

async function data(page: Page, path: string): Promise<any> {
  const response = await page.request.get(`/api/v3${path}`); expect(response.ok(), path).toBeTruthy(); return (await response.json()).data;
}
async function mutate(page: Page, method: 'PATCH' | 'PUT', path: string, body: unknown): Promise<any> {
  const session = await data(page, '/session');
  const response = await page.request.fetch(`/api/v3${path}`, { method, data: body, headers: { 'X-CSRF-Token': session.csrfToken, Origin: new URL(page.url()).origin } });
  expect(response.ok(), `${method} ${path}`).toBeTruthy(); return (await response.json()).data;
}
function parameter(page: Page, name: string, key: string): Locator {
  const escape = (value: string) => value.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
  return page.getByRole('group', { name: new RegExp(`^${escape(name)} \\(${escape(key)}\\)$`) });
}
async function create(page: Page): Promise<number> {
  await expect(page.getByRole('button', { name: 'Create experiment', exact: true })).toBeEnabled();
  const response = page.waitForResponse(value => new URL(value.url()).pathname === '/api/v3/projects/1/experiments' && value.request().method() === 'POST');
  await page.getByRole('button', { name: 'Create experiment', exact: true }).click(); expect((await response).status()).toBe(201);
  // Read the visible destination and subsequently persisted API state. Chromium
  // may evict a completed fetch body after the application changes route.
  await expect(page).toHaveURL(/\/experiments\/[1-9][0-9]*$/); return Number(new URL(page.url()).pathname.split('/').at(-1));
}

test('PARAM-06/07/08/09/13/14/16, EXPERIMENT-09: native parameter edits, validation, hidden drafts and event links persist through creation and copy', async ({ page }) => {
  await login(page); await page.goto('/projects/1/experiments/new'); await page.getByLabel('Name', { exact: true }).fill('Native inputs Zürich'); await page.getByLabel('Version 1', { exact: true }).fill('main');
  const label = parameter(page, 'Label', 'label'); await label.getByLabel('Value', { exact: true }).fill('Zürich & <plain text>');
  const mode = parameter(page, 'Mode', 'mode').getByLabel('Value', { exact: true }); await mode.selectOption({ label: 'Advanced' }); await expect(parameter(page, 'Detail', 'detail')).toBeVisible();
  await parameter(page, 'Detail', 'detail').getByLabel('Value', { exact: true }).fill('Retained hidden Zürich'); await mode.selectOption({ label: 'Basic' }); await expect(parameter(page, 'Detail', 'detail')).toHaveCount(0);
  const choice = parameter(page, 'Choice', 'choice'); await choice.getByLabel('read', { exact: true }).uncheck(); await expect(page.getByRole('button', { name: 'Create experiment', exact: true })).toBeDisabled(); await expect(choice).toContainText('Select at least one'); await choice.getByLabel('write', { exact: true }).check();
  const interval = parameter(page, 'Size', 'size'); await interval.getByLabel('Step', { exact: true }).fill('-10'); await expect(page.getByRole('button', { name: 'Create experiment', exact: true })).toBeDisabled(); await interval.getByLabel('Step', { exact: true }).fill('5');
  const ratio = parameter(page, 'Ratio', 'left,right'); await ratio.getByLabel('Start ratio', { exact: true }).fill('10:80'); await expect(page.getByRole('button', { name: 'Create experiment', exact: true })).toBeDisabled(); await ratio.getByLabel('Start ratio', { exact: true }).fill('0%:100%'); await ratio.getByLabel('Step', { exact: true }).fill('50%');
  await parameter(page, 'Tags', 'tag').getByLabel('Values', { exact: true }).fill('alpha,Zürich beta');
  const id = await create(page), saved = await data(page, `/experiments/${id}/parameters`);
  expect(saved.values).toMatchObject({ label: 'Zürich & <plain text>', mode: 'basic', detail: 'Retained hidden Zürich', 'choice-read': false, 'choice-write': 'on', 'size-min': '10', 'size-max': '20', 'size-step': '5', 'left,right-start': '0%:100%', 'left,right-step': '50%', tag: 'alpha,Zürich beta' });
  const activity = page.locator('app-activity'); await expect(activity).toContainText('Experiment created'); await expect(activity).toContainText('Owner Fixture');
  const events = await data(page, `/experiments/${id}/events`), created = events.items.find((event: any) => event.title === 'Experiment created'); expect(created.resourceId).toBe(id); expect(created.resources).toEqual(expect.arrayContaining([expect.objectContaining({ type: 'project', id: 1 }), expect.objectContaining({ type: 'experiment', id })]));
  await activity.getByRole('link', { name: 'Fixture Project', exact: true }).click(); await expect(page).toHaveURL('/projects/1'); await page.goBack();
  await page.getByRole('link', { name: 'Copy experiment', exact: true }).click(); await expect(parameter(page, 'Label', 'label').getByLabel('Value', { exact: true })).toHaveValue('Zürich & <plain text>'); await expect(parameter(page, 'Choice', 'choice').getByLabel('read', { exact: true })).not.toBeChecked(); await expect(parameter(page, 'Choice', 'choice').getByLabel('write', { exact: true })).toBeChecked();
  await expect(parameter(page, 'Size', 'size').getByLabel('Step', { exact: true })).toHaveValue('5'); await expect(parameter(page, 'Ratio', 'left,right').getByLabel('Step', { exact: true })).toHaveValue('50%'); await expect(parameter(page, 'Tags', 'tag').getByLabel('Values', { exact: true })).toHaveValue('alpha,Zürich beta');
  await parameter(page, 'Mode', 'mode').getByLabel('Value', { exact: true }).selectOption('advanced'); await expect(parameter(page, 'Detail', 'detail').getByLabel('Value', { exact: true })).toHaveValue('Retained hidden Zürich'); await fitsViewport(page);
});

test('PARAM-10/11/12/15: linked percentage previews and dynamic versions produce exact server jobs with version overrides and sequential runs', async ({ page }) => {
  await login(page); await page.goto('/projects/1/experiments/new'); await page.getByLabel('Name', { exact: true }).fill('Linked parameters'); await page.getByLabel('Repeated runs', { exact: true }).fill('2'); await page.getByLabel('Run distribution', { exact: true }).selectOption('order');
  await parameter(page, 'Quantity', 'quantity').getByLabel('Value', { exact: true }).fill('80');
  const share = parameter(page, 'Share', 'share'); await share.getByLabel('Percentage', { exact: true }).fill('50'); await expect(share.getByLabel('Absolute value (calculated)', { exact: true })).toHaveValue('40'); await expect(share.getByLabel('Absolute value (calculated)', { exact: true })).toHaveJSProperty('readOnly', true);
  const portion = parameter(page, 'Portion', 'portion'); await portion.getByLabel('Start percentage', { exact: true }).fill('20'); await portion.getByLabel('End percentage', { exact: true }).fill('60'); await portion.getByLabel('Step percentage', { exact: true }).fill('20');
  for (const [name, expected] of [['Start (calculated)', '16'], ['End (calculated)', '48'], ['Step (calculated)', '16']] as const) { await expect(portion.getByLabel(name, { exact: true })).toHaveValue(expected); await expect(portion.getByLabel(name, { exact: true })).toHaveJSProperty('readOnly', true); }
  const dependent = parameter(page, 'Dependent Share', 'dependent'); await dependent.getByLabel('Percentage', { exact: true }).fill('50');
  for (const [name, expected] of [['Start (calculated)', '8'], ['End (calculated)', '24'], ['Step (calculated)', '8']] as const) await expect(dependent.getByLabel(name, { exact: true })).toHaveValue(expected);
  await expect(page.locator('.text-warning-emphasis').first()).toContainText('total');
  await parameter(page, 'Size', 'size').getByLabel('Maximum', { exact: true }).fill('10'); await parameter(page, 'Ratio', 'left,right').getByLabel('End ratio', { exact: true }).fill('0:100');
  const versions = parameter(page, 'Version', 'version'); await versions.getByLabel('Version 1', { exact: true }).fill('saved-first'); await versions.getByLabel('Number of versions', { exact: true }).fill('2'); await versions.getByLabel('Number of versions', { exact: true }).press('Tab'); await expect(page.getByRole('button', { name: 'Create experiment', exact: true })).toBeDisabled(); await versions.getByLabel('Version 2', { exact: true }).fill('saved-second');
  await versions.getByLabel('Number of versions', { exact: true }).fill('1'); await versions.getByLabel('Number of versions', { exact: true }).press('Tab'); await expect(versions.getByLabel('Version 2', { exact: true })).toHaveCount(0);
  await versions.getByLabel('Number of versions', { exact: true }).fill('2'); await versions.getByLabel('Number of versions', { exact: true }).press('Tab'); await expect(versions.getByLabel('Version 2', { exact: true })).toHaveValue('saved-second');
  const id = await create(page); await page.getByRole('button', { name: 'Run evaluation', exact: true }).click(); const dialog = page.getByRole('dialog', { name: 'Run evaluation', exact: true });
  await expect(dialog.getByLabel('Version 1', { exact: true })).toHaveValue('saved-first'); await expect(dialog.getByLabel('Version 2', { exact: true })).toHaveValue('saved-second'); await dialog.getByLabel('Version 1', { exact: true }).fill('run-first'); await dialog.getByLabel('Version 2', { exact: true }).fill('run-second'); await dialog.getByLabel('Environment', { exact: true }).selectOption('system-local');
  const scheduled = page.waitForResponse(response => new URL(response.url()).pathname === `/api/v3/experiments/${id}/evaluations` && response.request().method() === 'POST'); await dialog.getByRole('button', { name: 'Run evaluation', exact: true }).click(); const response = await scheduled; expect(response.status()).toBe(201); const evaluation = (await response.json()).data;
  const jobs = await data(page, `/evaluations/${evaluation.id}/jobs?status=all&pageSize=100&sort=id`); expect(jobs.total).toBe(12); // Three portions × two revisions × two repeats.
  const expected = ['run-first', 'run-second'].flatMap(version => [16, 32, 48].flatMap(portion => [0, 1].map(run => ({ version, portion, dependent: portion / 2, run }))));
  expect(jobs.items.map((job: any) => { const p = job.configuration.parameters; return { version: p.version, portion: p.portion, dependent: p.dependent, run: p.run }; })).toEqual(expected);
  expect(jobs.items.every((job: any) => String(job.configuration.parameters.share) === '40' && !Object.hasOwn(job.configuration.parameters, 'detail'))).toBe(true); await fitsViewport(page);
});

test('EVALUATION-08: an empty configuration selection explains recovery and the first available configuration can be chosen', async ({ page }) => {
  await login(page); await page.goto('/evaluations/1'); const document = await data(page, '/systems/1/result-configurations'); document.elements['system-recovery'] = { ...structuredClone(document.elements['system-1']), name: 'Recovery alternative' };
  await mutate(page, 'PUT', '/systems/1/result-configurations', document); await mutate(page, 'PATCH', '/experiments/1', { resultId: '' }); await page.reload();
  const explorer = page.locator('app-results-explorer'); await expect(explorer.getByLabel('Result configuration', { exact: true })).toHaveValue(''); await expect(explorer).toContainText('Choose a result configuration to display charts.'); await expect(explorer.locator('app-result-chart')).toHaveCount(0); await expect(explorer).toContainText('Raw job results');
  const recovery = explorer.getByRole('link', { name: 'Manage result configurations', exact: true }); await expect(recovery).toHaveAttribute('href', '/experiments/1?tab=results'); await recovery.click(); await expect(page.locator('app-experiment-results')).toBeVisible(); await page.goBack();
  const result = page.waitForResponse(response => new URL(response.url()).pathname === '/api/v3/evaluations/1/results' && new URL(response.url()).searchParams.get('configurationId') === 'system-1'); await explorer.getByLabel('Result configuration', { exact: true }).selectOption('system-1'); expect((await result).ok()).toBeTruthy(); await expect(explorer.getByLabel('Result configuration', { exact: true })).toHaveValue('system-1'); await expect(explorer.locator('app-result-chart')).toHaveCount(10); expect((await data(page, '/experiments/1')).resultId).toBe(''); await fitsViewport(page);
});

test('EVALUATION-02: admin visibility persists when switching evaluations and jobs while ordinary accounts cannot request all-user data', async ({ page }) => {
  await login(page, 'admin'); await page.goto('/status'); const visibility = page.getByLabel('Visibility', { exact: true }); await expect(visibility.locator('option:checked')).toHaveText('My evaluations'); await expect(page.getByRole('table', { name: 'Evaluation status', exact: true })).toContainText('No evaluations in this view.');
  const all = page.waitForResponse(response => new URL(response.url()).pathname === '/api/v3/evaluations' && new URL(response.url()).searchParams.get('all') === 'true'); await visibility.selectOption({ label: 'All evaluations' }); expect((await all).ok()).toBeTruthy(); await page.getByLabel('Status', { exact: true }).selectOption('all');
  const evaluations = page.getByRole('table', { name: 'Evaluation status', exact: true }); await expect(evaluations.getByRole('link', { name: 'Private Evaluation', exact: true })).toBeVisible();
  const jobs = page.waitForResponse(response => new URL(response.url()).pathname === '/api/v3/jobs' && new URL(response.url()).searchParams.get('all') === 'true' && new URL(response.url()).searchParams.get('status') === 'active'); await page.getByRole('button', { name: 'Jobs', exact: true }).click(); expect((await jobs).ok()).toBeTruthy(); await expect(visibility.locator('option:checked')).toHaveText('All jobs'); await expect(page.getByLabel('Status', { exact: true })).toHaveValue('active'); await page.getByLabel('Status', { exact: true }).selectOption('all');
  await page.getByRole('button', { name: 'Evaluations', exact: true }).click(); await expect(visibility.locator('option:checked')).toHaveText('All evaluations'); await expect(evaluations.getByRole('link', { name: 'Mixed Evaluation', exact: true })).toBeVisible(); await page.getByLabel('Status', { exact: true }).selectOption('all'); await expect(evaluations.getByRole('link', { name: 'Private Evaluation', exact: true })).toBeVisible(); await fitsViewport(page);
  const session = await data(page, '/session'); const logout = await page.request.delete('/api/v3/session', { headers: { 'X-CSRF-Token': session.csrfToken, Origin: new URL(page.url()).origin } }); expect(logout.ok()).toBeTruthy();
  await login(page); await page.goto('/status'); await expect(page.getByLabel('Visibility', { exact: true })).toHaveCount(0); expect((await page.request.get('/api/v3/evaluations?all=true')).status()).toBe(403);
});

test('DORMANT-02 and EXPERIMENT-01/06: the additional Randomize choice preserves every configuration and repeated run', async ({ page }) => {
  await login(page); await page.goto('/projects/1/experiments/new'); await page.getByLabel('Name', { exact: true }).fill('Randomized input invariants'); await page.getByLabel('Repeated runs', { exact: true }).fill('3'); await page.getByLabel('Run distribution', { exact: true }).selectOption({ label: 'Randomize configurations' }); await page.getByLabel('Version 1', { exact: true }).fill('main');
  const id = await create(page); await page.getByRole('button', { name: 'Run evaluation', exact: true }).click(); const dialog = page.getByRole('dialog', { name: 'Run evaluation', exact: true }); await dialog.getByLabel('Environment', { exact: true }).selectOption('system-local');
  const scheduled = page.waitForResponse(response => new URL(response.url()).pathname === `/api/v3/experiments/${id}/evaluations` && response.request().method() === 'POST'); await dialog.getByRole('button', { name: 'Run evaluation', exact: true }).click(); const response = await scheduled; expect(response.status()).toBe(201); const evaluation = (await response.json()).data;
  const jobs = await data(page, `/evaluations/${evaluation.id}/jobs?status=all&pageSize=100`); expect(jobs.total).toBe(12); // Two sizes × two complementary ratios × three repeats.
  const tuple = (size: number, left: number, right: number, run: number) => JSON.stringify([size, left, right, run]);
  const expected = [10, 20].flatMap(size => [[0, 100], [100, 0]].flatMap(([left, right]) => [0, 1, 2].map(run => tuple(size, left, right, run)))).sort();
  const actual = jobs.items.map((job: any) => { const p = job.configuration.parameters; expect(p.version).toBe('main'); expect(p.enabled).toBe('false'); return tuple(p.size, p.left, p.right, p.run); }).sort();
  expect(actual).toEqual(expected); await fitsViewport(page);
});
