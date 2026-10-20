import { observeLive } from './live-helper';
import { test, expect, type Page, type Locator } from './fixture';

const browserErrors = new WeakMap<Page, string[]>();
test.afterEach(async ({ page }) => { expect(browserErrors.get(page) ?? [], 'The UI must not produce runtime errors.').toEqual([]); });

// Reset the isolated v3 fixture stack before each case. The runner owns reset
// coordination; these scenarios never select a target from arbitrary input.
test.beforeEach(async ({ page, baseURL }) => {
  const errors: string[] = []; browserErrors.set(page, errors);
  page.on('pageerror', error => errors.push(error.message));
  page.on('console', message => { if (message.type() === 'error' && /^ERROR\b/.test(message.text())) errors.push(message.text().split('\n')[0]); });
  const target = new URL(baseURL ?? '');
  if (target.protocol !== 'http:' || target.hostname !== '127.0.0.1' || !['4200', '8089'].includes(target.port)) throw new Error('The v3 browser suite requires the fixed loopback fixture.');
  const metadata = await page.request.get('/api/ui/meta');
  expect(metadata.ok()).toBeTruthy(); expect((await metadata.json()).data.api).toBe('ui');
  await page.route('**/*', route => {
    const url = new URL(route.request().url());
    return url.protocol === 'data:' || url.hostname === '127.0.0.1' && ['4200', '8089'].includes(url.port) ? route.continue() : route.abort('blockedbyclient');
  });
});

async function login(page: Page, username = 'admin', password = 'Chronos-test-2026!'): Promise<void> {
  await page.goto('/login'); await page.getByLabel('Username', { exact: true }).fill(username); await page.getByLabel('Password', { exact: true }).fill(password);
  await page.getByRole('button', { name: 'Sign in', exact: true }).click(); await expect(page.locator('.app-wrapper')).toBeVisible();
}
function card(page: Page, heading: string): Locator { return page.locator('section.card, section.node-health').filter({ has: page.getByRole('heading', { name: heading, exact: true }) }); }
async function fitsViewport(page: Page): Promise<void> {
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1)).toBeTruthy();
}
async function userRecord(page: Page, id: number): Promise<Record<string, unknown>> { const response = await page.request.get(`/api/ui/users/${id}`); expect(response.ok()).toBeTruthy(); return (await response.json()).data; }

