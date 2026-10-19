import { readFile } from 'node:fs/promises';
import { test, expect, login, fitsViewport, type Page, type Locator } from './fixture';

function card(page: Page, name: string): Locator {
  return page.locator('section.card').filter({ has: page.getByRole('heading', { name, exact: true }) });
}

async function assertActionRowsFillAvailableWidth(page: Page): Promise<void> {
  const rows = await page.getByLabel('Job actions', { exact: true }).evaluate(container => {
    const bounds = container.getBoundingClientRect();
    const style = getComputedStyle(container);
    const left = bounds.left + parseFloat(style.paddingLeft) + parseFloat(style.borderLeftWidth);
    const right = bounds.right - parseFloat(style.paddingRight) - parseFloat(style.borderRightWidth);
    const rows: { top: number; left: number; right: number }[] = [];
    for (const action of container.querySelectorAll<HTMLElement>(':scope > a, :scope > button')) {
      const rect = action.getBoundingClientRect();
      const row = rows.find(entry => Math.abs(entry.top - rect.top) < 2);
      if (row) { row.left = Math.min(row.left, rect.left); row.right = Math.max(row.right, rect.right); }
      else rows.push({ top: rect.top, left: rect.left, right: rect.right });
    }
    return { left, right, rows };
  });
  expect(rows.rows.length).toBeGreaterThan(0);
  for (const row of rows.rows) {
    expect(Math.abs(row.left - rows.left), 'Action rows start at the container edge.').toBeLessThanOrEqual(2);
    expect(Math.abs(row.right - rows.right), 'The final action in every row uses the available width.').toBeLessThanOrEqual(2);
  }
}

test('JOB-12: compact progress retains phase values, consistent tracks and full-width actions across job states', async ({ page }) => {
  await login(page);
  const cases = [
    { id: 2, values: [0, 0, 0, 0, 0, 0], labels: ['Setup', 'Prepare', 'Warm-up', 'Execute', 'Analyze', 'Clean'] },
    { id: 3, values: [100, 100, 50, 0, 0], labels: ['Prepare', 'Warm-up', 'Execute', 'Analyze', 'Clean'] },
    { id: 5, values: [100, 100, 100, 100, 100], labels: ['Prepare', 'Warm-up', 'Execute', 'Analyze', 'Clean'] },
  ];
  for (const job of cases) {
    await page.goto(`/jobs/${job.id}`);
    const progress = card(page, 'Execution progress');
    const bars = progress.getByRole('progressbar');
    await expect(bars).toHaveCount(job.values.length);
    for (let index = 0; index < job.values.length; index++) {
      const bar = bars.nth(index);
      await expect(bar).toHaveAccessibleName(new RegExp(`^${job.labels[index]}(?: progress)?$`));
      await expect(bar).toHaveAttribute('aria-valuenow', String(job.values[index]));
      await expect(bar).toHaveAttribute('aria-valuemin', '0');
      await expect(bar).toHaveAttribute('aria-valuemax', '100');
    }
    const tracks = await bars.evaluateAll(elements => elements.map(element => {
      const bounds = element.getBoundingClientRect();
      const fill = element.querySelector('.progress-bar')!.getBoundingClientRect();
      return { height: bounds.height, width: bounds.width, fillWidth: fill.width };
    }));
    for (let index = 0; index < tracks.length; index++) {
      const track = tracks[index];
      expect(track.height).toBeGreaterThan(0);
      expect(track.height, 'Execution progress stays a compact track rather than a tall block.').toBeLessThanOrEqual(16);
      expect(Math.abs(track.height - tracks[0].height), 'Setup and execution phases share the same track height.').toBeLessThanOrEqual(1);
      expect(track.width).toBeGreaterThan(0);
      expect(track.fillWidth).toBeGreaterThanOrEqual(0);
      expect(track.fillWidth).toBeLessThanOrEqual(track.width + 1);
      expect(Math.abs(track.fillWidth / track.width * 100 - job.values[index])).toBeLessThanOrEqual(1);
    }
    await expect(page.getByRole('heading', { name: 'Execution details', exact: true })).toHaveCount(0);
    const general = card(page, 'General');
    for (const label of ['Client', 'Created', 'Started', 'Finished']) await expect(general.locator('dt').filter({ hasText: new RegExp(`^${label}$`) })).toBeVisible();
    await assertActionRowsFillAvailableWidth(page);
    await fitsViewport(page);
  }
  // Exercise wrapping independently of the browser project's default viewport.
  for (const width of [320, 390, 768, 1440]) {
    await page.setViewportSize({ width, height: 900 });
    await assertActionRowsFillAvailableWidth(page);
    await fitsViewport(page);
  }
});

