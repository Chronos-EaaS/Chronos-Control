import { test, expect, login, fitsViewport } from './fixture';
import { observeLive } from './live-helper';

test('CEM-01: configured rows per page apply until the user chooses another size', async ({ page }) => {
  const live = observeLive(page);
  await login(page, 'admin');
  const nodeResponse = () => live.next('/cem/nodes', () => true, live.mark());
  const initial = nodeResponse(); await page.goto('/cem'); const response = await initial;
  expect(response.data.pageSize).toBe(5); expect(Object.hasOwn(response.query, 'pageSize')).toBe(false);
  await expect(page.getByLabel('Rows per page', { exact: true }).locator('option:checked')).toHaveText('5');
  await expect(page.getByRole('button', { name: 'Refresh nodes', exact: true })).toHaveCount(0);
  const chosen = nodeResponse(); await page.getByLabel('Rows per page', { exact: true }).selectOption({ label: '10' });
  const changed = await chosen; expect(changed.data.pageSize).toBe(10); expect(changed.query.pageSize).toBe('10');
  await expect(page.getByRole('button', { name: 'Refresh nodes', exact: true })).toHaveCount(0);
  await page.getByRole('searchbox', { name: 'Search nodes', exact: true }).fill('fixture');
  const searched = nodeResponse(); await page.getByRole('button', { name: 'Search nodes', exact: true }).click();
  const search = await searched; expect(search.query.pageSize).toBe('10'); expect(search.query.search).toBe('fixture'); expect(search.query.page).toBe('1');
  const sorted = nodeResponse(); await page.getByRole('button', { name: /^Hostname/ }).click();
  expect((await sorted).query.pageSize).toBe('10');
  await expect(page.getByRole('button', { name: 'Refresh nodes', exact: true })).toHaveCount(0);
  const revisited = nodeResponse(); await page.goto('/cem');
  const fresh = await revisited; expect(fresh.data.pageSize).toBe(5); expect(Object.hasOwn(fresh.query, 'pageSize')).toBe(false);
  await expect(page.getByLabel('Rows per page', { exact: true }).locator('option:checked')).toHaveText('5'); await fitsViewport(page);
});

test('CEM-01/02: full-row navigation preserves nested hostname and running-job links', async ({ page }) => {
  await login(page, 'admin'); await page.goto('/cem');
  const table = page.getByRole('table', { name: 'CEM execution nodes' });
  const busy = table.getByRole('row').filter({ has: page.locator('a[href="/cem/nodes/fixture-busy"]') });
  await expect(busy).toBeVisible();
  // The timestamp cell has no link. Clicking it follows the same node as the
  // hostname, without requiring a tiny text or icon target.
  await busy.getByRole('cell').last().click();
  await expect(page).toHaveURL(/\/cem\/nodes\/fixture-busy$/);
  await expect(page.getByRole('link', { name: 'Running job #2', exact: true })).toHaveAttribute('href', '/jobs/2');
  await page.goto('/cem');
  const hostname = busy.getByRole('rowheader').getByRole('link');
  await expect(hostname).toHaveAttribute('href', '/cem/nodes/fixture-busy'); await hostname.click();
  await expect(page).toHaveURL(/\/cem\/nodes\/fixture-busy$/);
  await page.goto('/cem');
  const job = busy.getByRole('link', { name: 'Job #2', exact: true });
  await expect(job).toHaveAttribute('href', '/jobs/2'); await job.click();
  await expect(page).toHaveURL(/\/jobs\/2$/);
  await expect(page.getByRole('heading', { name: /^Job details/ })).toBeVisible(); await fitsViewport(page);
});

test('CEM-02, ADMIN-01, SYSTEM-04: compact administration, system and node panels fit 360 and 390 pixel screens', async ({ page }) => {
  test.setTimeout(90_000); await login(page, 'superadmin');
  for (const width of [360, 390]) {
    await page.setViewportSize({ width, height: 844 });
    await page.goto('/admin');
    for (const name of ['Users', 'Systems', 'Application maintenance', 'Data storage', 'CEM environments']) await expect(page.getByRole('heading', { name, exact: true })).toBeVisible();
    await expect(page.getByRole('columnheader', { name: 'Last name', exact: true })).toBeVisible();
    await expect(page.getByRole('columnheader', { name: 'First name', exact: true })).toBeVisible();
    await fitsViewport(page);
    await page.getByRole('button', { name: 'Create user', exact: true }).click();
    const dialog = page.getByRole('dialog', { name: 'Create user', exact: true });
    await expect(dialog.getByLabel('Username', { exact: true })).toBeVisible();
    const dialogBox = await dialog.boundingBox(); expect(dialogBox).not.toBeNull();
    expect(dialogBox!.x).toBeGreaterThanOrEqual(0); expect(dialogBox!.x + dialogBox!.width).toBeLessThanOrEqual(width + 1);
    await dialog.getByRole('button', { name: 'Cancel', exact: true }).click();
    await page.goto('/systems/1');
    await expect(page.getByRole('heading', { name: 'General settings', exact: true })).toBeVisible();
    await expect(page.getByRole('heading', { name: 'Experiment and results configuration', exact: true })).toHaveCount(0);
    await expect(page.getByLabel('System ID', { exact: true })).toBeVisible();
    await expect(page.getByLabel('System ID', { exact: true })).toHaveAttribute('readonly', '');
    await expect(page.getByRole('img', { name: 'System logo', exact: true })).toBeVisible(); await fitsViewport(page);
    await page.getByRole('navigation', { name: 'System sections', exact: true }).getByRole('button', { name: 'Parameters', exact: true }).click();
    const parameters = page.locator('app-parameter-schema-editor');
    const structure = parameters.getByRole('navigation', { name: 'Parameter structure', exact: true });
    await expect(structure.getByRole('button', { name: /^Edit parameter / })).toHaveCount(13);
    await structure.getByRole('button', { name: 'Edit parameter Quantity', exact: true }).click();
    await expect(parameters.getByLabel('Input type', { exact: true })).toHaveCount(1);
    await expect(parameters.getByRole('group', { name: 'Quantity', exact: true })).toBeVisible(); await fitsViewport(page);
    await page.goto('/cem/nodes/fixture-warning');
    await expect(page.getByRole('progressbar', { name: 'CPU utilization' })).toHaveAttribute('aria-valuenow', '55');
    await expect(page.getByRole('progressbar', { name: 'Memory utilization' })).toHaveAttribute('aria-valuenow', '95');
    for (const panel of ['.node-information', '.node-resource', '.node-health', '.node-history']) {
      for (const element of await page.locator(panel).all()) {
        await expect(element).toBeVisible(); const bounds = await element.boundingBox(); expect(bounds).not.toBeNull();
        expect(bounds!.x).toBeGreaterThanOrEqual(0); expect(bounds!.x + bounds!.width).toBeLessThanOrEqual(width + 1);
      }
    }
    await expect(page.getByRole('heading', { name: 'Node event history', exact: true })).toBeVisible(); await fitsViewport(page);
  }
});