test('ADMIN-01/02, PROFILE-03/04/05: create/edit an account, change its role and reset credentials with persisted outcomes', async ({ page, context }) => {
  await login(page, 'superadmin'); await page.goto('/admin');
  const accounts = card(page, 'Users'); const search = accounts.getByRole('searchbox', { name: 'Search users', exact: true });
  expect(await search.evaluate(element => !!element.closest('.card-header')), 'Account search belongs in the Users header').toBe(true);
  await search.fill('owner');
  const filtered = page.waitForResponse(response => new URL(response.url()).pathname === '/api/ui/users' && new URL(response.url()).searchParams.get('search') === 'owner');
  await search.press('Enter'); expect((await filtered).ok()).toBeTruthy();
  await expect(accounts.locator('tbody tr')).toHaveCount(1); await expect(accounts.getByRole('rowheader', { name: 'owner · owner@example.test', exact: true })).toBeVisible();
  await search.fill('');
  const cleared = page.waitForResponse(response => new URL(response.url()).pathname === '/api/ui/users' && new URL(response.url()).searchParams.get('search') === '');
  await accounts.getByRole('button', { name: 'Search users', exact: true }).click(); expect((await cleared).ok()).toBeTruthy();
  await expect(accounts.locator('tbody tr')).not.toHaveCount(1);
  await page.getByRole('button', { name: 'Create user', exact: true }).click();
  const dialog = page.getByRole('dialog', { name: 'Create user' });
  await dialog.getByLabel('Username', { exact: true }).fill('v3-browser-user'); await dialog.getByLabel('First name').fill('V3'); await dialog.getByLabel('Last name').fill('Browser'); await dialog.getByLabel('Email').fill('browser@example.test');
  await dialog.getByLabel('Password', { exact: true }).fill('Chronos-test-2026!'); await dialog.getByLabel('Confirm password').fill('Different-password-2026!');
  await dialog.getByRole('button', { name: 'Create user', exact: true }).click(); await expect(dialog.getByRole('alert')).toContainText('passwords do not match');
  await dialog.getByLabel('Confirm password').fill('Chronos-test-2026!');
  const response = page.waitForResponse(value => value.url().endsWith('/api/ui/users') && value.request().method() === 'POST');
  await dialog.getByRole('button', { name: 'Create user', exact: true }).click(); expect((await response).status()).toBe(201);
  await expect(dialog).not.toBeVisible(); const edit = card(page, 'Users').getByRole('link', { name: 'Edit v3-browser-user', exact: true });
  await expect(edit).toHaveAttribute('href', /^\/admin\/users\/[1-9]\d*$/); const href = (await edit.getAttribute('href'))!; const id = Number(href.split('/').pop());
  expect(await userRecord(page, id)).toMatchObject({ username: 'v3-browser-user', firstName: 'V3', lastName: 'Browser', email: 'browser@example.test' });
  await edit.click(); await expect(page).toHaveURL(new URL(href, page.url()).href);
  await page.getByLabel('First name').fill('Edited Zürich'); await page.getByLabel('Role', { exact: true }).selectOption({ label: 'Administrator' }); await page.getByRole('button', { name: 'Save account' }).click();
  await expect(page.getByRole('status').filter({ hasText: 'account was updated' })).toBeVisible(); expect(await userRecord(page, id)).toMatchObject({ firstName: 'Edited Zürich', role: 1 });
  await page.getByLabel('New password', { exact: true }).fill('Reset-password-2026!'); await page.getByLabel('Confirm password', { exact: true }).fill('Reset-password-2026!'); await page.getByRole('button', { name: 'Reset password', exact: true }).click();
  await expect(page.getByRole('status').filter({ hasText: 'password was reset' })).toBeVisible(); await expect(page.getByLabel('New password', { exact: true })).toHaveValue('');
  const target = await context.browser()!.newContext({ baseURL: test.info().project.use.baseURL as string }); const targetPage = await target.newPage();
  try { await login(targetPage, 'v3-browser-user', 'Reset-password-2026!'); await targetPage.goto('/admin'); await expect(targetPage.getByRole('heading', { name: 'Administration', exact: true })).toBeVisible(); }
  finally { await target.close(); }
  await page.getByLabel('Account enabled', { exact: true }).uncheck(); await page.getByLabel('Account activated', { exact: true }).uncheck(); await page.getByRole('button', { name: 'Save account' }).click();
  await expect(page.getByRole('status').filter({ hasText: 'account was updated' })).toBeVisible(); expect(await userRecord(page, id)).toMatchObject({ enabled: false, activated: false });
  await page.goto('/admin'); await page.getByLabel('Accounts', { exact: true }).selectOption('false'); await expect(card(page, 'Users')).toContainText('v3-browser-user');
  const disabledRow = card(page, 'Users').getByRole('row').filter({ has: page.getByRole('rowheader', { name: 'v3-browser-user · browser@example.test', exact: true }) });
  await expect(disabledRow.locator('[title="Disabled"]')).toBeVisible(); await expect(disabledRow.locator('[title="Disabled"]')).toHaveText('Disabled'); await expect(disabledRow.locator('[title="Disabled"] svg[aria-hidden="true"]')).toHaveCount(1);
  await fitsViewport(page);
});