test('JOB-13: CEM deployment notice follows General, and unfinished jobs can open results without a ZIP download', async ({ page }) => {
  await login(page, 'member');
  await page.goto('/jobs/2');
  const general = card(page, 'General');
  const notice = page.getByText('This job is automatically deployed by Chronos Environment Management (CEM).', { exact: true });
  const log = card(page, 'Job log');
  await expect(notice).toBeVisible();
  const order = await general.evaluate((element, text) => {
    const notice = Array.from(document.querySelectorAll('.alert')).find(entry => entry.textContent?.replace(/\s+/g, ' ').trim() === text)!;
    const log = Array.from(document.querySelectorAll('section.card')).find(entry => entry.querySelector('h2')?.textContent?.trim() === 'Job log')!;
    return {
      afterGeneral: !!(element.compareDocumentPosition(notice) & Node.DOCUMENT_POSITION_FOLLOWING),
      beforeLog: !!(notice.compareDocumentPosition(log) & Node.DOCUMENT_POSITION_FOLLOWING),
    };
  }, 'This job is automatically deployed by Chronos Environment Management (CEM).');
  expect(order).toEqual({ afterGeneral: true, beforeLog: true });
  await expect(log).toBeVisible();
  const actions = page.getByLabel('Job actions', { exact: true });
  await expect(actions.getByRole('link', { name: 'Download result ZIP', exact: true })).toHaveCount(0);
  const results = actions.getByRole('button', { name: 'Results', exact: true });
  await expect(results).toBeEnabled();
  await expect(page.locator('app-results-explorer:visible')).toHaveCount(0);
  await results.click();
  const dialog = page.getByRole('dialog', { name: 'Job results', exact: true });
  await expect(dialog).toBeVisible();
  await expect(dialog.getByRole('status').filter({ hasText: /^\s*0 of 1 jobs finished\./ })).toBeVisible();
  await expect(dialog.getByRole('link', { name: 'Download raw results CSV', exact: true })).toBeVisible();
  await expect(dialog.getByRole('link', { name: 'Download result archives', exact: true })).toHaveAttribute('aria-disabled', 'true');
  await dialog.getByRole('button', { name: 'Close results', exact: true }).click();
  await expect(dialog).not.toBeVisible();
  await expect(page.locator('app-results-explorer')).toHaveCount(0);
  await expect(results).toBeFocused();
  await fitsViewport(page);
});

