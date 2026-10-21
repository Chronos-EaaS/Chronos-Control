import { observeLive } from './live-helper';
import { readFile } from 'node:fs/promises';
import { test, expect, login, fitsViewport, type Page, type Locator } from './fixture';

function section(page: Page, name: string): Locator {
  return page.locator('section.card').filter({ has: page.getByRole('heading', { name, exact: true }) });
}
async function data(page: Page, path: string): Promise<any> {
  const response = await page.request.get(`/api/ui${path}`); expect(response.ok(), path).toBeTruthy(); return (await response.json()).data;
}
async function systemTab(page: Page, name: string): Promise<void> {
  const tab = page.getByRole('navigation', { name: 'System sections' }).getByRole('button', { name: new RegExp('^' + name) });
  await tab.click(); await expect(tab).toHaveAttribute('aria-current', 'page');
}
async function saved(page: Page, text: string): Promise<void> {
  await expect(page.getByRole('status').filter({ hasText: text })).toBeVisible();
}
async function draftDelete(page: Page, button: Locator, label: string, accept: boolean): Promise<void> {
  const opening = page.waitForEvent('dialog'), clicking = button.click(); const dialog = await opening;
  expect(dialog.type()).toBe('confirm'); expect(dialog.message()).toContain(label);
  if (accept) await dialog.accept(); else await dialog.dismiss(); await clicking;
}

test('SYSTEM-04/18, PARAM-01/02/03/04/05: all twelve editor types, Unicode group titles, zero defaults, ordering and independent drafts persist', async ({ page }) => {
  await login(page); await page.goto('/systems/1'); await expect(page.getByRole('heading', { name: 'Fixture System', exact: true })).toBeVisible();
  const original = await data(page, '/systems/1/parameters');
  await page.getByLabel('Description', { exact: true }).fill('Unsaved general draft with Zürich & <literal>');
  await systemTab(page, 'Parameters'); const editor = page.locator('app-parameter-schema-editor');
  await expect(editor.getByLabel('Input type', { exact: true })).toHaveCount(13);
  const types = await editor.getByLabel('Input type', { exact: true }).evaluateAll(elements => Array.from(new Set(elements.map(element => (element as HTMLSelectElement).value))).sort());
  expect(types).toEqual(['boolean', 'checkbox-selection', 'dropdown', 'integer', 'interval', 'percentage', 'percentage-dep', 'percentage-interval', 'ratio', 'software-versions-list', 'string', 'string-list'].sort());
  await editor.getByLabel('Group title', { exact: true }).first().fill('English group with spaces – Zürich & <literal>');
  await editor.getByRole('group', { name: 'Quantity', exact: true }).getByLabel('Default value', { exact: true }).fill('0');
  await editor.getByRole('group', { name: 'Enabled', exact: true }).getByLabel('Default value', { exact: true }).selectOption('false');
  await editor.getByRole('button', { name: 'Add group', exact: true }).click();
  await expect(editor.getByLabel('Group title', { exact: true })).toHaveCount(3);
  await editor.getByLabel('Group title', { exact: true }).last().fill('Another English group');
  await expect(editor.getByRole('heading', { name: 'Another English group', exact: true })).toBeVisible();
  await editor.getByRole('button', { name: 'Add parameter', exact: true }).last().click();
  const extra = editor.getByRole('group', { name: 'New parameter', exact: true });
  await extra.getByLabel('Parameter name', { exact: true }).fill('extra'); await extra.getByLabel('Label', { exact: true }).fill('Extra browser field');
  await editor.getByRole('button', { name: 'Add parameter', exact: true }).last().click();
  const disposableParameter = editor.getByRole('group', { name: 'New parameter', exact: true }); await disposableParameter.getByLabel('Label', { exact: true }).fill('Disposable parameter');
  const parameter = editor.getByRole('group', { name: 'Disposable parameter', exact: true }); await draftDelete(page, parameter.getByRole('button', { name: 'Delete parameter', exact: true }), 'Disposable parameter', false); await expect(parameter).toBeVisible();
  await draftDelete(page, parameter.getByRole('button', { name: 'Delete parameter', exact: true }), 'Disposable parameter', true); await expect(parameter).toHaveCount(0);
  await editor.getByRole('button', { name: 'Add group', exact: true }).click(); await expect(editor.getByLabel('Group title', { exact: true })).toHaveCount(4); await editor.getByLabel('Group title', { exact: true }).last().fill('Disposable group');
  const disposableGroup = editor.locator('section.card').filter({ has: page.getByRole('heading', { name: 'Disposable group', exact: true }) });
  await draftDelete(page, disposableGroup.getByRole('button', { name: 'Delete group', exact: true }), 'Disposable group', false); await expect(disposableGroup).toBeVisible(); await expect(editor.getByLabel('Group title', { exact: true })).toHaveCount(4);
  await draftDelete(page, disposableGroup.getByRole('button', { name: 'Delete group', exact: true }), 'Disposable group', true); await expect(disposableGroup).toHaveCount(0); await expect(editor.getByLabel('Group title', { exact: true })).toHaveCount(3);
  expect((await data(page, '/systems/1/parameters')).groups).toEqual(original.groups);
  await editor.getByRole('button', { name: 'Move group Another English group up', exact: true }).press('Enter');
  const response = page.waitForResponse(value => value.url().endsWith('/api/ui/systems/1/parameters') && value.request().method() === 'PUT');
  await page.getByRole('button', { name: 'Save parameters', exact: true }).click(); expect((await response).ok()).toBeTruthy(); await saved(page, 'Experiment parameters saved.');
  const actual = await data(page, '/systems/1/parameters'); expect(actual.groups[0].id).toBe(original.groups[0].id); expect(actual.groups[0].title).toBe('English group with spaces – Zürich & <literal>');
  expect(actual.groups[0].elements.find((element: any) => element.id === 'fixture-quantity').default).toBe('0'); expect(actual.groups[0].elements.find((element: any) => element.id === 'fixture-enabled').default).toBe('false');
  expect(actual.groups[1]).toMatchObject({ title: 'Another English group', elements: [expect.objectContaining({ parameter: 'extra', name: 'Extra browser field' })] });
  expect((await data(page, '/systems/1')).description).toBe('Synthetic system 1');
  await systemTab(page, 'General'); await expect(page.getByLabel('Description', { exact: true })).toHaveValue('Unsaved general draft with Zürich & <literal>');
  await page.getByRole('button', { name: 'Save general settings', exact: true }).click(); await saved(page, 'System settings saved.');
  await page.reload(); await expect(page.getByLabel('Description', { exact: true })).toHaveValue('Unsaved general draft with Zürich & <literal>'); await fitsViewport(page);
});