test('ADMIN-01, PROFILE-05, SHELL-01: ordinary administrators have identity controls and no editable superadmin role selector', async ({ page }) => {
  await login(page); await page.goto('/admin'); await expect(card(page, 'Users')).not.toContainText('disabled');
  await page.getByLabel('Accounts', { exact: true }).selectOption('all'); await expect(card(page, 'Users')).toContainText('disabled');
  const superadmin = card(page, 'Users').getByRole('row').filter({ has: page.getByRole('rowheader', { name: /^superadmin · [^ ]+$/ }) });
  await expect(superadmin.getByRole('link', { name: /Edit/ })).toHaveCount(0); await page.goto('/admin/users/4'); await expect(page.getByLabel('First name')).toHaveValue('Member');
  await expect(page.getByLabel('Role', { exact: true })).toHaveCount(0); await expect(page.getByRole('heading', { name: 'Application maintenance' })).toHaveCount(0); await fitsViewport(page);
});

test('ADMIN-03, AUTH-04, SHELL-04: soft switch identifies the target and returns to the original administrator', async ({ page }) => {
  await login(page); await page.goto('/admin'); await page.getByRole('button', { name: 'Switch to member', exact: true }).click();
  const dialog = page.getByRole('dialog', { name: 'Switch account' }); await expect(dialog).toContainText('member'); await dialog.getByRole('button', { name: 'Cancel', exact: true }).click(); await expect(dialog).not.toBeVisible();
  await page.getByRole('button', { name: 'Switch to member', exact: true }).click(); await dialog.getByRole('button', { name: 'Switch account', exact: true }).click();
  await expect(page.locator('.impersonation-banner')).toContainText('as member'); await expect(page.request.get('/api/ui/admin/settings').then(value => value.status())).resolves.toBe(403);
  await page.locator('.impersonation-banner').getByRole('button', { name: 'Return to administrator' }).click(); await expect(page.locator('.impersonation-banner')).toHaveCount(0);
  expect((await (await page.request.get('/api/ui/session')).json()).data.user.username).toBe('admin');
});

test('ADMIN-04: permanent account switch ends the original administrator session', async ({ page }) => {
  await login(page); await page.goto('/admin'); await page.getByRole('button', { name: 'Switch to member', exact: true }).click();
  const dialog = page.getByRole('dialog', { name: 'Switch account' }); await dialog.getByLabel('End my administrator session permanently').check(); await dialog.getByRole('button', { name: 'Switch account', exact: true }).click();
  await expect(dialog).not.toBeVisible(); await expect(page.locator('.account-menu summary')).toContainText('Member Fixture');
  await expect(page.locator('.impersonation-banner')).toHaveCount(0); const session = (await (await page.request.get('/api/ui/session')).json()).data; expect(session.user.username).toBe('member'); expect(session.impersonation.active).toBe(false);
  await page.locator('.account-menu summary').click(); await page.getByRole('button', { name: 'Sign out', exact: true }).click(); await expect(page.getByRole('button', { name: 'Sign in', exact: true })).toBeVisible();
  expect((await (await page.request.get('/api/ui/session')).json()).data.user).toBeNull();
});

test('ADMIN-05/07/12: settings preserve zero/empty values, secrets stay redacted and storage/system information is visible', async ({ page }) => {
  await login(page); await page.goto('/admin'); const other = card(page, 'Other settings');
  await other.getByLabel('Rows Per Page').fill('10'); await other.getByLabel('Uploaded Data Hostname').fill(''); await other.getByRole('button', { name: 'Save Other settings' }).click(); await expect(page.getByRole('status').filter({ hasText: 'Other settings saved' })).toBeVisible();
  const ftp = card(page, 'Ftp settings'); await ftp.getByLabel('Use Ftp Upload For Local Clients').fill('0'); await ftp.getByLabel('Ftp Password', { exact: true }).fill('fixture-secret-not-for-response'); await ftp.getByRole('button', { name: 'Save Ftp settings' }).click();
  await expect(page.getByRole('status').filter({ hasText: 'Ftp settings saved' })).toBeVisible(); await page.reload(); await expect(other.getByLabel('Rows Per Page')).toHaveValue('10'); await expect(other.getByLabel('Uploaded Data Hostname')).toHaveValue(''); await expect(ftp.getByLabel('Use Ftp Upload For Local Clients')).toHaveValue('0'); await expect(ftp.getByLabel('Ftp Password', { exact: true })).toHaveValue('');
  const response = await page.request.get('/api/ui/admin/settings'); const raw = await response.text(); expect(raw).not.toContain('fixture-secret-not-for-response'); expect(raw).toContain('"configured":true');
  const storage = card(page, 'Data storage'); await expect(storage).toContainText(/Mounted|Not mounted|Unknown/); await expect(storage).toContainText(/\d{2}\.\d{2}\.\d{4} \d{2}:\d{2}:\d{2}/);
  await expect(card(page, 'Systems')).toContainText('Fixture System'); await expect(card(page, 'Systems')).toContainText('Private System'); await fitsViewport(page);
});

