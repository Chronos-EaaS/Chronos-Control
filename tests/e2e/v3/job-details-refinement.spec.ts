import { observeLive } from './live-helper';
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
    const labels: { text: string; left: number; right: number; lineHeight: number; rects: { left: number; right: number; top: number; height: number }[] }[] = [];
    for (const action of container.querySelectorAll<HTMLElement>(':scope > a, :scope > button')) {
      const rect = action.getBoundingClientRect();
      const row = rows.find(entry => Math.abs(entry.top - rect.top) < 2);
      if (row) { row.left = Math.min(row.left, rect.left); row.right = Math.max(row.right, rect.right); }
      else rows.push({ top: rect.top, left: rect.left, right: rect.right });
      const actionStyle = getComputedStyle(action);
      // Measure rendered text, rather than asserting a particular CSS wrapping rule.
      for (const node of action.childNodes) {
        const text = node.textContent ?? '';
        if (node.nodeType !== Node.TEXT_NODE || !text.trim()) continue;
        const range = document.createRange();
        range.setStart(node, text.search(/\S/));
        range.setEnd(node, text.trimEnd().length);
        labels.push({
          text: text.trim(),
          left: rect.left + parseFloat(actionStyle.paddingLeft) + parseFloat(actionStyle.borderLeftWidth),
          right: rect.right - parseFloat(actionStyle.paddingRight) - parseFloat(actionStyle.borderRightWidth),
          lineHeight: parseFloat(actionStyle.lineHeight) || parseFloat(actionStyle.fontSize) * 1.2,
          rects: Array.from(range.getClientRects()).filter(bounds => bounds.width > 0).map(bounds => ({ left: bounds.left, right: bounds.right, top: bounds.top, height: bounds.height })),
        });
      }
    }
    return { left, right, rows, labels };
  });
  expect(rows.rows.length).toBeGreaterThan(0);
  for (const row of rows.rows) {
    expect(Math.abs(row.left - rows.left), 'Action rows start at the container edge.').toBeLessThanOrEqual(2);
    expect(Math.abs(row.right - rows.right), 'The final action in every row uses the available width.').toBeLessThanOrEqual(2);
  }
  expect(rows.labels.length).toBeGreaterThan(0);
  for (const label of rows.labels) {
    expect(label.rects.length, `${label.text} has visible text.`).toBeGreaterThan(0);
    const tops = label.rects.map(bounds => bounds.top);
    expect(Math.max(...tops) - Math.min(...tops), `${label.text} fits on a single text line.`).toBeLessThanOrEqual(1);
    for (const rect of label.rects) {
      expect(rect.height, `${label.text} fits within one line height.`).toBeLessThanOrEqual(label.lineHeight + 1);
      expect(rect.left, `${label.text} starts inside its action.`).toBeGreaterThanOrEqual(label.left - 1);
      expect(rect.right, `${label.text} ends inside its action.`).toBeLessThanOrEqual(label.right + 1);
    }
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
  const results = actions.getByRole('link', { name: 'Results', exact: true });
  await expect(results).toHaveAttribute('href', '/jobs/2/results');
  await expect(page.locator('app-results-explorer:visible')).toHaveCount(0);
  await results.click();
  await expect(page).toHaveURL(/\/jobs\/2\/results$/);
  await expect(page.getByRole('heading', { name: /^Job results/, level: 1 })).toBeVisible();
  const explorer = page.locator('app-results-explorer');
  await expect(explorer.getByRole('status').filter({ hasText: /^\s*0 of 1 jobs finished\./ })).toBeVisible();
  await expect(explorer.getByRole('link', { name: 'Download raw results CSV', exact: true })).toBeVisible();
  await expect(explorer.getByRole('link', { name: 'Download result archives', exact: true })).toHaveAttribute('aria-disabled', 'true');
  await expect(page.getByRole('navigation', { name: 'Breadcrumb', exact: true }).getByRole('link', { name: /^Job \d+$/ })).toHaveAttribute('href', '/jobs/2');
  await page.getByRole('link', { name: /Job details/ }).click();
  await expect(page).toHaveURL(/\/jobs\/2$/);
  await expect(page.locator('app-results-explorer')).toHaveCount(0);
  await expect(results).toBeVisible();
  await fitsViewport(page);

  // A results deep link enforces the same project scope as the job details.
  const session = (await (await page.request.get('/api/ui/session')).json()).data;
  expect((await page.request.delete('/api/ui/session', {
    headers: { Origin: new URL(page.url()).origin, 'X-CSRF-Token': session.csrfToken },
  })).ok()).toBe(true);
  await expect(page).toHaveURL(/\/login(?:\?|$)/, { timeout: 20_000 });
  await login(page, 'outsider');
  await page.goto('/jobs/2/results');
  await expect(page.getByRole('alert').first()).toBeVisible();
  await expect(page.locator('app-results-explorer')).toHaveCount(0);
  await expect(page.getByRole('heading', { name: /^Job results/, level: 1 })).toHaveCount(0);
});