test('SYSTEM-19, RESULT-01/02/10: result builder copies, edits all five types, retains custom aliases and deletes only unused copies', async ({ page }) => {
  await login(page); await page.goto('/systems/1'); await systemTab(page, 'Results'); const editor = page.locator('app-result-configuration-editor');
  await editor.getByRole('button', { name: 'Copy configuration', exact: true }).click(); const configurationName = editor.getByLabel('Configuration name', { exact: true });
  await expect(configurationName).toHaveValue('Fixture Results (copy)'); await configurationName.fill('Browser charts Zürich'); await expect(configurationName).toHaveValue('Browser charts Zürich');
  const overall = editor.locator('section').filter({ has: page.getByRole('heading', { name: 'All jobs', exact: true }) });
  expect(await overall.getByLabel('Chart type', { exact: true }).evaluateAll(elements => elements.map(element => (element as HTMLSelectElement).value))).toEqual(['bar-plot', 'line-plot', 'line-plot-multi', 'pie-plot', 'box-plot']);
  await overall.getByRole('group', { name: 'Fixture Bar', exact: true }).getByLabel('Chart type', { exact: true }).selectOption('custom-bar');
  await overall.getByRole('group', { name: 'Fixture Box', exact: true }).getByLabel('Orientation', { exact: true }).selectOption('horizontal');
  await overall.getByRole('button', { name: 'Add chart', exact: true }).click(); const disposablePlot = overall.getByRole('group', { name: 'New chart', exact: true });
  await draftDelete(page, disposablePlot.getByRole('button', { name: 'Delete plot', exact: true }), 'New chart', false); await expect(disposablePlot).toBeVisible(); await expect(overall.getByLabel('Chart type', { exact: true })).toHaveCount(6);
  await draftDelete(page, disposablePlot.getByRole('button', { name: 'Delete plot', exact: true }), 'New chart', true); await expect(disposablePlot).toHaveCount(0); await expect(overall.getByLabel('Chart type', { exact: true })).toHaveCount(5);
  const savingCopy = page.waitForResponse(value => value.url().endsWith('/api/ui/systems/1/result-configurations') && value.request().method() === 'PUT');
  await page.getByRole('button', { name: 'Save result configurations', exact: true }).click(); const copyResponse = await savingCopy; expect(copyResponse.status()).toBe(200); await saved(page, 'Result configurations saved.');
  const actual = await data(page, '/systems/1/result-configurations'); const entry = Object.entries(actual.elements).find(([, value]: [string, any]) => value.name === 'Browser charts Zürich') as [string, any];
  expect(entry[0]).toMatch(/^system-/); expect(entry[1].all).toHaveLength(5); expect(entry[1].all[0].type).toBe('custom-bar'); expect(entry[1].all[4].orientation).toBe('horizontal'); expect(actual.elements['system-1'].all[0].type).toBe('bar-plot');
  await editor.getByRole('button', { name: 'Delete configuration…', exact: true }).click(); await editor.getByRole('button', { name: 'Delete configuration', exact: true }).click();
  const savingDeletion = page.waitForResponse(value => value.url().endsWith('/api/ui/systems/1/result-configurations') && value.request().method() === 'PUT');
  await page.getByRole('button', { name: 'Save result configurations', exact: true }).click(); const deletionResponse = await savingDeletion; expect(deletionResponse.status()).toBe(200); await saved(page, 'Result configurations saved.');
  const remaining = await data(page, '/systems/1/result-configurations'); expect(remaining.elements[entry[0]]).toBeUndefined(); expect(Object.keys(remaining.elements)).toEqual(['system-1']); expect(remaining.elements['system-1']).toEqual(actual.elements['system-1']); await fitsViewport(page);
});