test('ADMIN-06: add an environment once, reject duplicates, confirm/cancel removal and persist deletion', async ({ page }) => {
  await login(page);
  let releaseStorage!: () => void, storageStarted!: () => void;
  const storageGate = new Promise<void>(resolve => { releaseStorage = resolve; });
  const storagePending = new Promise<void>(resolve => { storageStarted = resolve; });
  let additions = 0;
  page.on('request', request => { if (request.method() === 'POST' && new URL(request.url()).pathname === '/api/ui/admin/environments') additions++; });
  await page.route('**/api/ui/admin/storage', async route => { storageStarted(); await storageGate; await route.continue(); });
  const environments = card(page, 'CEM environments'), name = environments.getByLabel('New environment name');
  try {
    await page.goto('/admin'); await storagePending;
    await expect(name).toBeDisabled(); await expect(name).toHaveValue('');
    await expect(environments.getByRole('button', { name: 'Add environment' })).toBeDisabled();
    await expect(card(page, 'Data storage').getByRole('button', { name: 'Refresh', exact: true })).toBeDisabled();
    await environments.locator('form').evaluate(form => (form as HTMLFormElement).requestSubmit());
    expect(additions).toBe(0); await expect(name).toHaveValue('');
  } finally { releaseStorage(); }
  await expect(name).toBeEnabled();
  await name.fill('v3-browser-cem'); await environments.getByRole('button', { name: 'Add environment' }).click();
  const row = environments.getByRole('row').filter({ has: page.getByRole('rowheader', { name: 'v3-browser-cem', exact: true }) }); await expect(row).toHaveCount(1);
  expect(additions).toBe(1); await expect(page.getByRole('status').filter({ hasText: 'The CEM environment was added.' })).toBeVisible();
  await environments.getByLabel('New environment name').fill('v3-browser-cem'); await environments.getByRole('button', { name: 'Add environment' }).click(); await expect(page.getByRole('alert')).toContainText('already exists'); await expect(row).toHaveCount(1);
  await environments.getByLabel('New environment name').fill(''); page.once('dialog', dialog => dialog.dismiss()); await row.getByRole('button', { name: 'Remove v3-browser-cem', exact: true }).click(); await expect(row).toHaveCount(1);
  page.once('dialog', dialog => dialog.accept()); await row.getByRole('button', { name: 'Remove v3-browser-cem', exact: true }).click(); await expect(row).toHaveCount(0); await page.reload(); await expect(row).toHaveCount(0);
});

