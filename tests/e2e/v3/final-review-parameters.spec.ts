import { test, expect, login, fitsViewport, type Page } from './fixture';

async function data(page: Page, path: string): Promise<any> {
  const response = await page.request.get(`/api/ui${path}`);
  expect(response.ok(), path).toBeTruthy();
  return (await response.json()).data;
}

test('PARAM-06: checkbox choices and legal prefixed sibling parameters remain submittable, persisted and copied', async ({ page }) => {
  await login(page);
  const groups = [{ id: 'main', title: 'Distinct parameter owners', depends: '', dependsValue: '', elements: [
    { id: 'mode-input', name: 'Mode', type: 'checkbox-selection', parameter: 'mode', options: 'fast,slow', default: 'fast' },
    { id: 'extra-input', name: 'Extra', type: 'string', parameter: 'mode-extra', default: 'on' },
    { id: 'flag-input', name: 'Enabled', type: 'boolean', parameter: 'mode-enabled', default: true },
  ] }];
  const session = await data(page, '/session');
  const configured = await page.request.put('/api/ui/systems/1/parameters', {
    data: { groups }, headers: { 'X-CSRF-Token': session.csrfToken, Origin: new URL(page.url()).origin },
  });
  expect(configured.status()).toBe(200);
  expect((await configured.json()).data.groups).toEqual(groups);
  await page.goto('/projects/1/experiments/new');
  const mode = page.getByRole('group', { name: 'Mode (mode)', exact: true });
  const extra = page.getByRole('group', { name: 'Extra (mode-extra)', exact: true }).getByLabel('Value', { exact: true });
  const enabled = page.getByRole('group', { name: 'Enabled (mode-enabled)', exact: true }).getByLabel('Value', { exact: true });
  await expect(mode.getByLabel('fast', { exact: true })).toBeChecked();
  await expect(extra).toHaveValue('on'); await expect(enabled).toHaveValue('true');
  await page.getByLabel('Name', { exact: true }).fill('Legal checkbox siblings');
  const create = page.getByRole('button', { name: 'Create experiment', exact: true });
  await expect(create).toBeEnabled();
  await expect(page.getByText('A saved selection is no longer available.', { exact: false })).toHaveCount(0);
  const created = page.waitForResponse(response => new URL(response.url()).pathname === '/api/ui/projects/1/experiments' && response.request().method() === 'POST');
  await create.click(); expect((await created).status()).toBe(201);
  await expect(page).toHaveURL(/\/experiments\/[1-9][0-9]*$/);
  const experimentId = Number(new URL(page.url()).pathname.split('/').at(-1));
  const saved = await data(page, `/experiments/${experimentId}/parameters`);
  expect(saved.groups).toEqual(groups);
  expect(saved.values).toMatchObject({ 'mode-fast': 'on', 'mode-slow': false, 'mode-extra': 'on', 'mode-enabled': true });
  await page.getByRole('button', { name: 'Run evaluation', exact: true }).click();
  const dialog = page.getByRole('dialog', { name: 'Run evaluation', exact: true });
  await expect(dialog.getByLabel('Environment', { exact: true })).toBeEnabled();
  await dialog.getByLabel('Environment', { exact: true }).selectOption('system-local');
  const scheduled = page.waitForResponse(response => new URL(response.url()).pathname === `/api/ui/experiments/${experimentId}/evaluations` && response.request().method() === 'POST');
  await dialog.getByRole('button', { name: 'Run evaluation', exact: true }).click();
  expect((await scheduled).status()).toBe(201); await expect(page).toHaveURL(/\/evaluations\/[1-9][0-9]*$/);
  const evaluationId = Number(new URL(page.url()).pathname.split('/').at(-1));
  const jobs = await data(page, `/evaluations/${evaluationId}/jobs?status=all&pageSize=100&sort=id`);
  expect(jobs.total).toBe(1);
  expect(jobs.items[0].configuration.parameters).toEqual({ mode: 'fast', 'mode-extra': 'on', 'mode-enabled': 'true', run: 0 });
  await page.goto(`/experiments/${experimentId}`);
  await page.getByRole('link', { name: 'Copy experiment', exact: true }).click();
  await expect(mode.getByLabel('fast', { exact: true })).toBeChecked();
  await expect(extra).toHaveValue('on'); await expect(enabled).toHaveValue('true'); await expect(create).toBeEnabled();
  await fitsViewport(page);
});