test('SYSTEM-07/08/09/10/13/15/16/17: environments, native defaults, validated imports, downloads, logo and searchable history use real persistence', async ({ page }) => {
  test.setTimeout(90_000); await login(page); await page.goto('/systems/1'); await systemTab(page, 'Environments');
  await page.getByLabel('New environment name', { exact: true }).fill('browser environment'); await section(page, 'Execution environments').getByRole('button', { name: 'Add', exact: true }).click(); await page.getByRole('button', { name: 'Save environments', exact: true }).click(); await saved(page, 'Environments saved.');
  expect((await data(page, '/systems/1/environments')).environments).toContain('browser environment');
  await systemTab(page, 'Settings and defaults'); const defaults = section(page, 'Experiment defaults');
  await defaults.getByRole('combobox').first().selectOption('unchecked'); await defaults.getByRole('combobox').nth(1).selectOption('browser environment');
  await defaults.getByLabel('New setting key', { exact: true }).fill('browserZero'); await defaults.getByRole('button', { name: 'Add', exact: true }).click(); await expect(defaults.getByLabel('browserZero', { exact: true })).toHaveValue(''); await defaults.getByLabel('browserZero', { exact: true }).fill('0');
  await defaults.getByRole('button', { name: 'Save Experiment defaults', exact: true }).click(); await saved(page, 'System settings saved.');
  expect((await data(page, '/systems/1/settings')).defaultValues).toMatchObject({ phase_warmUp: 'unchecked', environment: 'browser environment', browserZero: '0' });
  for (const [heading, key, value] of [['Setup options', 'browserSetup', '0'], ['Evaluation settings', 'browserEvaluation', 'false']]) {
    const settings = section(page, heading); await settings.getByLabel('New setting key', { exact: true }).fill(key); await settings.getByRole('button', { name: 'Add', exact: true }).click(); await expect(settings.getByLabel(key, { exact: true })).toHaveValue(''); await settings.getByLabel(key, { exact: true }).fill(value);
    const response = page.waitForResponse(reply => reply.url().endsWith('/api/ui/systems/1/settings') && reply.request().method() === 'PUT'); await settings.getByRole('button', { name: `Save ${heading}`, exact: true }).click(); expect((await response).ok()).toBeTruthy();
  }
  expect(await data(page, '/systems/1/settings')).toMatchObject({ setup: { browserSetup: '0' }, general: { browserEvaluation: 'false' } });
  await systemTab(page, 'Logo and files'); const groups = (await data(page, '/systems/1/parameters')).groups;
  await page.getByLabel('Import parameters.json or resultConfigurations.json', { exact: true }).setInputFiles({ name: 'parameters.json', mimeType: 'application/json', buffer: Buffer.from('{broken') });
  await page.getByRole('button', { name: 'Validate and import configuration', exact: true }).click(); await expect(page.getByRole('alert')).toContainText('valid JSON'); expect((await data(page, '/systems/1/parameters')).groups).toEqual(groups);
  const imported = structuredClone(groups); imported[0].title = 'Imported English group with spaces';
  await page.getByLabel('Import parameters.json or resultConfigurations.json', { exact: true }).setInputFiles({ name: 'parameters.json', mimeType: 'application/json', buffer: Buffer.from(JSON.stringify(imported)) });
  await page.getByRole('button', { name: 'Validate and import configuration', exact: true }).click(); await saved(page, 'Configuration validated and imported.'); expect((await data(page, '/systems/1/parameters')).groups[0].title).toBe(imported[0].title);
  const png = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAIAAAACCAYAAABytg0kAAAAFUlEQVR4nGOQj575H4QZGHqm/gdhAEU0CGXFKd3EAAAAAElFTkSuQmCC', 'base64');
  await page.getByLabel('PNG, JPEG or GIF · maximum 512 KiB', { exact: true }).setInputFiles({ name: 'browser-logo.png', mimeType: 'image/png', buffer: png }); await page.getByRole('button', { name: 'Save logo', exact: true }).click(); await saved(page, 'System logo saved.');
  const logo = await page.request.get('/api/ui/systems/1/logo'); expect(logo.headers()['content-type']).toContain('image/png'); expect((await logo.body()).subarray(0, 8).equals(png.subarray(0, 8))).toBeTruthy();
  const download = page.waitForEvent('download'); await page.getByRole('link', { name: 'Download system configuration ZIP', exact: true }).click(); const zip = await download; expect(zip.suggestedFilename()).toBe('system1.zip'); expect((await readFile((await zip.path())!)).includes(Buffer.from('parameters.json'))).toBeTruthy();
  await systemTab(page, 'Repository and history'); await page.getByRole('button', { name: 'Refresh local history', exact: true }).click(); const history = section(page, 'Version history'); await expect(history).toContainText('Fixture commit'); await expect(history).toContainText('15.01.2026');
  await history.getByLabel('Search history', { exact: true }).fill('missing-revision'); await expect(history).toContainText('No history matches your search.'); await history.getByLabel('Search history', { exact: true }).fill('Fixture Author'); await expect(history).toContainText('Fixture commit');
  await history.getByRole('button', { name: 'Author', exact: true }).click(); await expect(history.getByRole('columnheader', { name: 'Author', exact: true })).toHaveAttribute('aria-sort', 'ascending'); await fitsViewport(page);
});

