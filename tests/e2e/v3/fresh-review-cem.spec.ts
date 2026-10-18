import { test, expect, login, fitsViewport } from './fixture';

test('CEM-01: configured rows per page apply until the user chooses another size', async ({ page }) => {
  await login(page, 'admin');
  const nodeResponse = () => page.waitForResponse(response => new URL(response.url()).pathname === '/api/ui/cem/nodes' && response.request().method() === 'GET');
  const initial = nodeResponse(); await page.goto('/cem'); const response = await initial;
  expect(response.status()).toBe(200); expect(new URL(response.url()).searchParams.has('pageSize')).toBe(false);
  // Observe the applied response in the UI. Chromium can discard response bodies
  // at document navigation boundaries; the HTTP suite verifies the JSON contract.
  await expect(page.getByLabel('Rows per page', { exact: true }).locator('option:checked')).toHaveText('5');
  await expect(page.getByRole('button', { name: 'Refresh nodes', exact: true })).toBeEnabled();
  const chosen = nodeResponse(); await page.getByLabel('Rows per page', { exact: true }).selectOption({ label: '10' });
  const changed = await chosen; expect(changed.status()).toBe(200); expect(new URL(changed.url()).searchParams.get('pageSize')).toBe('10');
  await expect(page.getByRole('button', { name: 'Refresh nodes', exact: true })).toBeEnabled();
  await page.getByRole('searchbox', { name: 'Search nodes', exact: true }).fill('fixture');
  const searched = nodeResponse(); await page.getByRole('button', { name: 'Search', exact: true }).click();
  const search = new URL((await searched).url()); expect(search.searchParams.get('pageSize')).toBe('10'); expect(search.searchParams.get('search')).toBe('fixture'); expect(search.searchParams.get('page')).toBe('1');
  const refreshed = nodeResponse(); await page.getByRole('button', { name: 'Refresh nodes', exact: true }).click();
  expect(new URL((await refreshed).url()).searchParams.get('pageSize')).toBe('10');
  await expect(page.getByRole('button', { name: 'Refresh nodes', exact: true })).toBeEnabled();
  const revisited = nodeResponse(); await page.goto('/cem');
  const fresh = await revisited; expect(fresh.status()).toBe(200); expect(new URL(fresh.url()).searchParams.has('pageSize')).toBe(false);
  await expect(page.getByLabel('Rows per page', { exact: true }).locator('option:checked')).toHaveText('5'); await fitsViewport(page);
});
