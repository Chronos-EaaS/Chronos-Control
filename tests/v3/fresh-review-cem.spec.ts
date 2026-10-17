import { test, expect, login, fitsViewport } from './fixture';

test('CEM-01: configured rows per page apply until the user chooses another size', async ({ page }) => {
  await login(page, 'admin');
  const nodeResponse = () => page.waitForResponse(response => new URL(response.url()).pathname === '/api/v3/cem/nodes' && response.request().method() === 'GET');
  const initial = nodeResponse(); await page.goto('/cem'); const response = await initial;
  expect(response.status()).toBe(200); expect(new URL(response.url()).searchParams.has('pageSize')).toBe(false);
  expect((await response.json()).data.pageSize).toBe(5);
  await expect(page.getByLabel('Rows per page', { exact: true }).locator('option:checked')).toHaveText('5');
  const chosen = nodeResponse(); await page.getByLabel('Rows per page', { exact: true }).selectOption({ label: '10' });
  const changed = await chosen; expect(new URL(changed.url()).searchParams.get('pageSize')).toBe('10'); expect((await changed.json()).data.pageSize).toBe(10);
  await page.getByRole('searchbox', { name: 'Search nodes', exact: true }).fill('fixture');
  const searched = nodeResponse(); await page.getByRole('button', { name: 'Search', exact: true }).click();
  const search = new URL((await searched).url()); expect(search.searchParams.get('pageSize')).toBe('10'); expect(search.searchParams.get('search')).toBe('fixture'); expect(search.searchParams.get('page')).toBe('1');
  const refreshed = nodeResponse(); await page.getByRole('button', { name: 'Refresh nodes', exact: true }).click();
  expect(new URL((await refreshed).url()).searchParams.get('pageSize')).toBe('10');
  const revisited = nodeResponse(); await page.goto('/cem');
  const fresh = await revisited; expect(new URL(fresh.url()).searchParams.has('pageSize')).toBe(false); expect((await fresh.json()).data.pageSize).toBe(5);
  await expect(page.getByLabel('Rows per page', { exact: true }).locator('option:checked')).toHaveText('5'); await fitsViewport(page);
});
