import { test, expect, login, fitsViewport } from './fixture';

test('WORKSPACE-02: configuration copy and select icons retain accessible names, touch targets and persisted selection', async ({ page }) => {
  await login(page, 'member'); await page.goto('/experiments/1');
  const configurations = page.locator('app-experiment-results');
  const table = configurations.getByRole('table', { name: 'Saved system and experiment result configurations', exact: true });
  const copy = table.getByRole('button', { name: 'Copy to this experiment', exact: true }).first();
  const select = table.getByRole('button', { name: 'Select configuration', exact: true }).first();
  for (const action of [copy, select]) {
    await expect(action).toHaveClass(/btn-icon/);
    await expect(action.locator('app-icon')).toHaveCount(1);
    await expect(action.locator('span')).toHaveClass('visually-hidden');
    await expect(action).toHaveAttribute('title', /configuration|experiment/i);
    const bounds = (await action.boundingBox())!;
    const minimum = page.viewportSize()!.width < 992 ? 40 : 30;
    expect(bounds.width).toBeGreaterThanOrEqual(minimum);
    expect(bounds.height).toBeGreaterThanOrEqual(minimum);
  }
  await copy.click();
  const editor = configurations.locator('app-result-configuration-editor');
  await editor.getByLabel('Configuration name', { exact: true }).fill('Icon configuration');
  await configurations.getByRole('button', { name: 'Save experiment configurations', exact: true }).click();
  await expect(configurations).toContainText('Experiment result configurations saved.');
  const row = table.getByRole('row').filter({ has: page.getByRole('rowheader', { name: /^Icon configuration/ }) });
  const choose = row.getByRole('button', { name: 'Select configuration', exact: true });
  await expect(choose).toBeEnabled(); await choose.click();
  await expect(configurations).toContainText('The selected result configuration is now used by this experiment.');
  await expect(choose).toHaveAttribute('aria-pressed', 'true');
  await expect(choose).toBeDisabled();
  const saved = (await (await page.request.get('/api/ui/experiments/1/result-configurations')).json()).data;
  const id = Object.entries(saved.elements).find(([, item]: [string, any]) => item.name === 'Icon configuration')![0];
  const experiment = (await (await page.request.get('/api/ui/experiments/1')).json()).data;
  expect(experiment.resultId).toBe(id);
  await fitsViewport(page);
});