test('EXPERIMENT-08, RESULT-01/02/03/10, AUTH-03: project members edit their own charts, select aliases, render them and download complete results', async ({ page }) => {
  await login(page, 'member'); await page.goto('/experiments/1'); const configurations = page.locator('app-experiment-results');
  await configurations.getByRole('button', { name: 'Copy to this experiment', exact: true }).first().click(); await expect(configurations.locator('details.configuration-editor')).toHaveAttribute('open', ''); const editor = configurations.locator('app-result-configuration-editor'); await editor.getByLabel('Configuration name', { exact: true }).fill('Member chart configuration');
  await editor.getByLabel('Chart type', { exact: true }).first().selectOption('custom-bar'); await configurations.getByRole('button', { name: 'Save experiment configurations', exact: true }).click(); await saved(page, 'Experiment result configurations saved.');
  const owned = await data(page, '/experiments/1/result-configurations'); const ownId = Object.entries(owned.elements).find(([, entry]: [string, any]) => entry.name === 'Member chart configuration')![0]; expect(ownId).toMatch(/^experiment-1-/); expect((await page.request.get('/api/ui/systems/1/result-configurations')).status()).toBe(403);
  await configurations.getByLabel("Configuration used for this experiment's results", { exact: true }).selectOption(ownId); await configurations.getByRole('button', { name: 'Use selected configuration', exact: true }).click(); await saved(page, 'The selected result configuration is now used by this experiment.'); expect((await data(page, '/experiments/1')).resultId).toBe(ownId);
  await page.goto('/evaluations/2/results'); const explorer = page.locator('app-results-explorer'); await expect(explorer.getByLabel('Result configuration', { exact: true })).toHaveValue(ownId); await expect(explorer.getByRole('button', { name: 'Download PNG' }).first()).toBeEnabled();
  const full = await data(page, '/evaluations/2/results'); expect(full.charts[0].definition).toMatchObject({ type: 'custom-bar', baseType: 'bar-plot' }); expect(full.charts[0].renderer).toBe('bar');
  await expect(explorer.locator('.raw-result-table')).toHaveCount(0); await explorer.getByRole('button', { name: 'Raw results', exact: true }).click();
  await explorer.getByLabel('Search results or parameters', { exact: true }).fill('missing-result'); await expect(explorer).toContainText('No jobs match your search.'); const filtered = await data(page, '/evaluations/2/results?search=missing-result&pageSize=10'); expect(filtered.charts).toEqual(full.charts); expect(filtered.jobPage.total).toBe(0);
  await explorer.getByLabel('Search results or parameters', { exact: true }).fill(''); await expect(explorer).toContainText('6 matching jobs'); await explorer.getByLabel('Rows per page', { exact: true }).selectOption('10'); await explorer.getByRole('button', { name: 'Job', exact: true }).click(); await expect(explorer.getByRole('columnheader', { name: 'Job', exact: true })).toHaveAttribute('aria-sort', 'ascending');
  const csvDownload = page.waitForEvent('download'); await explorer.getByRole('link', { name: 'Download raw results CSV', exact: true }).click(); const csv = await csvDownload; expect(csv.suggestedFilename()).toBe('evaluation2.csv'); expect((await readFile((await csv.path())!, 'utf8'))).toContain('metric');
  const archiveDownload = page.waitForEvent('download'); await explorer.getByRole('link', { name: 'Download result archives', exact: true }).click(); const archive = await archiveDownload; expect((await readFile((await archive.path())!)).subarray(0, 2).toString()).toBe('PK'); await fitsViewport(page);
});