test('PARAM-11: decimal percentage intervals produce exact fractional jobs and retain copied inputs', async ({ page }) => {
  await login(page);
  const groups = [{ id: 'main', title: 'Decimal interval', depends: '', dependsValue: '', elements: [
    { id: 'base-input', name: 'Base', type: 'integer', parameter: 'base', default: 3 },
    { id: 'portion-input', name: 'Portion', type: 'percentage-interval', parameter: 'portion', dependency: 'base', 'parameter-type': 'float', 'default-start': 10, 'default-end': 30, 'default-step': 10 },
  ] }];
  const session = await data(page, '/session');
  const configured = await page.request.put('/api/ui/systems/1/parameters', {
    data: { groups }, headers: { 'X-CSRF-Token': session.csrfToken, Origin: new URL(page.url()).origin },
  });
  expect(configured.status()).toBe(200);
  await page.goto('/projects/1/experiments/new');
  const portion = page.getByRole('group', { name: 'Portion (portion)', exact: true });
  for (const [label, value] of [['Start (calculated)', '0.3'], ['End (calculated)', '0.9'], ['Step (calculated)', '0.3']]) {
    await expect(portion.getByLabel(label, { exact: true })).toHaveValue(value);
    await expect(portion.getByLabel(label, { exact: true })).toHaveJSProperty('readOnly', true);
  }
  await page.getByLabel('Name', { exact: true }).fill('Decimal percentage jobs');
  const create = page.getByRole('button', { name: 'Create experiment', exact: true });
  await expect(create).toBeEnabled();
  const created = page.waitForResponse(response => new URL(response.url()).pathname === '/api/ui/projects/1/experiments' && response.request().method() === 'POST');
  await create.click(); expect((await created).status()).toBe(201);
  await expect(page).toHaveURL(/\/experiments\/[1-9][0-9]*$/);
  const experimentId = Number(new URL(page.url()).pathname.split('/').at(-1));
  expect((await data(page, `/experiments/${experimentId}/parameters`)).values).toMatchObject({ base: 3, 'portion-percentage-start': 10, 'portion-percentage-end': 30, 'portion-percentage-step': 10 });
  await page.getByRole('button', { name: 'Run evaluation', exact: true }).click();
  const dialog = page.getByRole('dialog', { name: 'Run evaluation', exact: true });
  await expect(dialog.getByLabel('Environment', { exact: true })).toBeEnabled();
  await dialog.getByLabel('Environment', { exact: true }).selectOption('system-local');
  const scheduled = page.waitForResponse(response => new URL(response.url()).pathname === `/api/ui/experiments/${experimentId}/evaluations` && response.request().method() === 'POST');
  await dialog.getByRole('button', { name: 'Run evaluation', exact: true }).click();
  expect((await scheduled).status()).toBe(201); await expect(page).toHaveURL(/\/evaluations\/[1-9][0-9]*$/);
  const evaluationId = Number(new URL(page.url()).pathname.split('/').at(-1));
  const jobs = await data(page, `/evaluations/${evaluationId}/jobs?status=all&pageSize=100&sort=id`);
  expect(jobs.total).toBe(3);
  expect(jobs.items.map((job: any) => job.configuration.parameters)).toEqual([0.3, 0.6, 0.9].map(value => ({ base: 3, portion: value, run: 0 })));
  await page.goto(`/experiments/${experimentId}`);
  await page.getByRole('link', { name: 'Copy experiment', exact: true }).click();
  await expect(portion.getByLabel('Start percentage', { exact: true })).toHaveValue('10');
  await expect(portion.getByLabel('End percentage', { exact: true })).toHaveValue('30');
  await expect(portion.getByLabel('Step percentage', { exact: true })).toHaveValue('10');
  await expect(create).toBeEnabled(); await fitsViewport(page);
});