test('JOB-14: job results modal preserves configurations, charts, filtering, sorting and all result exports', async ({ page }) => {
  await login(page);
  let resultRequests = 0;
  page.on('request', request => { if (new URL(request.url()).pathname === '/api/ui/jobs/5/results') resultRequests++; });
  await page.goto('/jobs/5');
  const actions = page.getByLabel('Job actions', { exact: true });
  const results = actions.getByRole('button', { name: 'Results', exact: true });
  const zip = actions.getByRole('link', { name: 'Download result ZIP', exact: true });
  await expect(zip).toBeVisible();
  expect(await zip.evaluate(element => element.nextElementSibling?.textContent?.trim())).toBe('Results');
  await expect(page.locator('app-results-explorer:visible')).toHaveCount(0);
  expect(resultRequests, 'Results load only when the modal opens.').toBe(0);
  const description = page.getByLabel('Description', { exact: true });
  await description.fill('Unsubmitted job description survives results review.');
  await results.click();
  const dialog = page.getByRole('dialog', { name: 'Job results', exact: true });
  await expect(dialog).toBeVisible();
  const explorer = dialog.locator('app-results-explorer');
  await expect(explorer.getByRole('status').filter({ hasText: /^\s*1 of 1 jobs finished\./ })).toBeVisible();
  const configuration = explorer.getByLabel('Result configuration', { exact: true });
  await expect(configuration).toHaveValue('system-1');
  await expect(configuration.locator('option:checked')).toHaveText('Fixture Results');
  await explorer.getByLabel('Refresh automatically', { exact: true }).uncheck();
  const configured = page.waitForResponse(response => {
    const url = new URL(response.url());
    return url.pathname === '/api/ui/jobs/5/results' && url.searchParams.get('configurationId') === 'system-1';
  });
  await configuration.selectOption('system-1');
  expect((await configured).ok()).toBe(true);
  const charts = explorer.locator('app-result-chart');
  expect(await charts.count()).toBeGreaterThan(0);
  await expect(charts.first().getByRole('button', { name: 'Download PNG', exact: true })).toBeEnabled();
  const raw = explorer.getByRole('table').filter({ has: page.getByRole('columnheader', { name: 'Result metrics', exact: true }) });
  await expect(raw).toContainText('metric');
  const search = explorer.getByLabel('Search results or parameters', { exact: true });
  await search.fill('missing-result');
  await expect(raw).toContainText('No jobs match your search.');
  await search.fill('');
  await expect(raw).toContainText('metric');
  await raw.getByRole('button', { name: 'Job', exact: true }).click();
  await expect(raw.getByRole('columnheader', { name: 'Job', exact: true })).toHaveAttribute('aria-sort', 'ascending');
  await raw.getByRole('button', { name: 'Job', exact: true }).click();
  await expect(raw.getByRole('columnheader', { name: 'Job', exact: true })).toHaveAttribute('aria-sort', 'descending');
  await explorer.getByLabel('Rows per page', { exact: true }).selectOption('10');
  await expect(explorer.getByRole('status').filter({ hasText: /^\s*Showing 1–1 of 1 jobs\s*$/ })).toBeVisible();
  const refreshed = page.waitForResponse(response => new URL(response.url()).pathname === '/api/ui/jobs/5/results');
  await explorer.getByRole('button', { name: 'Refresh results', exact: true }).click();
  expect((await refreshed).ok()).toBe(true);
  for (const [role, name, expected] of [
    ['link', 'Download raw results CSV', 'metric'],
    ['link', 'Download result archives', 'PK'],
    ['button', 'Download chart CSV', 'Value'],
    ['button', 'Download PNG', 'PNG'],
  ] as const) {
    const control = role === 'link' ? explorer.getByRole(role, { name, exact: true }) : charts.first().getByRole(role, { name, exact: true });
    const downloaded = page.waitForEvent('download');
    await control.click();
    const download = await downloaded;
    const bytes = await readFile((await download.path())!);
    if (expected === 'PK') expect(bytes.subarray(0, 2).toString()).toBe('PK');
    else if (expected === 'PNG') expect(bytes.subarray(0, 8).equals(Buffer.from([137, 80, 78, 71, 13, 10, 26, 10]))).toBe(true);
    else expect(bytes.toString()).toContain(expected);
  }
  await explorer.getByLabel('Refresh automatically', { exact: true }).check();
  await dialog.press('Escape');
  await expect(dialog).not.toBeVisible();
  await expect(results).toBeFocused();
  await expect(page.locator('app-results-explorer')).toHaveCount(0);
  const closedRequests = resultRequests;
  // Cross one complete result refresh interval while the job's own polling continues.
  await page.waitForTimeout(5_300);
  expect(resultRequests, 'Closing the modal stops result polling.').toBe(closedRequests);
  await expect(description).toHaveValue('Unsubmitted job description survives results review.');
  await expect(page.getByRole('button', { name: 'Save description', exact: true })).toBeEnabled();
  await results.click();
  await expect(dialog.getByRole('heading', { name: 'Raw job results', exact: true })).toBeVisible();
  await dialog.getByRole('button', { name: 'Close results', exact: true }).click();
  await expect(results).toBeFocused();
  await fitsViewport(page);
});
