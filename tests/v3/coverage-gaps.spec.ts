import { execFileSync } from 'node:child_process';
import path from 'node:path';
import { test, expect, login, fitsViewport, type Page, type Locator } from './fixture';

function repositoryPolicy(action: 'enable' | 'restore'): void {
  execFileSync(path.resolve('scripts/test-stack-v3'), ['repository-policy', action], { timeout: 15_000, stdio: 'pipe' });
}

async function data(page: Page, path: string): Promise<any> {
  const response = await page.request.get(`/api/v3${path}`);
  expect(response.ok(), `GET ${path}`).toBeTruthy();
  return (await response.json()).data;
}
function card(page: Page, heading: string): Locator {
  return page.locator('section.card').filter({ has: page.getByRole('heading', { name: heading, exact: true }) });
}
function responseFor(page: Page, path: string, method = 'GET', query: Record<string, string> = {}) {
  return page.waitForResponse(response => {
    const url = new URL(response.url());
    return url.pathname === `/api/v3${path}` && response.request().method() === method
      && Object.entries(query).every(([key, value]) => url.searchParams.get(key) === value);
  });
}
async function signOut(page: Page): Promise<void> {
  await page.locator('.account-menu summary').click();
  await page.getByRole('button', { name: 'Sign out', exact: true }).click();
  await expect(page.getByRole('heading', { name: 'Welcome back', exact: true })).toBeVisible();
}
async function systemTab(page: Page, label: string): Promise<void> {
  await page.getByRole('navigation', { name: 'System sections', exact: true }).getByRole('button', { name: label, exact: true }).click();
}
async function saveSystem(page: Page, id: number): Promise<void> {
  const saved = responseFor(page, `/systems/${id}`, 'PATCH');
  await page.getByRole('button', { name: 'Save general settings', exact: true }).click();
  expect((await saved).status()).toBe(200);
  await expect(page.getByRole('status').filter({ hasText: 'System settings saved.' })).toBeVisible();
}

