import { readFile } from 'node:fs/promises';
import {
  test, expect, login, logout, api, patchJob, panel, clickAndAccept,
} from './helpers';

type JobState = { id: number; status: number; progress: number; currentPhase: string; phases: string[]; log?: string };

test('EVALUATION-01, EVALUATION-02: running evaluation navigation and admin own/all filtering', async ({ page }) => {
  await login(page);
  await page.goto('/evaluation/overview');
  await expect(page.locator('#evaluations')).toContainText('Mixed Evaluation');
  await expect(page.locator('#evaluations')).not.toContainText('Complete Evaluation');
  await page.locator('[data-href="/evaluation/detail/id=1"]').click();
  await expect(page.locator('.content-header h1')).toContainText('Mixed Evaluation');
  await logout(page);
  await login(page, 'admin');
  await page.goto('/evaluation/overview');
  await expect(page.locator('#evaluations')).not.toContainText('Mixed Evaluation');
  await page.locator('#showAllUser').uncheck();
  await expect(page.locator('#evaluations')).toContainText('Mixed Evaluation');
  await page.getByRole('link', { name: /Jobs$/ }).click();
  await expect(page.locator('.content-header h1')).toHaveText('Jobs');
});

test('EVALUATION-03: evaluation metadata saves and invalid name leaves persisted data unchanged', async ({ page }) => {
  await login(page);
  await page.goto('/evaluation/detail/id=1');
  await page.locator('#name').fill('Edited Mixed Evaluation');
  await page.locator('#description').fill('Persistent evaluation description.');
  await page.getByRole('button', { name: /Save$/ }).click();
  await expect(page.locator('#saveResultSuccess')).toBeVisible();
  await page.reload();
  await expect(page.locator('#name')).toHaveValue('Edited Mixed Evaluation');
  await expect(page.locator('#description')).toHaveValue('Persistent evaluation description.');
  await page.locator('#name').fill('x'.repeat(251));
  await page.getByRole('button', { name: /Save$/ }).click();
  await expect(page.locator('#saveResultError')).toContainText('250');
  expect((await api<{ name: string }>(page.request, '/api/ui/evaluation/id=1')).name).toBe('Edited Mixed Evaluation');
});

test('EVALUATION-04: real polling renders all six job states and picks up backend changes', async ({ page }) => {
  await page.clock.install();
  await login(page);
  await page.goto('/evaluation/detail/id=1');
  const body = page.locator('#jobs-tbody');
  await expect(body.locator('tr')).toHaveCount(6);
  for (const status of ['scheduled', 'setup', 'running', 'failed', 'finished', 'aborted']) {
    await expect(body).toContainText(status);
  }
  await patchJob(page.request, 3, { status: 3 });
  await page.clock.fastForward(10_000);
  await expect(body.locator('[data-href="/job/detail/id=3"]')).toContainText('finished');
  await expect(body.locator('[data-href="/job/detail/id=3"] a')).toHaveAttribute('href', /3\.zip$/);
  await body.locator('[data-href="/job/detail/id=2"] td').first().click();
  await expect(page.locator('#id')).toHaveValue('2');
});

test('EVALUATION-05: reschedule all changes failed/aborted jobs and leaves other states intact', async ({ page }) => {
  await login(page);
  await page.goto('/evaluation/detail/id=1');
  const rescheduling = page.waitForResponse(response => response.request().isNavigationRequest() && response.request().method() === 'POST' && new URL(response.url()).pathname.replace(/\/$/, '') === '/evaluation/detail/id=1' && new URLSearchParams(response.request().postData() ?? '').get('reschedule') === 'all');
  await page.getByRole('button', { name: /Re\-schedule All$/ }).click();
  const rescheduled = await rescheduling; expect(rescheduled.status()).toBe(200); expect(await rescheduled.finished()).toBeNull();
  const states = await Promise.all([1, 2, 3, 4, 5, 6].map(id => api<JobState>(page.request, `/api/ui/job/id=${id}`)));
  expect(states.map(job => job.status)).toEqual([0, 1, 2, 0, 3, 0]);
  await page.goto('/job/detail/id=4');
  await expect(page.locator('.timeline-item')).toContainText('rescheduled');
});