test('CEM-01/02/03/04/05: filter/search nodes, inspect exact timestamps, health thresholds and event links', async ({ page }) => {
  await login(page); await page.goto('/cem'); const table = page.getByRole('table', { name: 'CEM execution nodes' }); await expect(table.locator('tbody tr')).toHaveCount(3); await expect(table).not.toContainText('missing.fixture.test');
  await page.getByLabel('Include missing nodes').check(); await expect(table.locator('tbody tr')).toHaveCount(4); await expect(table).toContainText('missing.fixture.test');
  await page.getByRole('searchbox', { name: 'Search nodes', exact: true }).fill('warning.fixture.test'); await page.getByRole('button', { name: 'Search nodes', exact: true }).click(); await expect(table.locator('tbody tr')).toHaveCount(1); await expect(table).toContainText('Health warning'); await page.getByRole('link', { name: 'warning.fixture.test', exact: true }).click();
  await expect(page.getByRole('heading', { name: 'warning.fixture.test', exact: true })).toBeVisible(); await expect(card(page, 'Node information')).toContainText('Fixture Linux'); await expect(card(page, 'Node information')).toContainText('192.0.2.13'); await expect(card(page, 'Node information')).toContainText(/\d{2}\.\d{2}\.\d{4} \d{2}:\d{2}:\d{2}/);
  await expect(page.getByRole('progressbar', { name: 'CPU utilization' })).toHaveAttribute('aria-valuenow', '55'); await expect(page.locator('.node-resource').filter({ has: page.getByRole('progressbar', { name: 'CPU utilization' }) })).toHaveClass(/resource-danger/); await expect(page.getByRole('progressbar', { name: 'Memory utilization' })).toHaveAttribute('aria-valuenow', '95'); await expect(page.locator('.node-resource').filter({ has: page.getByRole('progressbar', { name: 'Memory utilization' }) })).toHaveClass(/resource-danger/);
  await expect(card(page, 'Health status')).toContainText('Fixture memory warning'); await expect(card(page, 'Health status')).toContainText('Second warning line'); await expect(card(page, 'Node event history')).toContainText('Fixture node warning');
  await card(page, 'Node event history').getByRole('link', { name: 'Node warning.fixture.test', exact: true }).click(); await expect(page.getByRole('heading', { name: 'warning.fixture.test', exact: true })).toBeVisible();
  await page.goto('/cem/nodes/fixture-busy'); await expect(card(page, 'Node information').getByRole('link', { name: 'Running job #2' })).toHaveAttribute('href', '/jobs/2');
  await page.goto('/cem/nodes/fixture-idle'); await expect(page.getByRole('progressbar', { name: 'CPU utilization' })).toHaveAttribute('aria-valuenow', '15'); await expect(page.getByRole('progressbar', { name: 'Memory utilization' })).toHaveAttribute('aria-valuenow', '25'); await expect(page.locator('.node-resource.resource-success')).toHaveCount(2); await expect(card(page, 'Health status')).toContainText('OK'); await fitsViewport(page);
});

test('ADMIN-08/09/10/11: review explicit maintenance resources and complete only disposable simulations', async ({ page }) => {
  await login(page, 'superadmin'); await page.goto('/admin'); const maintenance = card(page, 'Application maintenance');
  for (const action of ['Review update', 'Review rollback', 'Mount data storage', 'Reset demo data', 'Reset local systems']) {
    await maintenance.getByRole('button', { name: action, exact: true }).click(); const dialog = page.getByRole('dialog'); await expect(dialog).toBeVisible();
    const confirmation = await dialog.locator('label[for="operation-confirmation"] strong').innerText(); await expect(dialog.getByRole('button', { name: 'Run disposable simulation' })).toBeDisabled(); await dialog.getByLabel(/Enter .* to confirm/).fill(confirmation);
    await dialog.getByRole('button', { name: 'Run disposable simulation' }).click(); await expect(dialog).toContainText('Simulation completed'); await expect(dialog).toContainText('No application, storage or system data was changed'); await dialog.getByRole('button', { name: 'Close', exact: true }).click();
  }
  await fitsViewport(page);
});