test('PARAM-05/06: choice labels, padded values and string-list whitespace survive creation, jobs and copying', async ({ page }) => {
  await login(page);
  const groups = [{ id: 'main', title: 'Lossless parameter values', depends: '', dependsValue: '', elements: [
    { id: 'engine-input', name: 'Engine', type: 'dropdown', parameter: 'engine', options: 'fast, slow', names: 'Fast label,Slow label', default: 'Slow label' },
    { id: 'storage-input', name: 'Storage', type: 'checkbox-selection', parameter: 'storage', options: 'mem, disk', default: ' disk' },
    { id: 'dataset-input', name: 'Dataset', type: 'string-list', parameter: 'dataset', default: 'alpha, beta' },
    { id: 'note-input', name: 'Note', type: 'string', parameter: 'note', default: '  padded value  ' },
  ] }];
  const session = await data(page, '/session');
  const configured = await page.request.put('/api/ui/systems/1/parameters', {
    data: { groups }, headers: { 'X-CSRF-Token': session.csrfToken, Origin: new URL(page.url()).origin },
  });
  expect(configured.status()).toBe(200);
  expect((await configured.json()).data.groups).toEqual(groups);
  await page.goto('/projects/1/experiments/new');
  const engine = page.getByRole('group', { name: 'Engine (engine)', exact: true }).getByLabel('Value', { exact: true });
  const storage = page.getByRole('group', { name: 'Storage (storage)', exact: true });
  const dataset = page.getByRole('group', { name: 'Dataset (dataset)', exact: true }).getByLabel('Values', { exact: true });
  const note = page.getByRole('group', { name: 'Note (note)', exact: true }).getByLabel('Value', { exact: true });
  await expect(engine).toHaveValue(' slow');
  await engine.selectOption({ label: 'Fast label' }); await engine.selectOption({ label: 'Slow label' });
  await expect(engine).toHaveValue(' slow');
  await expect(storage.getByLabel('disk', { exact: true })).toBeChecked();
  await expect(storage.getByLabel('mem', { exact: true })).not.toBeChecked();
  await expect(dataset).toHaveValue('alpha, beta'); await expect(note).toHaveValue('  padded value  ');
  await page.getByLabel('Name', { exact: true }).fill('Lossless choice and list values');
  const create = page.getByRole('button', { name: 'Create experiment', exact: true });
  await expect(create).toBeEnabled();
  const created = page.waitForResponse(response => new URL(response.url()).pathname === '/api/ui/projects/1/experiments' && response.request().method() === 'POST');
  await create.click(); expect((await created).status()).toBe(201);
  await expect(page).toHaveURL(/\/experiments\/[1-9][0-9]*$/);
  const experimentId = Number(new URL(page.url()).pathname.split('/').at(-1));
  const saved = await data(page, `/experiments/${experimentId}/parameters`);
  expect(saved.groups).toEqual(groups);
  expect(saved.values).toMatchObject({ engine: ' slow', 'storage-mem': false, 'storage- disk': 'on', dataset: 'alpha, beta', note: '  padded value  ' });
  await page.getByRole('button', { name: 'Run evaluation', exact: true }).click();
  const dialog = page.getByRole('dialog', { name: 'Run evaluation', exact: true });
  await expect(dialog.getByLabel('Environment', { exact: true })).toBeEnabled();
  await dialog.getByLabel('Environment', { exact: true }).selectOption('system-local');
  const scheduled = page.waitForResponse(response => new URL(response.url()).pathname === `/api/ui/experiments/${experimentId}/evaluations` && response.request().method() === 'POST');
  await dialog.getByRole('button', { name: 'Run evaluation', exact: true }).click();
  expect((await scheduled).status()).toBe(201); await expect(page).toHaveURL(/\/evaluations\/[1-9][0-9]*$/);
  const evaluationId = Number(new URL(page.url()).pathname.split('/').at(-1));
  const jobs = await data(page, `/evaluations/${evaluationId}/jobs?status=all&pageSize=100&sort=id`);
  expect(jobs.total).toBe(2);
  expect(jobs.items.map((job: any) => job.configuration.parameters)).toEqual(['alpha', ' beta'].map(value => ({ engine: ' slow', storage: ' disk', dataset: value, note: '  padded value  ', run: 0 })));
  let releaseOptions!: () => void, optionsStarted!: (request: import('@playwright/test').Request) => void, held = false;
  const optionsGate = new Promise<void>(resolve => { releaseOptions = resolve; });
  const optionsRequest = new Promise<import('@playwright/test').Request>(resolve => { optionsStarted = resolve; });
  const pattern = `**/api/ui/experiments/${experimentId}/run-options`;
  await page.route(pattern, async route => {
    if (!held && route.request().method() === 'GET') {
      held = true; optionsStarted(route.request()); await optionsGate;
    }
    await route.continue();
  });
  try {
    await page.goto(`/experiments/${experimentId}`);
    const request = await optionsRequest;
    const feedback = page.locator('main .app-content > ng-component > app-feedback').first();
    await expect(feedback.getByRole('status')).toHaveText('Loading…');
    const copy = page.getByRole('link', { name: 'Copy experiment', exact: true });
    await expect(copy).toBeVisible(); const before = await copy.boundingBox(); expect(before).not.toBeNull();
    const loaded = page.waitForResponse(response => response.request() === request); releaseOptions(); expect((await loaded).status()).toBe(200);
    await expect(feedback.getByRole('status')).toHaveCount(0);
    const after = await copy.boundingBox(); expect(after).not.toBeNull();
    for (const key of ['x', 'y', 'width', 'height'] as const) {
      expect(after![key], `Completing initial loading must preserve the Copy experiment link's ${key}`).toBeCloseTo(before![key], 3);
    }
    await copy.click(); await expect(page).toHaveURL(`/projects/1/experiments/new?copy=${experimentId}`);
  } finally {
    releaseOptions();
    if (!page.isClosed()) await page.unroute(pattern).catch(error => { if (!page.isClosed()) throw error; });
  }
  await expect(engine).toHaveValue(' slow'); await expect(storage.getByLabel('disk', { exact: true })).toBeChecked();
  await expect(storage.getByLabel('mem', { exact: true })).not.toBeChecked();
  await expect(dataset).toHaveValue('alpha, beta'); await expect(note).toHaveValue('  padded value  ');
  await expect(create).toBeEnabled(); await fitsViewport(page);
});
