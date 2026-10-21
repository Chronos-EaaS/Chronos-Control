import { execFileSync } from 'node:child_process';
import { performance } from 'node:perf_hooks';
import { v3Stack } from '../paths';
import { test, expect, login, fitsViewport } from './fixture';
import { observeLive } from './live-helper';

interface JobSummary {
  id: number; internalId: number; description: string; evaluationId: number;
  status: number; statusName: string; progress: number; phases: number; currentPhase: number | null;
  setupRequired: boolean; permissions: { canDownload: boolean };
}
interface JobPage { items: JobSummary[]; total: number; page: number; pageSize: number; }

test('EVALUATION-PERF-01: every job in a large evaluation renders from a lean pushed snapshot and live updates preserve its workspace', async ({ page }, testInfo) => {
  test.setTimeout(120_000);
  const fixture = JSON.parse(execFileSync(v3Stack, ['php', '/opt/chronos/tests/fixtures/evaluation-performance.php', 'chronos-v3-ui-regression', 'seed'], { timeout: 30_000, encoding: 'utf8', stdio: 'pipe' }).trim());
  try {
    const live = observeLive(page);
    await login(page);
    // Browser timestamps start at document navigation, independent of Node's
    // WebSocket observer clock. A mutation observer records actual row binding.
    await page.addInitScript(() => {
      const metrics = { documentId: Math.random().toString(36), firstJobBoundAtMs: null as number | null, lastJobBoundAtMs: null as number | null };
      Object.defineProperty(window, '__chronosEvaluationPerformance', { value: metrics });
      const observer = new MutationObserver(() => {
        if (metrics.firstJobBoundAtMs === null && document.querySelector('a[href="/jobs/100001"]')) metrics.firstJobBoundAtMs = performance.now();
        if (metrics.lastJobBoundAtMs === null && document.querySelector('a[href="/jobs/101001"]')) metrics.lastJobBoundAtMs = performance.now();
        if (metrics.firstJobBoundAtMs !== null && metrics.lastJobBoundAtMs !== null) observer.disconnect();
      });
      observer.observe(document, { childList: true, subtree: true });
    });
    const httpReads: string[] = [];
    let documentNavigations = 0;
    page.on('framenavigated', frame => { if (frame === page.mainFrame()) ++documentNavigations; });
    page.on('request', request => {
      const path = new URL(request.url()).pathname;
      if (request.method() === 'GET' && /^\/api\/ui\/(?:evaluations\/1001(?:\/jobs)?|jobs\/10000[12])$/.test(path)) httpReads.push(path);
    });
    const navigationStartedAt = performance.now();
    let snapshotReceivedAfterNavigationMs = 0;
    const initialData = live.next<JobPage>('/evaluations/1001/jobs', snapshot => snapshot.query.view === 'summary' && snapshot.query.pageSize === 'all', live.mark()).then(snapshot => {
      snapshotReceivedAfterNavigationMs = performance.now() - navigationStartedAt;
      return snapshot;
    });
    await page.goto('/evaluations/1001');
    const initial = await initialData;
    expect(initial.data).toMatchObject({ total: 1001, page: 1, pageSize: 1001 });
    expect(initial.data.items.map(job => job.id)).toEqual(Array.from({ length: 1001 }, (_, index) => 100001 + index));
    expect(initial.data.items[0]).toMatchObject({ statusName: 'finished', progress: 0 });
    expect(initial.data.items[1]).toMatchObject({ statusName: 'running', currentPhase: 3, progress: 50 });
    const payloadBytes = Buffer.byteLength(JSON.stringify(initial.data));
    expect(payloadBytes, 'A summary frame must not resend megabytes of stored configuration/results.').toBeLessThan(400_000);
    expect(initial.data.items.some(job => 'configuration' in job || 'result' in job || 'owner' in job)).toBe(false);
    const table = page.getByRole('table', { name: 'Evaluation jobs', exact: true });
    await expect(table.locator('tbody tr')).toHaveCount(1001);
    const allRowsBoundAfterNavigationMs = performance.now() - navigationStartedAt;
    await expect(table.locator('tbody tr').first().getByRole('link', { name: '1', exact: true })).toHaveAttribute('href', '/jobs/100001');
    await expect(table.locator('tbody tr').last().getByRole('link', { name: '1001', exact: true })).toHaveAttribute('href', '/jobs/101001');
    await table.locator('tbody tr').last().scrollIntoViewIfNeeded();
    await expect(table.locator('tbody tr').last()).toBeVisible();
    const lastRowReachableAfterNavigationMs = performance.now() - navigationStartedAt;
    await expect(table.locator('tbody tr').first().getByRole('progressbar')).toHaveAttribute('aria-valuenow', '100');
    const runningRow = table.locator('tbody tr').filter({ has: page.locator('a[href="/jobs/100002"]') });
    await expect(runningRow.getByRole('progressbar')).toHaveAttribute('aria-valuenow', '50');
    await expect(runningRow.locator('.job-stage')).toHaveText('Execute');
    await expect(page.getByLabel('Rows per page', { exact: true })).toHaveCount(0);
    await expect(page.getByRole('button', { name: 'Previous page', exact: true })).toHaveCount(0);
    await expect(page.getByRole('button', { name: 'Next page', exact: true })).toHaveCount(0);
    const binding = await page.evaluate(() => (window as unknown as { __chronosEvaluationPerformance: { documentId: string; firstJobBoundAtMs: number | null; lastJobBoundAtMs: number | null } }).__chronosEvaluationPerformance);
    expect(binding.firstJobBoundAtMs).not.toBeNull(); expect(binding.lastJobBoundAtMs).not.toBeNull();

    const draft = page.locator('#evaluation-description');
    await draft.fill('Unsaved large-evaluation draft');
    const sorted = live.next<JobPage>('/evaluations/1001/jobs', snapshot => snapshot.query.sort === '-internalId', live.mark());
    await table.getByRole('button', { name: 'Sort by ID', exact: true }).click();
    expect((await sorted).data.items[0].internalId).toBe(1001);
    await expect(table.locator('tbody tr').first()).toContainText('Performance job 1001');
    const filtered = live.next<JobPage>('/evaluations/1001/jobs', snapshot => snapshot.query.status === 'running' && snapshot.query.sort === '-internalId', live.mark());
    await page.getByRole('combobox', { name: 'Status', exact: true }).selectOption('running');
    expect((await filtered).data.items).toHaveLength(500);
    await expect(table.locator('tbody tr')).toHaveCount(500);
    const searched = live.next<JobPage>('/evaluations/1001/jobs', snapshot => snapshot.query.search === 'Performance job' && snapshot.query.status === 'running', live.mark());
    await page.getByRole('searchbox', { name: 'Search jobs', exact: true }).fill('Performance job');
    await page.getByRole('searchbox', { name: 'Search jobs', exact: true }).press('Tab');
    expect((await searched).data.items).toHaveLength(500);
    await expect(table.locator('tbody tr')).toHaveCount(500);

    const tableHandle = await table.elementHandle();
    const runningHandle = await runningRow.elementHandle();
    const identity = (await (await page.request.get('/api/ui/session')).json()).data;
    const changed = live.next<JobPage>('/evaluations/1001/jobs', snapshot => snapshot.data.items.some(job => job.id === 100002 && job.progress === 75), live.mark());
    const updateStartedAt = performance.now();
    const write = await page.request.patch('/api/ui/jobs/100002', {
      headers: { Origin: new URL(page.url()).origin, 'X-CSRF-Token': identity.csrfToken },
      data: { progress: 75 },
    });
    expect(write.ok()).toBe(true);
    const pushed = await changed;
    const updateSnapshotMs = performance.now() - updateStartedAt;
    expect(pushed.query).toEqual({ status: 'running', view: 'summary', pageSize: 'all', sort: '-internalId', search: 'Performance job' });
    expect(pushed.data.items).toHaveLength(500);
    await expect(runningRow.getByRole('progressbar')).toHaveAttribute('aria-valuenow', '55');
    const updateBoundMs = performance.now() - updateStartedAt;
    expect(await tableHandle!.evaluate(element => element.isConnected && element === document.querySelector('table'))).toBe(true);
    expect(await runningHandle!.evaluate(element => element.isConnected && element.querySelector('a[href="/jobs/100002"]') !== null)).toBe(true);
    await expect(draft).toHaveValue('Unsaved large-evaluation draft');
    await expect(page.getByRole('combobox', { name: 'Status', exact: true })).toHaveValue('running');
    await expect(page.getByRole('searchbox', { name: 'Search jobs', exact: true })).toHaveValue('Performance job');
    await expect(table.getByRole('columnheader').first()).toHaveAttribute('aria-sort', 'descending');
    expect(await page.evaluate(() => (window as unknown as { __chronosEvaluationPerformance: { documentId: string } }).__chronosEvaluationPerformance.documentId)).toBe(binding.documentId);
    expect(documentNavigations, 'Live snapshots must retain the existing document.').toBe(1);
    expect(httpReads, 'Evaluation identity, job snapshots and their updates must use the existing socket.').toEqual([]);
    expect(live.active('/evaluations/1001/jobs')).toHaveLength(1);

    // Remove view controls again and prove the complete collection still exists
    // after a pushed update; no implicit paging or client truncation is allowed.
    const unsearched = live.next<JobPage>('/evaluations/1001/jobs', snapshot => snapshot.query.search === '' && snapshot.query.status === 'running', live.mark());
    await page.getByRole('searchbox', { name: 'Search jobs', exact: true }).fill('');
    await page.getByRole('searchbox', { name: 'Search jobs', exact: true }).press('Tab');
    await unsearched;
    const unfiltered = live.next<JobPage>('/evaluations/1001/jobs', snapshot => snapshot.query.status === 'all' && snapshot.query.search === '', live.mark());
    await page.getByRole('combobox', { name: 'Status', exact: true }).selectOption('all');
    expect((await unfiltered).data.items).toHaveLength(1001);
    await expect(table.locator('tbody tr')).toHaveCount(1001);
    await expect(table.locator('tbody tr').first()).toContainText('Performance job 1001');
    await expect(table.locator('tbody tr').last()).toContainText('Performance job 0001');
    await fitsViewport(page);

    await testInfo.attach('evaluation-performance.json', {
      contentType: 'application/json',
      body: Buffer.from(JSON.stringify({
        project: testInfo.project.name, browser: testInfo.project.use.browserName, fixture,
        initial: { jobs: initial.data.items.length, payloadBytes, snapshotReceivedAfterNavigationMs, allRowsBoundAfterNavigationMs, lastRowReachableAfterNavigationMs, browserBinding: binding },
        update: { jobs: pushed.data.items.length, snapshotMs: updateSnapshotMs, boundMs: updateBoundMs, rawProgress: 75, overallProgress: 55 },
        noReload: documentNavigations === 1, httpEvaluationReads: httpReads, activeJobsSubscriptions: live.active('/evaluations/1001/jobs').length,
      }, null, 2)),
    });
  } finally {
    // Use the existing fixed-project reset, including when an assertion fails.
    await page.close();
    execFileSync(v3Stack, ['reset'], { timeout: 30_000, stdio: 'pipe' });
  }
});