test('HOME-01/02: dashboard cards show independently known scoped counts and real activity opens only authorized resources', async ({ page }) => {
  await login(page);
  await expect(page.getByLabel('Overview scope', { exact: true })).toHaveCount(0);
  const expected = { Projects: 1, Experiments: 2, 'Running evaluations': 2, 'Completed evaluations': 2, 'Total jobs': 15, 'Active jobs': 5, 'Failed jobs': 1 };
  for (const [label, count] of Object.entries(expected)) {
    const stat = page.locator('section.stat-card').filter({ has: page.getByText(label, { exact: true }) });
    await expect(stat.locator('strong')).toHaveText(String(count));
  }
  expect((await data(page, '/dashboard')).counts).toEqual({ projects: 1, experiments: 2, evaluationsRunning: 2, evaluationsCompleted: 2, jobs: 15, activeJobs: 5, failedJobs: 1 });
  const activity = card(page, 'Recent activity');
  await expect(activity).toContainText('Synthetic activity for Fixture Project.');
  await expect(activity).toContainText('Owner Fixture');
  await expect(activity.locator('time')).toHaveAttribute('title', '15.01.2026 12:00:00');
  await expect(activity.locator('time')).toContainText(/ago|just now/);
  await expect(activity.locator('.activity-symbol app-icon svg')).toHaveCount(1);
  const event = activity.getByRole('link', { name: 'Fixture project created', exact: true });
  await expect(event).toHaveAttribute('href', '/projects/1'); await event.click();
  await expect(page.getByRole('heading', { name: 'Fixture Project', exact: true })).toBeVisible();
  await signOut(page); await login(page, 'outsider');
  const outsider = { Projects: 1, Experiments: 1, 'Running evaluations': 0, 'Completed evaluations': 1, 'Total jobs': 1, 'Active jobs': 0, 'Failed jobs': 0 };
  for (const [label, count] of Object.entries(outsider)) {
    await expect(page.locator('section.stat-card').filter({ has: page.getByText(label, { exact: true }) }).locator('strong')).toHaveText(String(count));
  }
  await expect(card(page, 'Recent activity')).toContainText('No activity yet.');
  expect((await data(page, '/dashboard')).events).toEqual([]);
  expect((await page.request.get('/api/v3/projects/1')).status()).toBe(403);
  await expect(page.getByLabel('Overview scope', { exact: true })).toHaveCount(0);
  await signOut(page); await login(page, 'admin');
  const scope = page.getByLabel('Overview scope', { exact: true });
  await expect(scope.locator('option:checked')).toHaveText('My workspace');
  await expect(page.locator('section.stat-card').filter({ has: page.getByText('Projects', { exact: true }) }).locator('strong')).toHaveText('0');
  const allUsers = responseFor(page, '/dashboard', 'GET', { all: 'true' }); await scope.selectOption({ label: 'All users' });
  expect((await allUsers).ok()).toBeTruthy(); await expect(page.getByRole('heading', { name: 'All evaluations, at a glance.', exact: true })).toBeVisible();
  const global = { Projects: 2, Experiments: 3, 'Running evaluations': 2, 'Completed evaluations': 3, 'Total jobs': 16, 'Active jobs': 5, 'Failed jobs': 1 };
  for (const [label, count] of Object.entries(global)) await expect(page.locator('section.stat-card').filter({ has: page.getByText(label, { exact: true }) }).locator('strong')).toHaveText(String(count));
  await expect(card(page, 'Recent activity').getByRole('link', { name: 'Fixture project created', exact: true })).toBeVisible();
  await expect(card(page, 'Recent activity')).toContainText('Fixture node warning');
  const ownWorkspace = responseFor(page, '/dashboard', 'GET', { all: 'false' }); await scope.selectOption({ label: 'My workspace' });
  expect((await ownWorkspace).ok()).toBeTruthy(); await expect(page.getByRole('heading', { name: 'Your evaluations, at a glance.', exact: true })).toBeVisible();
  await expect(page.locator('section.stat-card').filter({ has: page.getByText('Projects', { exact: true }) }).locator('strong')).toHaveText('0');
  await expect(card(page, 'Recent activity').getByRole('link', { name: 'Fixture project created', exact: true })).toHaveCount(0);
  await fitsViewport(page);
});

test('PROJECT-02: administrator visibility, archive filters and every contextual overview sort preserve their server scope', async ({ page }) => {
  await login(page, 'admin'); await page.goto('/projects');
  await expect(page.getByLabel('Visibility', { exact: true }).locator('option:checked')).toHaveText('My projects');
  await expect(page.getByRole('link', { name: 'Fixture Project', exact: true })).toHaveCount(0);
  const all = responseFor(page, '/projects', 'GET', { all: 'true', archived: 'false' });
  await page.getByLabel('Visibility', { exact: true }).selectOption({ label: 'All projects' }); expect((await all).ok()).toBeTruthy();
  const table = page.locator('table');
  await expect(table.getByRole('link', { name: 'Fixture Project', exact: true })).toBeVisible();
  await expect(table.getByRole('link', { name: 'Private Project', exact: true })).toBeVisible();
  await expect(table).toContainText('Owner Fixture'); await expect(table).toContainText('Outsider Fixture');
  for (const [label, key] of [['Description', 'description'], ['System', 'system'], ['Owner', 'owner']] as const) {
    const sorted = responseFor(page, '/projects', 'GET', { all: 'true', sort: key });
    await table.getByRole('button', { name: new RegExp(`^${label}(?:\\s|$)`) }).click();
    expect((await sorted).ok()).toBeTruthy();
    await expect(table.getByRole('columnheader').filter({ has: page.getByRole('button', { name: new RegExp(`^${label}(?:\\s|$)`) }) })).toHaveAttribute('aria-sort', 'ascending');
  }
  const archived = responseFor(page, '/projects', 'GET', { all: 'true', archived: 'true' });
  await page.getByLabel('State', { exact: true }).selectOption('true'); expect((await archived).ok()).toBeTruthy();
  await expect(table).toContainText('No projects found');
  const everyState = responseFor(page, '/projects', 'GET', { all: 'true', archived: 'all' });
  await page.getByLabel('State', { exact: true }).selectOption('all'); expect((await everyState).ok()).toBeTruthy();
  await expect(table.locator('tbody tr')).toHaveCount(2);
  const own = responseFor(page, '/projects', 'GET', { all: 'false', archived: 'all' });
  await page.getByLabel('Visibility', { exact: true }).selectOption({ label: 'My projects' }); expect((await own).ok()).toBeTruthy();
  await expect(table).toContainText('No projects found'); await fitsViewport(page);
});