test('JOB-14: job results page preserves configurations, charts, filtering, sorting and all result exports behind the draft guard', async ({ page }) => {
  const live = observeLive(page);
  await login(page);
  let resultRequests = 0;
  page.on('request', request => { if (new URL(request.url()).pathname === '/api/ui/jobs/5/results') resultRequests++; });
  await page.goto('/jobs/5');
  const actions = page.getByLabel('Job actions', { exact: true });
  const results = actions.getByRole('link', { name: 'Results', exact: true });
  const zip = actions.getByRole('link', { name: 'Download result ZIP', exact: true });
  await expect(zip).toBeVisible();
  expect(await zip.evaluate(element => element.nextElementSibling?.textContent?.trim())).toBe('Results');
  await expect(page.locator('app-results-explorer:visible')).toHaveCount(0);
  await expect(results).toHaveAttribute('href', '/jobs/5/results');
  expect(resultRequests, 'Results load only on the dedicated results route.').toBe(0);
  const persistedJob = (await (await page.request.get('/api/ui/jobs/5')).json()).data;
  const description = page.getByLabel('Description', { exact: true });
  const draft = 'Unsubmitted job description remains until navigation is accepted.';
  await description.fill(draft);
  page.once('dialog', dialog => dialog.dismiss());
  await results.click();
  await expect(page).toHaveURL(/\/jobs\/5$/);
  await expect(description).toHaveValue(draft);
  await expect(page.getByRole('button', { name: 'Save description', exact: true })).toBeEnabled();
  await expect(page.locator('app-results-explorer')).toHaveCount(0);
  expect(resultRequests, 'Cancelling navigation must not mount or read results.').toBe(0);
  page.once('dialog', dialog => dialog.accept());
  await results.click();
  await expect(page).toHaveURL(/\/jobs\/5\/results$/);
  await expect(page.getByRole('heading', { name: /^Job results/, level: 1 })).toBeVisible();
  await expect(page.getByRole('navigation', { name: 'Breadcrumb', exact: true }).getByRole('link', { name: /^Job \d+$/ })).toHaveAttribute('href', '/jobs/5');
  const explorer = page.locator('app-results-explorer');
  await expect(explorer.getByRole('status').filter({ hasText: /^\s*1 of 1 jobs finished\./ })).toBeVisible();
  await expect(explorer.getByRole('link', { name: 'Manage result configurations', exact: true })).toHaveAttribute('href', `/experiments/${persistedJob.experimentId}?tab=results`);
  const configuration = explorer.getByLabel('Result configuration', { exact: true });
  await expect(configuration).toHaveValue('system-1');
  await expect(configuration.locator('option:checked')).toHaveText('Fixture Results');
  await explorer.getByLabel('Refresh automatically', { exact: true }).uncheck();
  const configured = live.next('/jobs/5/results', frame => frame.query['configurationId'] === 'system-1', live.mark());
  await configuration.selectOption('system-1');
  expect((await configured).data.configurationId).toBe('system-1');
  const charts = explorer.locator('app-result-chart');
  expect(await charts.count()).toBeGreaterThan(0);
  await expect(charts.first().getByRole('button', { name: 'Download PNG', exact: true })).toBeEnabled();
  const firstCanvas = await charts.first().locator('canvas').elementHandle(); expect(firstCanvas).not.toBeNull();
  await expect(explorer.locator('.raw-result-table')).toHaveCount(0); await explorer.getByRole('button', { name: 'Raw results', exact: true }).click();
  await expect(explorer.locator('canvas:visible')).toHaveCount(0);
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
  const refreshed = live.next('/jobs/5/results', () => true, live.mark());
  await explorer.getByRole('button', { name: 'Refresh results', exact: true }).click();
  expect((await refreshed).data.totalJobs).toBe(1);
  await explorer.getByRole('button', { name: 'Charts', exact: true }).click();
  await expect(charts.first()).toBeVisible();
  expect(await charts.first().locator('canvas').evaluate((canvas, previous) => canvas === previous, firstCanvas)).toBe(true);
  await expect(configuration).toHaveValue('system-1');
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
  await page.getByRole('link', { name: /Job details/ }).click();
  await expect(page).toHaveURL(/\/jobs\/5$/);
  await expect(page.locator('app-results-explorer')).toHaveCount(0);
  await expect.poll(() => live.active('/jobs/5/results').length).toBe(0);
  await expect.poll(() => live.active('/jobs/5').length).toBe(1);
  expect(resultRequests, 'The results view uses WebSocket exclusively.').toBe(0);
  await expect(description).toHaveValue(persistedJob.description);
  await expect(page.getByRole('button', { name: 'Save description', exact: true })).toBeDisabled();

  // Direct loading uses the same page and starts a fresh explorer view.
  await page.goto('/jobs/5/results');
  await expect(page.getByRole('heading', { name: /^Job results/, level: 1 })).toBeVisible();
  await expect(configuration).toHaveValue('system-1');
  await expect(explorer.locator('.raw-result-table')).toHaveCount(0);
  await explorer.getByRole('button', { name: 'Raw results', exact: true }).click();
  await expect(explorer.getByRole('heading', { name: 'Raw job results', exact: true })).toBeVisible();
  await page.getByRole('navigation', { name: 'Breadcrumb', exact: true }).getByRole('link', { name: /^Job \d+$/ }).click();
  await expect(page).toHaveURL(/\/jobs\/5$/);
  await expect(description).toHaveValue(persistedJob.description);
  expect(resultRequests, 'A direct results page also uses WebSocket exclusively.').toBe(0);
  await fitsViewport(page);
});