test('RESULT-03/04/05/06/07/08: all five plots and a horizontal box render real canvases with accessible exact data', async ({ page }) => {
  await login(page, 'superadmin');
  const sourceResponse = await page.request.get('/api/ui/systems/1/result-configurations'); expect(sourceResponse.ok()).toBeTruthy(); const original = (await sourceResponse.json()).data;
  const session = (await (await page.request.get('/api/ui/session')).json()).data; const modified = structuredClone(original);
  const configuration = modified.elements['system-1']; const box = configuration.all.find((plot: {type: string}) => plot.type === 'box-plot'); expect(box).toBeTruthy();
  configuration.all.push({ ...box, id: 'browser-horizontal-box', name: 'Horizontal fixture box', orientation: 'horizontal' });
  // Arrange an additional supported definition through the real authenticated API.
  expect((await page.request.put('/api/ui/systems/1/result-configurations', { data: modified, headers: { 'X-CSRF-Token': session.csrfToken } })).ok()).toBeTruthy();
  try {
    await page.goto('/evaluations/2/results'); const explorer = page.locator('app-results-explorer');
    for (const name of ['Fixture Bar', 'Fixture Line', 'Fixture Multi', 'Fixture Pie', 'Fixture Box', 'Horizontal fixture box']) await expect(explorer.getByRole('heading', { name, exact: true }).first()).toBeVisible();
    await expect.poll(() => explorer.locator('canvas').count()).toBeGreaterThanOrEqual(6); await expect(explorer.getByRole('button', { name: 'Download PNG' }).first()).toBeEnabled();
    await expect.poll(() => explorer.locator('canvas').evaluateAll(canvases => canvases.every(element => { const canvas = element as HTMLCanvasElement; const context = canvas.getContext('2d'); return !!context && canvas.width > 0 && canvas.height > 0 && context.getImageData(0, 0, canvas.width, canvas.height).data.some((value, index) => index % 4 === 3 && value > 0); }))).toBeTruthy();
    const horizontal = explorer.locator('app-result-chart').filter({ has: page.getByRole('heading', { name: 'Horizontal fixture box', exact: true }) }); await horizontal.locator('summary').click(); await expect(horizontal.getByRole('table')).toBeVisible(); await expect(horizontal.getByRole('table')).toContainText('Median');
    await expect(explorer.getByRole('table').filter({ has: page.locator('caption', { hasText: 'matching jobs' }) })).toContainText('6 matching jobs');
    await explorer.getByLabel('Search results or parameters').fill('no-such-fixture-result'); await expect(explorer).toContainText('No jobs match your search'); await explorer.getByLabel('Search results or parameters').fill('');
    const png = page.waitForEvent('download'); await horizontal.getByRole('button', { name: 'Download PNG' }).click(); expect((await png).suggestedFilename()).toMatch(/\.png$/); await fitsViewport(page);
  } finally { expect((await page.request.put('/api/ui/systems/1/result-configurations', { data: original, headers: { 'X-CSRF-Token': session.csrfToken } })).ok()).toBeTruthy(); }
});

test('RESULT-09: active results receive pushed snapshots and manual refresh uses WebSocket', async ({ page }) => {
  const live = observeLive(page);
  const gets: string[] = [];
  page.on('request', request => { if (request.method() === 'GET' && new URL(request.url()).pathname === '/api/ui/evaluations/1/results') gets.push(request.url()); });
  await login(page, 'owner'); await page.goto('/evaluations/1/results');
  const explorer = page.locator('app-results-explorer'); await expect(explorer.getByLabel('Refresh automatically')).toBeChecked();
  const initial = await live.next('/evaluations/1/results'); expect(initial.data.totalJobs).toBe(6);
  const after = live.mark();
  await explorer.getByRole('button', { name: 'Refresh results', exact: true }).click();
  const refreshed = await live.next('/evaluations/1/results', () => true, after);
  expect(refreshed.id).not.toBe(initial.id); expect(refreshed.data).toEqual(initial.data);
  await expect(explorer).toContainText(/Updated \d{2}\.\d{2}\.\d{4} \d{2}:\d{2}:\d{2}/);
  expect(gets).toEqual([]);
});