test('PROJECT-10: a persisted descendant event renders actor, German time and working project/experiment hierarchy links', async ({ page }) => {
  await login(page); await page.goto('/experiments/1');
  await card(page, 'Experiment details').getByLabel('Description', { exact: true }).fill('Child activity with Zürich & literal <text>');
  const saved = responseFor(page, '/experiments/1', 'PATCH');
  await page.getByRole('button', { name: 'Save experiment', exact: true }).click(); expect((await saved).ok()).toBeTruthy();
  await expect(page.getByRole('status').filter({ hasText: 'Experiment saved.' })).toBeVisible();
  expect((await data(page, '/experiments/1')).description).toBe('Child activity with Zürich & literal <text>');
  await page.goto('/projects/1');
  const entry = card(page, 'Recent activity').locator('article').filter({ has: page.getByRole('link', { name: 'Experiment updated', exact: true }) });
  await expect(entry).toHaveCount(1); await expect(entry).toContainText('Owner Fixture');
  await expect(entry.locator('time')).toHaveAttribute('title', /\d{2}\.\d{2}\.\d{4} \d{2}:\d{2}:\d{2}/);
  await expect(entry.locator('.activity-symbol app-icon svg')).toHaveCount(1);
  await expect(entry.getByRole('link', { name: 'Fixture Project', exact: true })).toHaveAttribute('href', '/projects/1');
  await expect(entry.getByRole('link', { name: 'Fixture Experiment', exact: true })).toHaveAttribute('href', '/experiments/1');
  await entry.getByRole('link', { name: 'Experiment updated', exact: true }).click();
  await expect(page.getByRole('heading', { name: 'Fixture Experiment', exact: true })).toBeVisible();
  await page.goBack(); await expect(page.getByRole('heading', { name: 'Fixture Project', exact: true })).toBeVisible();
  await entry.getByRole('link', { name: 'Fixture Experiment', exact: true }).click();
  await expect(page.getByRole('heading', { name: 'Fixture Experiment', exact: true })).toBeVisible();
  const events = await data(page, '/projects/1/events');
  expect(events.items.find((event: any) => event.title === 'Experiment updated').resources).toEqual(expect.arrayContaining([
    expect.objectContaining({ type: 'project', id: 1 }), expect.objectContaining({ type: 'experiment', id: 1 }),
  ])); await fitsViewport(page);
});

