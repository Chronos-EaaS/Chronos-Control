import { test, expect, login, fitsViewport } from './fixture';

test('EXPERIMENT-04/08: persist a default environment and use it when opening a new evaluation', async ({ page }) => {
  await login(page); await page.goto('/experiments/1');
  const environment = page.getByLabel('Default environment', { exact: true }); await expect(environment).toHaveValue('system-local');
  await environment.selectOption('cem-fixture');
  const saved = page.waitForResponse(response => response.url().endsWith('/api/ui/experiments/1') && response.request().method() === 'PATCH');
  await page.getByRole('button', { name: 'Save experiment', exact: true }).click(); expect((await saved).status()).toBe(200);
  expect((await (await page.request.get('/api/ui/experiments/1')).json()).data.defaultEnvironment).toBe('cem-fixture');
  await page.reload(); await expect(environment).toHaveValue('cem-fixture'); await page.getByRole('button', { name: 'Run evaluation', exact: true }).click();
  const dialog = page.getByRole('dialog', { name: 'Run evaluation', exact: true }); await expect(dialog.getByLabel('Environment', { exact: true })).toHaveValue('cem-fixture');
  await dialog.getByRole('button', { name: 'Cancel', exact: true }).click(); await expect(dialog).not.toBeVisible(); await fitsViewport(page);
});

test('PROJECT-07/09 and EXPERIMENT-03: child grids search, sort, and retain hierarchical identifiers', async ({ page }) => {
  await login(page); await page.goto('/projects/1');
  const experiments = page.locator('app-data-grid').filter({ has: page.getByRole('table', { name: 'Experiments', exact: true }) });
  await expect(experiments.getByRole('link', { name: 'Fixture Experiment', exact: true })).toBeVisible();
  await experiments.getByRole('button', { name: 'Sort by Name', exact: true }).click(); await expect(experiments.locator('th').filter({ has: page.getByRole('button', { name: 'Sort by Name', exact: true }) })).toHaveAttribute('aria-sort', 'ascending');
  await experiments.getByRole('searchbox').fill('missing fixture record'); await expect(experiments).toContainText('No rows match this view.'); await experiments.getByRole('searchbox').fill('');
  const running = page.locator('app-data-grid').filter({ has: page.getByRole('table', { name: 'Currently running evaluations', exact: true }) });
  const starred = page.locator('app-data-grid').filter({ has: page.getByRole('table', { name: 'Starred evaluations', exact: true }) });
  await expect(page.getByRole('table', { name: 'Running and starred evaluations', exact: true })).toHaveCount(0);
  await expect(running.getByRole('link', { name: '1.1', exact: true })).toBeVisible();
  await expect(running.getByRole('link', { name: 'Mixed Evaluation', exact: true })).toBeVisible();
  await expect(running.getByRole('link', { name: 'Agent Contract Evaluation', exact: true })).toBeVisible();
  await expect(running.getByRole('link', { name: 'Complete Evaluation', exact: true })).toHaveCount(0);
  await expect(starred.getByRole('link', { name: '1.2', exact: true })).toBeVisible();
  await expect(starred.getByRole('link', { name: 'Complete Evaluation', exact: true })).toBeVisible();
  await expect(starred.getByRole('link', { name: 'Mixed Evaluation', exact: true })).toHaveCount(0);
  await running.getByRole('searchbox').fill('Fixture Experiment'); await expect(running.getByRole('link', { name: 'Mixed Evaluation', exact: true })).toBeVisible();
  await starred.getByRole('searchbox').fill('missing fixture record'); await expect(starred).toContainText('No rows match this view.');
  await expect(running.getByRole('link', { name: 'Mixed Evaluation', exact: true })).toBeVisible();
  await starred.getByRole('searchbox').fill(''); await expect(starred.getByRole('link', { name: 'Complete Evaluation', exact: true })).toBeVisible();
  await running.getByRole('searchbox').fill('');
  await running.getByRole('link', { name: 'Mixed Evaluation', exact: true }).click();
  const starredUpdate = page.waitForResponse(response => new URL(response.url()).pathname === '/api/ui/evaluations/1' && response.request().method() === 'PATCH');
  await page.getByRole('button', { name: 'Star evaluation', exact: true }).click(); expect((await starredUpdate).ok()).toBeTruthy();
  await page.goto('/projects/1');
  await running.getByLabel('Sort currently running evaluations', { exact: true }).selectOption('starred');
  await expect(running.locator('tbody tr').first()).toContainText('Agent Contract Evaluation');
  await running.getByRole('button', { name: 'Sort currently running evaluations descending', exact: true }).click();
  await expect(running.locator('tbody tr').first()).toContainText('Mixed Evaluation');
  await running.getByRole('searchbox').fill('Not starred');
  await expect(running.getByRole('link', { name: 'Agent Contract Evaluation', exact: true })).toBeVisible();
  await expect(running.getByRole('link', { name: 'Mixed Evaluation', exact: true })).toHaveCount(0);
  await running.getByRole('searchbox').fill('');
  await running.getByLabel('Sort currently running evaluations', { exact: true }).selectOption('experiment');
  await expect(running.getByLabel('Sort currently running evaluations', { exact: true })).toHaveValue('experiment');
  await fitsViewport(page);
});

