import { test, expect, login, fitsViewport, type Page } from './fixture';
import { observeLive } from './live-helper';

function panel(page: Page) {
  return page.getByRole('region', { name: 'Maintainers', exact: true });
}
async function data(page: Page, path: string): Promise<any> {
  const response = await page.request.get('/api/ui' + path); expect(response.ok()).toBe(true);
  return (await response.json()).data;
}
async function systemTab(page: Page, name: string): Promise<void> {
  await page.getByRole('navigation', { name: 'System sections', exact: true }).getByRole('button', { name: new RegExp(`^${name}(?: Unsaved changes)?$`) }).click();
}

test('SYSTEM-MAINTAINERS-01: owner manages maintainers and transfers the designation without replacing editor drafts', async ({ page }) => {
  page.on('dialog', dialog => dialog.accept());
  const live = observeLive(page), rosterGets: string[] = [];
  page.on('request', request => { if (request.method() === 'GET' && new URL(request.url()).pathname.includes('/maintainers')) rosterGets.push(request.url()); });
  await login(page); await page.goto('/systems/1');
  const maintainers = panel(page); await expect(maintainers).toBeVisible();
  await expect(maintainers.getByRole('row').filter({ hasText: 'Owner Fixture' })).toContainText('Owner');
  const candidates = maintainers.getByLabel('Add a maintainer', { exact: true });
  await expect(candidates.locator('option').filter({ hasText: 'Disabled Fixture' })).toHaveCount(0);
  await page.getByLabel('Description', { exact: true }).fill('Unsaved general description');
  await candidates.selectOption({ label: 'Member Fixture (member)' });
  const added = page.waitForResponse(response => new URL(response.url()).pathname === '/api/ui/systems/1/maintainers' && response.request().method() === 'POST');
  await maintainers.getByRole('button', { name: 'Add maintainer', exact: true }).click(); expect((await added).ok()).toBe(true);
  const member = maintainers.getByRole('row').filter({ hasText: 'Member Fixture' }); await expect(member).toBeVisible();
  const rowHeights = await maintainers.locator('tbody tr').evaluateAll(rows => rows.map(row => row.getBoundingClientRect().height));
  expect(Math.max(...rowHeights) - Math.min(...rowHeights), 'Owner badges and action controls retain a consistent row height.').toBeLessThanOrEqual(1);
  await expect(page.getByLabel('Description', { exact: true })).toHaveValue('Unsaved general description');
  await systemTab(page, 'Parameters');
  const editor = page.locator('app-parameter-schema-editor');
  await editor.getByRole('button', { name: 'Edit parameter Quantity', exact: true }).click();
  await editor.getByRole('group', { name: 'Quantity', exact: true }).getByLabel('Default value', { exact: true }).fill('27');
  await systemTab(page, 'General');
  const changed = live.next('/systems/1/maintainers', frame => frame.query['candidates'] !== 'true' && frame.data.ownerId === 4, live.mark());
  await member.getByRole('button', { name: 'Make member owner', exact: true }).click(); await changed;
  await expect(member).toContainText('Owner');
  await expect(maintainers.getByLabel('Add a maintainer', { exact: true })).toHaveCount(0);
  await expect(maintainers.getByRole('button')).toHaveCount(0);
  await expect(maintainers.getByRole('row').filter({ hasText: 'Owner Fixture' })).toBeVisible();
  await expect(page.getByLabel('Description', { exact: true })).toHaveValue('Unsaved general description');
  const save = page.waitForResponse(response => new URL(response.url()).pathname === '/api/ui/systems/1' && response.request().method() === 'PATCH');
  await page.getByRole('button', { name: 'Save general settings', exact: true }).click(); expect((await save).ok()).toBe(true);
  expect((await data(page, '/systems/1')).ownerId).toBe(4);
  await systemTab(page, 'Parameters');
  await editor.getByRole('button', { name: 'Edit parameter Quantity', exact: true }).click();
  await expect(editor.getByRole('group', { name: 'Quantity', exact: true }).getByLabel('Default value', { exact: true })).toHaveValue('27');
  await expect(page.getByRole('button', { name: 'Save parameters', exact: true })).toBeEnabled();
  expect((await data(page, '/systems/1/parameters')).groups[0].elements.find((item: any) => item.parameter === 'quantity').default).not.toBe('27');
  expect(rosterGets, 'Maintainer roster and candidate snapshots use WebSockets.').toEqual([]);
  await fitsViewport(page);
});

test('SYSTEM-MAINTAINERS-02: revoking a maintainer clears protected content in an open editor through WebSocket', async ({ page, playwright, baseURL }) => {
  const admin = await playwright.request.newContext({ baseURL });
  try {
    const initial = (await (await admin.get('/api/ui/session')).json()).data;
    const signed = await admin.post('/api/ui/session', { headers: { 'X-CSRF-Token': initial.csrfToken }, data: { username: 'admin', password: 'Chronos-test-2026!', remember: false } });
    expect(signed.ok()).toBe(true); const headers = { 'X-CSRF-Token': (await signed.json()).data.csrfToken };
    expect((await admin.post('/api/ui/systems/1/maintainers', { headers, data: { userId: 4 } })).ok()).toBe(true);
    const live = observeLive(page);
    await login(page, 'member'); await page.goto('/systems/1');
    await expect(panel(page)).toContainText('Member Fixture'); await expect(panel(page).getByRole('button')).toHaveCount(0);
    await systemTab(page, 'Parameters');
    const editor = page.locator('app-parameter-schema-editor');
    await editor.getByRole('button', { name: 'Edit parameter Quantity', exact: true }).click();
    await editor.getByRole('group', { name: 'Quantity', exact: true }).getByLabel('Default value', { exact: true }).fill('31');
    expect(live.active('/systems/1/maintainers')).toHaveLength(1);
    const removed = await admin.delete('/api/ui/systems/1/maintainers/4', { headers }); expect(removed.ok()).toBe(true);
    await expect(page.getByRole('navigation', { name: 'System sections', exact: true })).toHaveCount(0);
    await expect(editor).toHaveCount(0); await expect(page.getByRole('alert')).toContainText(/permission|access|authorized/i);
    expect((await page.request.get('/api/ui/systems/1')).status()).toBe(403);
    expect((await page.request.get('/api/ui/projects/1')).status()).toBe(200);
    await fitsViewport(page);
  } finally { await admin.dispose(); }
});