test('SYSTEM-01/02/03/06/12: local system creation retains its logo, owner and integration ID through archive filters and restoration', async ({ page }) => {
  test.setTimeout(90_000); await login(page, 'admin'); await page.goto('/systems');
  await page.getByRole('button', { name: 'Add system', exact: true }).click();
  const dialog = page.getByRole('dialog', { name: 'Add system', exact: true });
  const name = 'Browser system Zürich & <literal>';
  await dialog.getByLabel('Name', { exact: true }).fill(name);
  await dialog.getByLabel('Description', { exact: true }).fill('A local fixture definition');
  await dialog.getByLabel('Owner', { exact: true }).selectOption({ label: 'Owner Fixture (owner)' });
  await expect(dialog.getByLabel('Name', { exact: true })).toHaveValue(name);
  await expect(dialog.getByLabel('Clone a remote repository', { exact: true })).toHaveCount(0);
  const created = responseFor(page, '/systems', 'POST'); await dialog.getByRole('button', { name: 'Create', exact: true }).click();
  expect((await created).status()).toBe(201); await expect(page).toHaveURL(/\/systems\/[1-9][0-9]*$/);
  const systemId = Number(new URL(page.url()).pathname.split('/').at(-1)), system = await data(page, `/systems/${systemId}`);
  expect(system).toMatchObject({ name, description: 'A local fixture definition', ownerId: 3, archived: false, cem: false });
  expect(system.identifier).toEqual(expect.any(String)); expect(system.identifier.length).toBeGreaterThan(0);
  await expect(page.getByRole('heading', { name, exact: true })).toBeVisible();
  await expect(page.getByText(system.identifier, { exact: true })).toBeVisible();
  const logo = await page.request.get(`/api/v3/systems/${system.id}/logo`); expect(logo.ok()).toBeTruthy(); expect(logo.headers()['content-type']).toContain('image/png');
  await page.getByLabel('Archive this system', { exact: true }).check(); await saveSystem(page, system.id);
  expect((await data(page, `/systems/${system.id}`)).archived).toBe(true);
  await page.getByRole('link', { name: 'All systems', exact: true }).click();
  await page.getByLabel('Visibility', { exact: true }).selectOption({ label: 'All systems' });
  await expect(page.getByRole('link', { name, exact: true })).toHaveCount(0);
  await page.getByLabel('State', { exact: true }).selectOption('true');
  const row = page.getByRole('row').filter({ has: page.getByRole('link', { name, exact: true }) });
  await expect(row).toContainText('Owner Fixture'); await expect(row).toContainText('Archived'); await expect(row).toContainText('A local fixture definition');
  await expect(row.locator('img.system-avatar')).toHaveAttribute('src', `/api/v3/systems/${system.id}/logo`);
  await expect.poll(() => row.locator('img.system-avatar').evaluate((image: HTMLImageElement) => image.complete && image.naturalWidth > 0)).toBe(true);
  await page.getByLabel('State', { exact: true }).selectOption('all');
  await expect(page.getByRole('link', { name: 'Fixture System', exact: true })).toBeVisible(); await expect(page.getByRole('link', { name, exact: true })).toBeVisible();
  await row.getByRole('link', { name, exact: true }).click();
  await page.getByLabel('Archive this system', { exact: true }).uncheck(); await saveSystem(page, system.id);
  expect((await data(page, `/systems/${system.id}`)).archived).toBe(false);
  await page.reload(); await expect(page.getByText(system.identifier, { exact: true })).toBeVisible();
  await expect(page.getByLabel('Archive this system', { exact: true })).not.toBeChecked(); await fitsViewport(page);
});

test('SYSTEM-04/06/11: active-project archive fails atomically, CEM choices propagate and an ownership transfer changes real access', async ({ page }) => {
  test.setTimeout(90_000); await login(page, 'admin'); await page.goto('/systems/1');
  const original = await data(page, '/systems/1');
  await page.getByLabel('Description', { exact: true }).fill('An archive failure must not save this draft');
  await page.getByLabel('Archive this system', { exact: true }).check();
  const rejected = responseFor(page, '/systems/1', 'PATCH'); await page.getByRole('button', { name: 'Save general settings', exact: true }).click();
  expect((await rejected).status()).toBe(409); await expect(page.getByRole('alert')).toContainText('Archive current projects');
  expect(await data(page, '/systems/1')).toMatchObject({ archived: false, description: original.description, ownerId: 3, cem: true });
  await page.getByLabel('Description', { exact: true }).fill(original.description); await page.getByLabel('Archive this system', { exact: true }).uncheck();
  await page.getByLabel('Enable Chronos Environment Manager (CEM)', { exact: true }).uncheck(); await saveSystem(page, 1);
  expect((await data(page, '/systems/1')).cem).toBe(false);
  expect((await data(page, '/experiments/1/run-options')).environments.some((environment: any) => environment.type === 'cem')).toBe(false);
  await page.getByLabel('Enable Chronos Environment Manager (CEM)', { exact: true }).check(); await saveSystem(page, 1);
  expect((await data(page, '/experiments/1/run-options')).environments).toEqual(expect.arrayContaining([expect.objectContaining({ key: 'cem-fixture', type: 'cem' })]));
  await page.getByLabel('Owner', { exact: true }).selectOption({ label: 'Member Fixture (member) · #4' }); await saveSystem(page, 1);
  expect((await data(page, '/systems/1')).ownerId).toBe(4);
  await signOut(page); await login(page, 'owner');
  expect((await page.request.get('/api/v3/systems/1')).status()).toBe(403); expect((await page.request.get('/api/v3/projects/1')).status()).toBe(200);
  await page.goto('/systems'); await expect(page.getByRole('link', { name: 'Fixture System', exact: true })).toHaveCount(0);
  await signOut(page); await login(page, 'member'); await page.goto('/systems');
  const row = page.getByRole('row').filter({ has: page.getByRole('link', { name: 'Fixture System', exact: true }) });
  await expect(row).toContainText('Member Fixture'); await row.getByRole('link', { name: 'Fixture System', exact: true }).click();
  await expect(page.getByLabel('Owner ID', { exact: true })).toHaveValue('4'); await expect(page.getByLabel('Owner ID', { exact: true })).toHaveAttribute('readonly', '');
  await expect(page.getByLabel('Enable Chronos Environment Manager (CEM)', { exact: true })).toBeChecked(); await fitsViewport(page);
});