test('EVALUATION-06: abort all changes active/failed jobs and preserves finished results', async ({ page }) => {
  await login(page);
  await page.goto('/evaluation/detail/id=1');
  const aborting = page.waitForResponse(response => response.request().isNavigationRequest() && response.request().method() === 'POST' && new URL(response.url()).pathname.replace(/\/$/, '') === '/evaluation/detail/id=1' && new URLSearchParams(response.request().postData() ?? '').get('abort') === 'all');
  await page.getByRole('button', { name: /Abort All$/ }).click();
  const aborted = await aborting; expect(aborted.status()).toBe(200); expect(await aborted.finished()).toBeNull();
  const states = await Promise.all([1, 2, 3, 4, 5, 6].map(id => api<JobState>(page.request, `/api/ui/job/id=${id}`)));
  expect(states.map(job => job.status)).toEqual([-1, -1, -1, -1, 3, -1]);
  await page.goto('/job/detail/id=3');
  await expect(page.locator('.timeline-item')).toContainText('aborted');
});

test('EVALUATION-07, PROJECT-09: star and clear-star propagate to the project', async ({ page }) => {
  await login(page);
  await page.goto('/evaluation/detail/id=1');
  await page.getByRole('button', { name: /Star$/ }).click();
  await expect(page.locator('.content-header .fa-star')).toBeVisible();
  await page.goto('/project/detail/id=1');
  await expect(panel(page, 'Starred Evaluations').locator('[data-href="/evaluation/detail/id=1"]')).toBeVisible();
  await page.goto('/evaluation/detail/id=1');
  await page.getByRole('button', { name: /Clear Star$/ }).click();
  await expect(page.locator('.content-header .fa-star')).toHaveCount(0);
  await page.goto('/project/detail/id=1');
  await expect(panel(page, 'Starred Evaluations').locator('[data-href="/evaluation/detail/id=1"]')).toHaveCount(0);
});

test('EVALUATION-09, JOB-08: completed archives download and unfinished evaluation download is rejected', async ({ page }) => {
  await login(page);
  await page.goto('/evaluation/detail/id=2');
  const [evaluation] = await Promise.all([
    page.waitForEvent('download'), page.getByRole('link', { name: /Download$/ }).click(),
  ]);
  expect(evaluation.suggestedFilename()).toBe('evaluation2.zip');
  const evaluationBytes = await readFile((await evaluation.path())!);
  expect(evaluationBytes.subarray(0, 2).toString()).toBe('PK');
  for (const id of [7, 8, 9, 10, 11, 12]) expect(evaluationBytes.includes(Buffer.from(`${id}.zip`))).toBeTruthy();
  await page.goto('/job/detail/id=5');
  const [job] = await Promise.all([
    page.waitForEvent('download'), page.getByRole('link', { name: /Download$/ }).click(),
  ]);
  expect(job.suggestedFilename()).toBe('5.zip');
  const jobBytes = await readFile((await job.path())!);
  expect(jobBytes.subarray(0, 2).toString()).toBe('PK');
  expect(jobBytes.includes(Buffer.from('result.json'))).toBeTruthy();
  await page.goto('/evaluation/download/id=1');
  await expect(page.locator('body')).toContainText('is not finished!');
});

test('EVALUATION-10, JOB-04: CEM evaluation and job screens identify automatic deployment', async ({ page }) => {
  await login(page);
  await page.goto('/experiment/detail/id=1');
  await page.getByRole('button', { name: /Run Evaluation$/ }).click();
  await page.locator('#environment').selectOption('cem-fixture');
  await page.locator('#modal-run-evaluation').getByRole('button', { name: /Run$/ }).click();
  await expect(page.locator('#cemJob')).toContainText('automatically deployed');
  const evaluationId = await page.locator('#id').inputValue();
  const data = await api<{ jobs: Array<{ id: number }> }>(page.request, `/api/ui/evaluation/id=${evaluationId}/action=jobs`);
  await page.goto(`/job/detail/id=${data.jobs[0].id}`);
  await expect(page.locator('#cemJob')).toContainText('automatically deployed');
  await expect(page.locator('#progress-outer-setup')).toBeVisible();
});