test('JOB-01/02 and EVALUATION-01: live status retains context and performs real server sorting', async ({ page }) => {
  await login(page); await page.goto('/status');
  const table = page.getByRole('table', { name: 'Evaluation status', exact: true }); await expect(table.getByRole('link', { name: 'Fixture System', exact: true }).first()).toBeVisible();
  for (const [label, key] of [['Experiment', 'experiment'], ['Project', 'project'], ['System', 'system'], ['Status', 'status']] as const) {
    const response = page.waitForResponse(response => new URL(response.url()).pathname === '/api/ui/evaluations' && new URL(response.url()).searchParams.get('sort') === key);
    await table.getByRole('button', { name: `Sort by ${label}`, exact: true }).click(); expect((await response).status()).toBe(200);
  }
  await page.getByRole('button', { name: 'Jobs', exact: true }).click(); const jobs = page.getByRole('table', { name: 'Job status', exact: true });
  await expect(jobs).toContainText('Owner Fixture (owner)'); await expect(jobs.getByRole('link', { name: 'Mixed Evaluation', exact: true }).first()).toBeVisible();
  for (const [label, key] of [['User', 'owner'], ['Type', 'type'], ['System', 'system'], ['Progress', 'progress']] as const) {
    const response = page.waitForResponse(response => new URL(response.url()).pathname === '/api/ui/jobs' && new URL(response.url()).searchParams.get('sort') === key);
    await jobs.getByRole('button', { name: `Sort by ${label}`, exact: true }).click(); expect((await response).status()).toBe(200);
  }
  // Additional context stays sortable after the compact table moves it beneath Evaluation.
  for (const [label, key] of [['Description', 'description'], ['Experiment', 'experiment'], ['Project', 'project']] as const) {
    const response = page.waitForResponse(response => new URL(response.url()).pathname === '/api/ui/jobs' && new URL(response.url()).searchParams.get('sort') === key);
    await page.getByLabel('Sort column', { exact: true }).selectOption(key); expect((await response).status()).toBe(200);
    await expect(page.getByLabel('Sort column', { exact: true })).toHaveValue(key);
    const descending = page.waitForResponse(response => new URL(response.url()).pathname === '/api/ui/jobs' && new URL(response.url()).searchParams.get('sort') === `-${key}`);
    await page.getByLabel('Sort column', { exact: true }).selectOption(`-${key}`); expect((await descending).status()).toBe(200);
    await expect(page.getByLabel('Sort column', { exact: true })).toHaveValue(`-${key}`);
  }
  await page.getByLabel('Search jobs', { exact: true }).fill('Fixture System'); await page.getByLabel('Search jobs', { exact: true }).press('Tab'); await expect(jobs.getByRole('link', { name: 'Fixture System', exact: true }).first()).toBeVisible(); await fitsViewport(page);
});

test('EVALUATION-03/04/10: environment, sortable job grid and completed ZIP are available together', async ({ page }) => {
  await login(page); await page.goto('/evaluations/1'); await expect(page.getByLabel('Environment', { exact: true })).toContainText('System: local');
  const jobs = page.getByRole('table', { name: 'Evaluation jobs', exact: true });
  await expect(page.locator('app-activity')).toHaveCount(0);
  await expect(page.locator('app-results-explorer')).toHaveCount(0);
  await expect(page.getByRole('link', { name: 'Results', exact: true })).toHaveAttribute('href', '/evaluations/1/results');
  await expect(page.getByLabel('Search jobs', { exact: true })).toHaveJSProperty('type', 'search');
  const running = jobs.locator('tbody tr').filter({ has: page.locator('.state-running') });
  await expect(running.locator('.job-stage')).toHaveText('Execute');
  await expect(running.getByRole('progressbar')).toHaveAttribute('aria-valuetext', 'Execute: 50 percent');
  for (const [label, key] of [['Description', 'description'], ['Status', 'status'], ['Stage', 'phase'], ['Progress', 'progress']] as const) {
    const response = page.waitForResponse(response => new URL(response.url()).pathname === '/api/ui/evaluations/1/jobs' && new URL(response.url()).searchParams.get('sort') === key);
    await jobs.getByRole('button', { name: `Sort by ${label}`, exact: true }).click(); expect((await response).status()).toBe(200);
  }
  // The configured default is five rows; ascending progress places the finished
  // sixth job on page two. Use the real status filter to select completed rows.
  const finished = page.waitForResponse(response => new URL(response.url()).pathname === '/api/ui/evaluations/1/jobs' && new URL(response.url()).searchParams.get('status') === 'finished');
  await page.getByLabel('Status', { exact: true }).selectOption('finished'); expect((await finished).status()).toBe(200);
  const download = jobs.getByRole('link', { name: 'Download result ZIP for job 5', exact: true });
  await expect(download).toHaveAttribute('href', '/api/ui/jobs/5/download');
  const response = await page.request.get('/api/ui/jobs/5/download'); expect(response.ok()).toBeTruthy(); expect(response.headers()['content-type']).toContain('zip'); expect((await response.body()).subarray(0, 2).toString()).toBe('PK');
  const evaluation = (await (await page.request.get('/api/ui/evaluations/1')).json()).data; expect(evaluation.experimentInternalId).toBe(1); expect(evaluation.environments).toEqual(['cem-fixture', 'system-local']); await fitsViewport(page);
});