test('SYSTEM-05/14: installation-disabled remote credentials and update/push controls cannot be activated through the UI or API', async ({ page }) => {
  await login(page, 'admin'); await page.goto('/systems/1');
  expect((await data(page, '/meta')).features.remoteRepositories).toBe(false);
  await systemTab(page, 'Repository and history'); const repository = card(page, 'Repository');
  for (const label of ['Repository URL', 'Type', 'Branch', 'Username', 'New password (optional)']) await expect(repository.getByLabel(label, { exact: true })).toBeDisabled();
  await expect(repository).toContainText('Remote repository operations are disabled by this installation.');
  await expect(repository.getByRole('button', { name: 'Update from configured branch', exact: true })).toHaveCount(0);
  await expect(repository.getByRole('button', { name: 'Push committed changes', exact: true })).toHaveCount(0);
  const before = await data(page, '/systems/1'); const session = await data(page, '/session'); const headers = { 'X-CSRF-Token': session.csrfToken };
  expect((await page.request.patch('/api/v3/systems/1', { headers, data: { repository: { url: 'https://fixture.invalid/system.git', type: 'git', branch: 'main', username: 'fixture', password: 'Never persisted' } } })).status()).toBe(403);
  for (const action of ['update', 'push']) expect((await page.request.post(`/api/v3/systems/1/repository/${action}`, { headers, data: {} })).status()).toBe(403);
  expect((await data(page, '/systems/1')).repository).toEqual(before.repository);
  const refreshed = responseFor(page, '/systems/1/repository'); await repository.getByRole('button', { name: 'Refresh local history', exact: true }).click(); expect((await refreshed).ok()).toBeTruthy();
  await expect(card(page, 'Version history')).toContainText('Fixture commit'); await fitsViewport(page);
});