test('SYSTEM-19/16, RESULT-01: legacy configurations are read without migration and migrate only through the explicit action', async ({ page }) => {
  await login(page, 'outsider'); await page.goto('/systems/2'); await systemTab(page, 'Results'); await expect(page.getByText('Legacy result files are being displayed', { exact: false })).toBeVisible();
  expect((await data(page, '/systems/2')).hasLegacyResults).toBe(true); await data(page, '/systems/2/result-configurations'); expect((await data(page, '/systems/2')).hasLegacyResults).toBe(true);
  await page.getByRole('button', { name: 'Migrate legacy configuration', exact: true }).click(); await saved(page, 'Legacy configuration migrated. Original files retained.'); expect((await data(page, '/systems/2')).hasLegacyResults).toBe(false);
  await systemTab(page, 'Logo and files'); const download = page.waitForEvent('download'); await page.getByRole('link', { name: 'Download system configuration ZIP', exact: true }).click(); const bytes = await readFile((await (await download).path())!); for (const filename of ['resultConfigurations.json', 'results.json', 'resultsJob.json']) expect(bytes.includes(Buffer.from(filename))).toBeTruthy(); await fitsViewport(page);
});


test('RESULT-09: completed agent results add a new chart group through WebSocket push and manual refresh', async ({ page }) => {
  const live = observeLive(page);
  await login(page); await page.goto('/evaluations/1/results'); const explorer = page.locator('app-results-explorer');
  await expect(explorer.getByRole('status').filter({ hasText: /^\s*1 of 6 jobs finished\./ })).toBeVisible();
  const before = await data(page, '/evaluations/1/results'); expect(before.finishedJobs).toBe(1); expect(before.charts.filter((chart: any) => chart.scope === 'job').every((chart: any) => chart.jobIds.includes(5))).toBe(true);
  await expect(explorer.locator('app-result-chart')).toHaveCount(before.charts.length);
  const automaticallyRefreshed = live.next('/evaluations/1/results', frame => frame.data.finishedJobs === 2, live.mark());
  const complete = await page.request.patch('/api/v1/job/id=4', { form: { status: 3, progress: 100, result: JSON.stringify({ metric: 128, series: '[1,2,3]', read: 3, write: 1 }) } }); expect(complete.ok()).toBeTruthy();
  expect((await automaticallyRefreshed).data.finishedJobs).toBe(2); await expect(explorer.getByRole('status').filter({ hasText: /^\s*2 of 6 jobs finished\./ })).toBeVisible();
  const overallBar = explorer.locator('app-result-chart').filter({ has: page.getByRole('heading', { name: 'Fixture Bar', exact: true }) }).first();
  await overallBar.locator('summary').click(); await expect(overallBar.getByRole('cell', { name: '128', exact: true })).toBeVisible();
  const after = await data(page, '/evaluations/1/results');
  expect(after.finishedJobs).toBe(2); expect(after.charts.length).toBeGreaterThan(before.charts.length);
  const group = after.charts.filter((chart: any) => chart.scope === 'job' && chart.jobIds.length === 1 && chart.jobIds[0] === 4); expect(group).toHaveLength(5); expect(group.every((chart: any) => chart.groupLabel.includes('size: 30'))).toBe(true);
  expect(after.charts.find((chart: any) => chart.scope === 'all' && chart.definition.type === 'bar-plot').data.datasets[0].data.toSorted((a: number, b: number) => a - b)).toEqual([10, 128]);
  await expect(explorer.getByRole('status').filter({ hasText: /^\s*2 of 6 jobs finished\./ })).toBeVisible(); await expect(explorer.locator('app-result-chart')).toHaveCount(after.charts.length); await expect(explorer.locator('app-result-chart').filter({ hasText: 'Job[size: 30]' })).toHaveCount(5);
  const automatic = explorer.getByLabel('Refresh automatically', { exact: true }); await automatic.uncheck(); let polls = 0;
  const countPolls = (request: any) => { if (/\/api\/ui\/evaluations\/1\/results\?/.test(request.url())) polls++; }; page.on('request', countPolls);
  const changed = await page.request.patch('/api/v1/job/id=4', { form: { result: JSON.stringify({ metric: 256, series: '[2,4,6]', read: 4, write: 2 }) } }); expect(changed.ok()).toBeTruthy();
  await expect.poll(() => live.active('/evaluations/1/results').length).toBe(0);
  await expect(overallBar.getByRole('cell', { name: '128', exact: true })).toBeVisible();
  expect(polls).toBe(0);
  const manuallyRefreshed = live.next('/evaluations/1/results', frame => frame.data.charts.some((chart: any) => chart.scope === 'all' && chart.definition.type === 'bar-plot' && chart.data.datasets[0].data.includes(256)), live.mark());
  await explorer.getByRole('button', { name: 'Refresh results', exact: true }).click(); expect((await manuallyRefreshed).data.finishedJobs).toBe(2); await expect(overallBar.getByRole('cell', { name: '256', exact: true })).toBeVisible();
  const manual = await data(page, '/evaluations/1/results');
  expect(manual.charts.find((chart: any) => chart.scope === 'all' && chart.definition.type === 'bar-plot').data.datasets[0].data.toSorted((a: number, b: number) => a - b)).toEqual([10, 256]); await expect(explorer.locator('app-result-chart')).toHaveCount(after.charts.length); expect(polls).toBe(0); page.off('request', countPolls); await fitsViewport(page);
});
