import { test, expect, login, fitsViewport } from './fixture';
import { observeLive } from './live-helper';

test('UI-DETAILS-01: project order, member heights and trailing table actions stay consistent', async ({ page }) => {
  await login(page);
  const session = (await (await page.request.get('/api/ui/session')).json()).data;
  const created = await page.request.post('/api/ui/projects', {
    headers: { Origin: new URL(page.url()).origin, 'X-CSRF-Token': session.csrfToken },
    data: { name: 'Newest fixture project', description: 'Synthetic sorting example', systemId: 1 },
  });
  expect(created.ok()).toBe(true);
  const id = (await created.json()).data.id;
  const requested = page.waitForResponse(response => new URL(response.url()).pathname === '/api/ui/projects');
  await page.goto('/projects');
  expect(new URL((await requested).url()).searchParams.get('sort')).toBe('-id');
  const rows = page.locator('.resource-browser tbody tr');
  await expect(rows).toHaveCount(2);
  await expect(rows.first().locator('td').first()).toHaveText(String(id));
  await expect(page.locator('.resource-browser thead th').first()).toHaveAttribute('aria-sort', 'descending');
  await page.getByRole('button', { name: /^ID / }).click();
  await expect(rows.first().locator('td').first()).toHaveText('1');

  await page.goto('/projects/1');
  const members = page.getByRole('table', { name: 'Project members', exact: true }).locator('tbody tr');
  await expect(members).toHaveCount(2);
  const heights = await members.evaluateAll(elements => elements.map(element => element.getBoundingClientRect().height));
  expect(Math.max(...heights) - Math.min(...heights)).toBeLessThanOrEqual(1);
  const actions = page.locator('.project-row-actions').first();
  await expect(actions).toBeVisible();
  const alignment = await actions.evaluate(element => {
    const cell = element.closest('td')!, buttons = [...element.querySelectorAll<HTMLElement>('.btn')];
    const right = Math.max(...buttons.map(button => button.getBoundingClientRect().right));
    return Math.abs(cell.getBoundingClientRect().right - parseFloat(getComputedStyle(cell).paddingRight) - right);
  });
  expect(alignment).toBeLessThanOrEqual(2);
  await fitsViewport(page);
});

test('UI-DETAILS-02: pushed pages omit reload controls and keep manual snapshots for a paused log', async ({ page }) => {
  const live = observeLive(page);
  await login(page, 'admin');
  await page.goto('/cem');
  await expect(page.getByRole('heading', { name: /CEM nodes/ })).toBeVisible();
  await expect(page.getByRole('button', { name: 'Refresh nodes', exact: true })).toHaveCount(0);
  await page.goto('/cem/nodes/fixture-busy');
  await expect(page.getByRole('heading', { name: 'Node information', exact: true })).toBeVisible();
  await expect(page.getByRole('button', { name: 'Refresh status', exact: true })).toHaveCount(0);
  await page.goto('/status');
  await expect(page.getByRole('heading', { name: 'Evaluations', exact: true })).toBeVisible();
  await expect(page.getByRole('button', { name: 'Refresh now', exact: true })).toHaveCount(0);
  await page.goto('/jobs/5');
  await expect(page.getByLabel('Automatically update log', { exact: true })).toBeChecked();
  await expect(page.getByRole('button', { name: 'Refresh log', exact: true })).toHaveCount(0);
  await page.getByLabel('Automatically update log', { exact: true }).uncheck();
  const refreshed = live.next('/jobs/5/log', () => true, live.mark());
  await page.getByRole('button', { name: 'Refresh log', exact: true }).click();
  await refreshed;
  await page.getByLabel('Automatically update log', { exact: true }).check();
  await expect(page.getByRole('button', { name: 'Refresh log', exact: true })).toHaveCount(0);
  await page.goto('/admin');
  await expect(page.getByRole('heading', { name: 'CEM settings', exact: true })).toBeVisible();
  await expect(page.getByRole('heading', { name: 'Cem settings', exact: true })).toHaveCount(0);
  // Storage is an explicit filesystem probe rather than a live subscription.
  await expect(page.getByRole('button', { name: 'Refresh', exact: true })).toBeEnabled();
  await fitsViewport(page);
});

test('UI-DETAILS-03: evaluation actions have visible labels and fill every available row', async ({ page }) => {
  await login(page); await page.goto('/evaluations/2');
  const strip = page.locator('.action-strip[aria-label="Evaluation actions"]');
  const buttons = strip.locator('.btn');
  await expect(buttons).toHaveCount(5);
  const layout = await strip.evaluate(element => {
    const container = element.getBoundingClientRect();
    const rows = new Map<number, DOMRect[]>();
    for (const button of element.querySelectorAll<HTMLElement>('.btn')) {
      const bounds = button.getBoundingClientRect(), key = Math.round(bounds.top);
      rows.set(key, [...rows.get(key) ?? [], bounds]);
    }
    return { labels: [...element.querySelectorAll<HTMLElement>('.btn')].map(button => button.innerText.trim()),
      gaps: [...rows.values()].map(row => ({ left: Math.abs(Math.min(...row.map(rect => rect.left)) - container.left),
        right: Math.abs(Math.max(...row.map(rect => rect.right)) - container.right) })) };
  });
  expect(layout.labels.every(label => label.length > 0)).toBe(true);
  for (const row of layout.gaps) { expect(row.left).toBeLessThanOrEqual(2); expect(row.right).toBeLessThanOrEqual(2); }
  await fitsViewport(page);
});