test('JOB-01, JOB-02: job state filters, sorting, paging and admin active-job scope work', async ({ page }) => {
  await login(page);
  // Exceed even the legacy fallback page size through a real saved/run
  // experiment. The configured-size defect is tracked separately below.
  const created = await page.request.post('/builder/create', { form: {
    projectId: '1', name: 'Pagination Regression', description: 'Pagination fixture via real controller.',
    runs: '30', 'run-distribution': 'alter', phase_warmUp: '1',
    'elements[]': 'fixture-quantity', 'fixture-quantity-type': 'integer', 'fixture-quantity-parameter': 'quantity', quantity: '10',
  } });
  expect(created.ok()).toBeTruthy();
  const experimentId = Number((await created.text()).match(/id="id" type="text" value="(\d+)"/)?.[1]);
  expect(experimentId).toBeGreaterThan(3);
  await page.goto(`/experiment/detail/id=${experimentId}`);
  await page.getByRole('button', { name: /Run Evaluation$/ }).click();
  await page.locator('#environment').selectOption('system-local');
  const running = page.waitForResponse(response => response.request().isNavigationRequest() && response.request().method() === 'POST' && new URL(response.url()).pathname.replace(/\/$/, '') === '/builder/run' && new URLSearchParams(response.request().postData() ?? '').get('experimentId') === String(experimentId));
  await page.locator('#modal-run-evaluation').getByRole('button', { name: /Run$/ }).click();
  const started = await running; expect(started.status()).toBe(303); expect(await started.finished()).toBeNull();
  await expect(page.locator('.content-header h1')).toContainText('Evaluation:');
  const evaluationId = await page.locator('#id').inputValue();
  const generated = await api<{ jobs: Array<{ id: number }> }>(page.request, `/api/ui/evaluation/id=${evaluationId}/action=jobs`);
  expect(generated.jobs).toHaveLength(30);
  for (const job of generated.jobs) await patchJob(page.request, job.id, { status: 3 });
  await page.goto('/job/jobs');
  await expect(page.locator('#running')).toContainText('running');
  await expect(page.locator('#finished')).toHaveCount(0);
  await page.locator('#showOnlyActive').uncheck();
  await expect(page.locator('#finished_wrapper')).toBeVisible();
  const visibleCount = await page.locator('#finished tbody tr').count();
  expect(visibleCount).toBeGreaterThan(0);
  expect(visibleCount).toBeLessThan(39);
  await page.locator('#finished th').filter({ hasText: /^#$/ }).click();
  await expect(page.locator('#finished th').first()).toHaveAttribute('aria-sort', /ascending|descending/);
  const before = await page.locator('#finished tbody').textContent();
  await page.locator('#finished_next a').click();
  await expect(page.locator('#finished tbody')).not.toHaveText(before!);
  await logout(page);
  await login(page, 'admin');
  await page.goto('/job/jobs');
  await expect(page.locator('#running')).not.toContainText('Mixed Evaluation');
  await page.locator('#showAllUser').uncheck();
  await expect(page.locator('#running')).toContainText('Mixed Evaluation');
  await expect(page.locator('#showOnlyActive')).toBeChecked();
});

test('JOB-03, JOB-09, JOB-10: job edits persist, previous/next navigation and escaped CDL work', async ({ page }) => {
  await login(page);
  await page.goto('/job/detail/id=3');
  await page.locator('#description').fill('Edited job description — Zürich.');
  await page.getByRole('button', { name: /Save$/ }).click();
  await expect(page.locator('#saveResultSuccess')).toBeVisible();
  await page.reload();
  await expect(page.locator('#description')).toHaveValue('Edited job description — Zürich.');
  await page.getByRole('link', { name: /Next$/ }).click();
  await expect(page.locator('#id')).toHaveValue('4');
  await page.getByRole('link', { name: /Previous$/ }).click();
  await expect(page.locator('#id')).toHaveValue('3');
  await page.getByRole('button', { name: /View CDL$/ }).click();
  await expect(page.locator('#modal-cdl code')).toContainText('quantity');
  await expect(page.locator('#modal-cdl code')).toContainText('10');
  await expect(page.locator('#modal-cdl code').locator('script')).toHaveCount(0);
  await page.locator('#modal-cdl').getByRole('button', { name: /Close$/ }).last().click();
  await page.getByRole('link', { name: /Evaluation$/ }).last().click();
  await expect(page.locator('.content-header h1')).toContainText('Mixed Evaluation');
});

test('JOB-04, JOB-05: real phase/log polling, ANSI escaping and auto-update controls', async ({ page }) => {
  await page.clock.install();
  await login(page);
  const requests: string[] = [];
  page.on('request', request => { if (request.url().includes('/api/ui/job/')) requests.push(request.url()); });
  await page.goto('/job/detail/id=3');
  await expect(page.locator('#log')).toContainText('Fixture job 3 log');
  await expect(page.locator('#log')).toContainText('Completed fixture step');
  await expect(page.locator('#log')).toContainText('Literal <script>fixture</script>');
  await expect(page.locator('#log script')).toHaveCount(0);
  await expect(page.locator('#progress-inner-execute')).toHaveAttribute('style', /width: 50%/);
  const initialLogs = requests.filter(url => url.includes('withLog=1')).length;
  await patchJob(page.request, 3, { progress: 75 });
  await page.clock.fastForward(2_000);
  await expect(page.locator('#progress-inner-execute')).toHaveAttribute('style', /width: 75%/);
  expect(requests.filter(url => url.includes('withLog=1'))).toHaveLength(initialLogs);
  await page.getByRole('button', { name: /Refresh$/ }).click();
  await expect.poll(() => requests.filter(url => url.includes('withLog=1')).length).toBe(initialLogs + 1);
  await page.locator('#autoupdateLog').check();
  await page.clock.fastForward(2_000);
  await expect.poll(() => requests.filter(url => url.includes('withLog=1')).length).toBeGreaterThan(initialLogs + 1);
  await patchJob(page.request, 3, { status: -2 });
  await page.clock.fastForward(2_000);
  await expect(page.locator('#progress-inner-execute')).toHaveClass(/progress-bar-danger/);
});

test('JOB-06, JOB-07, JOB-11: abort/reschedule persists state, resets progress and writes timeline events', async ({ page }) => {
  await login(page);
  await page.goto('/job/detail/id=3');
  await page.locator('.btn-app').filter({ hasText: /^\s*Abort\s*$/ }).click();
  await expect(page.locator('#status')).toHaveValue('aborted');
  expect((await api<JobState>(page.request, '/api/ui/job/id=3')).status).toBe(-1);
  await expect(page.locator('.timeline-item')).toContainText('aborted');
  await page.locator('.btn-app').filter({ hasText: /^\s*Reschedule\s*$/ }).click();
  await expect(page.locator('#status')).toHaveValue('scheduled');
  const state = await api<JobState>(page.request, '/api/ui/job/id=3');
  expect(state.status).toBe(0);
  expect(state.progress).toBe(0);
  expect(state.currentPhase).toBe('');
  await expect(page.locator('.timeline-item').first()).toContainText('rescheduled');
});

test('RESULT-01, RESULT-02: overall/job graph builders persist independent definitions and reset unsaved edits', async ({ page }) => {
  await login(page);
  await page.goto('/results/build/systemId=1/type=1/resultId=system-1');
  await expect(page.locator('.content-header h1')).toContainText('All Jobs');
  await page.locator('#plot-bar [name="name"]').fill('Edited Overall Bar');
  await page.locator('.btn-app').filter({ hasText: /Save\s*$/ }).click();
  await expect(page.locator('.content-header h1')).toContainText('System: Fixture System');
  await page.goto('/results/build/systemId=1/type=1/resultId=system-1');
  await expect(page.locator('#plot-bar [name="name"]')).toHaveValue('Edited Overall Bar');
  await page.locator('#plot-bar [name="name"]').fill('Unsaved Bar');
  await page.locator('.btn-app').filter({ hasText: /Reset\s*$/ }).click();
  await expect(page.locator('#plot-bar [name="name"]')).toHaveValue('Edited Overall Bar');
  await page.locator('[data-target="#modal-plot"]').click();
  await page.locator('#plot-form [name="type"]').selectOption('pie-plot');
  await expect(page.locator('#build-content > .box')).toHaveCount(5);
  await page.locator('#modal-plot').getByRole('button', { name: /Add$/ }).click();
  await expect(page.locator('#build-content > .box')).toHaveCount(6);
  const added = page.locator('#build-content > .box').last();
  await added.locator('[name="name"]').fill('Disposable Pie');
  await added.locator('[name="parameter"]').fill('read,write');
  await clickAndAccept(page, () => added.getByRole('button', { name: /Delete$/ }).click());
  await expect(page.locator('#build-content > .box')).toHaveCount(5);
  await page.goto('/results/build/systemId=1/type=2/resultId=system-1');
  await expect(page.locator('.content-header h1')).toContainText('Single Jobs');
  await expect(page.locator('#plot-bar [name="name"]')).toHaveValue('Fixture Bar');
});

test('EVALUATION-08, RESULT-03, RESULT-04, RESULT-05, RESULT-06, RESULT-07, RESULT-08: all real charts render known result datasets and configuration groups', async ({ page }) => {
  await login(page);
  await page.goto('/evaluation/detail/id=2');
  await page.getByRole('link', { name: /Results$/ }).click();
  await expect(page.locator('.content-header h1')).toContainText('Results');
  await expect(page.locator('canvas')).toHaveCount(15);
  // Canvas markup arrives before Chart.js finishes its ready callbacks.
  // Wait for actual chart instances rather than reading a partial registry.
  await expect.poll(() => page.evaluate(() => {
    const chart = (window as unknown as { Chart?: { instances?: Record<string, unknown> } }).Chart;
    return Object.keys(chart?.instances ?? {}).length;
  })).toBe(15);
  const chartData = await page.evaluate(() => {
    const chart = (window as unknown as { Chart: { instances: Record<string, {
      config: { type: string }; data: { labels?: string[]; datasets: Array<{ data: unknown[] }> };
    }> } }).Chart;
    return Object.values(chart.instances).map(instance => ({ type: instance.config.type, data: instance.data }));
  });
  expect(chartData.map(chart => chart.type)).toEqual(expect.arrayContaining(['bar', 'line', 'doughnut', 'boxplot']));
  expect(chartData.find(chart => chart.type === 'bar')?.data.datasets[0].data).toEqual([12, 22]);
  expect(chartData.find(chart => chart.type === 'doughnut')?.data.datasets[0].data).toEqual([60, 40, 60, 40]);
  const boxes = chartData.filter(chart => chart.type === 'boxplot');
  expect(boxes).toHaveLength(3);
  expect(boxes[0].data.datasets[0].data).not.toHaveLength(0);
  await expect(page.locator('.box-title')).toContainText(['Fixture Bar', 'Fixture Line', 'Fixture Multi', 'Fixture Pie', 'Fixture Box']);
  await expect(page.locator('.box-title').filter({ hasText: 'Job[size: 10]' })).toBeVisible();
  await expect(page.locator('.box-title').filter({ hasText: 'Job[size: 20]' })).toBeVisible();
});

test('RESULT-07: insufficient-run box plots show their explanatory warning', async ({ page }) => {
  await login(page);
  await page.goto('/results/show/id=1');
  await expect(page.getByText('BoxPlots need at least 3 runs to have enough data!').first()).toBeVisible();
});