test('SYSTEM-05/14: enabled repository controls persist masked credentials, recover from type mismatch and complete real API update/push orchestration', async ({ page }) => {
  test.setTimeout(90_000); repositoryPolicy('restore');
  try {
    repositoryPolicy('enable');
    await expect.poll(async () => (await data(page, '/meta')).features.remoteRepositories, { timeout: 15_000 }).toBe(true);
    await login(page, 'admin'); await page.goto('/systems/1'); await systemTab(page, 'Repository and history');
    let repository = card(page, 'Repository');
    await repository.getByLabel('Repository URL', { exact: true }).fill('https://fixture.invalid/system.git');
    await repository.getByLabel('Type', { exact: true }).selectOption('git');
    await repository.getByLabel('Branch', { exact: true }).fill('fixture-branch');
    await repository.getByLabel('Username', { exact: true }).fill('fixture-account');
    const secret = 'Synthetic browser repository token';
    await repository.getByLabel('New password (optional)', { exact: true }).fill(secret);
    await expect(repository.getByLabel('New password (optional)', { exact: true })).toHaveAttribute('type', 'password');
    const saved = responseFor(page, '/systems/1', 'PATCH'); await repository.getByRole('button', { name: 'Save repository settings', exact: true }).click();
    const response = await saved; expect(response.status()).toBe(200); expect(await response.text()).not.toContain(secret);
    await expect(repository.getByLabel('New password (optional)', { exact: true })).toHaveValue('');
    expect((await data(page, '/systems/1')).repository).toMatchObject({ url: 'https://fixture.invalid/system.git', type: 'git', branch: 'fixture-branch', username: 'fixture-account', hasPassword: true });
    await page.reload(); await systemTab(page, 'Repository and history'); repository = card(page, 'Repository');
    await expect(repository.getByLabel('Branch', { exact: true })).toHaveValue('fixture-branch');
    await expect(repository.getByLabel('Username', { exact: true })).toHaveValue('fixture-account');
    await expect(repository.getByLabel('New password (optional)', { exact: true })).toHaveValue('');
    await expect(repository).toContainText('A password is saved. Leave this field empty to keep it.');

    // Saving an empty replacement field keeps the stored credential. A VCS type
    // mismatch must report a recoverable error before changing repository files.
    await repository.getByLabel('Type', { exact: true }).selectOption('hg');
    await repository.getByLabel('Branch', { exact: true }).fill('master');
    const changedType = responseFor(page, '/systems/1', 'PATCH'); await repository.getByRole('button', { name: 'Save repository settings', exact: true }).click();
    const typeResponse = await changedType; expect(typeResponse.status()).toBe(200);
    expect(typeResponse.request().postDataJSON().repository).not.toHaveProperty('password');
    expect((await data(page, '/systems/1')).repository).toMatchObject({ type: 'hg', branch: 'master', hasPassword: true });
    const parameters = await data(page, '/systems/1/parameters');
    const mismatch = responseFor(page, '/systems/1/repository/update', 'POST');
    await repository.getByRole('button', { name: 'Update from configured branch', exact: true }).click();
    expect((await mismatch).status()).toBe(409); await expect(page.getByRole('alert')).toContainText('does not match this system repository');
    expect(await data(page, '/systems/1/parameters')).toEqual(parameters);
    await repository.getByLabel('Type', { exact: true }).selectOption('git');
    const repaired = responseFor(page, '/systems/1', 'PATCH'); await repository.getByRole('button', { name: 'Save repository settings', exact: true }).click(); expect((await repaired).ok()).toBeTruthy();

    // The HTTP app and all authorization/CSRF/filesystem preflight are real.
    // The disposable image's inert VCS boundary supplies known safe metadata;
    // separate network-disabled native Git/Hg tests verify actual VCS semantics.
    await signOut(page); await login(page, 'owner'); await page.goto('/systems/1'); await systemTab(page, 'Repository and history'); repository = card(page, 'Repository');
    await expect(repository.getByLabel('Repository URL', { exact: true })).toBeDisabled();
    const updated = responseFor(page, '/systems/1/repository/update', 'POST');
    await repository.getByRole('button', { name: 'Update from configured branch', exact: true }).click();
    const updateResponse = await updated; expect(updateResponse.status()).toBe(200); expect(await updateResponse.text()).not.toContain(secret);
    await expect(page.getByRole('status').filter({ hasText: 'System repository updated.' })).toBeVisible();
    expect(await data(page, '/systems/1/parameters')).toEqual(parameters);
    const pushed = responseFor(page, '/systems/1/repository/push', 'POST');
    await repository.getByRole('button', { name: 'Push committed changes', exact: true }).click();
    const pushResponse = await pushed; expect(pushResponse.status()).toBe(200); expect(await pushResponse.text()).not.toContain(secret);
    await expect(page.getByRole('status').filter({ hasText: 'Committed system changes synchronized with the configured remote branch.' })).toBeVisible();
    await expect(card(page, 'Version history')).toContainText('Fixture commit'); await fitsViewport(page);
  } finally { repositoryPolicy('restore'); }
  await expect.poll(async () => (await data(page, '/meta')).features.remoteRepositories, { timeout: 15_000 }).toBe(false);
});
