import { test, expect, login, fitsViewport, type Page, type Locator } from './fixture';

async function resultConfigurations(page: Page, resource: string): Promise<any> {
  const response = await page.request.get(`/api/ui${resource}/result-configurations`);
  expect(response.ok()).toBe(true);
  return (await response.json()).data;
}

function scopeOutline(editor: Locator, name: string): Locator {
  return editor.getByRole('navigation', { name: 'Result structure', exact: true }).getByRole('group', { name, exact: true });
}

async function openSystemResults(page: Page): Promise<Locator> {
  await login(page); await page.goto('/systems/1');
  await page.getByRole('navigation', { name: 'System sections', exact: true }).getByRole('button', { name: 'Results', exact: true }).click();
  return page.locator('app-result-configuration-editor');
}

test('RESULT-BUILDER-01: focused chart search and scope-only typed creation preserve keyboard ordering, undo and real persistence', async ({ page }) => {
  const editor = await openSystemResults(page);
  const original = await resultConfigurations(page, '/systems/1');
  const structure = editor.getByRole('navigation', { name: 'Result structure', exact: true });
  const overall = scopeOutline(editor, 'All jobs');
  const charts = structure.getByRole('button', { name: /^Edit chart / });
  const search = editor.getByLabel('Search charts', { exact: true });
  await expect(charts).toHaveCount(10);
  await search.fill('Fixture Box'); await expect(charts).toHaveCount(2);
  await expect(overall.getByRole('button', { name: 'Edit chart Fixture Box', exact: true })).toBeVisible();
  await search.fill(''); await expect(charts).toHaveCount(10);
  await overall.getByRole('button', { name: 'Edit chart Fixture Box', exact: true }).click();
  await expect(editor.getByLabel('Chart type', { exact: true })).toHaveCount(1);
  await expect(editor.getByLabel('Chart type', { exact: true })).toHaveValue('box-plot');
  await expect(editor.getByLabel('Orientation', { exact: true })).toHaveValue('vertical');
  await expect(editor.getByRole('button', { name: 'Add chart', exact: true })).toHaveCount(0);
  await expect(editor.getByLabel('New chart type', { exact: true })).toHaveCount(0);
  expect(await resultConfigurations(page, '/systems/1')).toEqual(original);

  await overall.getByRole('button', { name: 'Edit scope All jobs', exact: true }).click();
  await expect(editor.getByRole('button', { name: 'Add chart', exact: true })).toBeVisible();
  await editor.getByLabel('New chart type', { exact: true }).selectOption('custom-bar');
  await editor.getByRole('button', { name: 'Add chart', exact: true }).click();
  const title = editor.getByLabel('Title', { exact: true });
  await expect(title).toBeFocused();
  await expect(editor.getByLabel('Chart type', { exact: true })).toHaveValue('custom-bar');
  await expect(editor.getByRole('button', { name: 'Add chart', exact: true })).toHaveCount(0);
  await title.fill('Browser sample chart');
  await editor.getByLabel('Result metric names (comma-separated)', { exact: true }).fill('metric');
  await expect(overall.getByRole('button', { name: /^Edit chart / })).toHaveCount(6);
  await editor.getByRole('button', { name: 'Move Browser sample chart up', exact: true }).press('Enter');
  await expect(overall.getByRole('button', { name: /^Edit chart / }).nth(4)).toHaveAttribute('aria-label', 'Edit chart Browser sample chart');
  await editor.getByRole('button', { name: 'Undo last change', exact: true }).click();
  await expect(overall.getByRole('button', { name: /^Edit chart / }).nth(5)).toHaveAttribute('aria-label', 'Edit chart Browser sample chart');
  const save = page.getByRole('button', { name: 'Save result configurations', exact: true });
  await expect(save).toBeEnabled();
  expect(await resultConfigurations(page, '/systems/1')).toEqual(original);
  const response = page.waitForResponse(value => new URL(value.url()).pathname === '/api/ui/systems/1/result-configurations' && value.request().method() === 'PUT');
  await save.click(); expect((await response).ok()).toBe(true);
  await expect(page.getByRole('status').filter({ hasText: 'Result configurations saved.' })).toBeVisible();
  const actual = await resultConfigurations(page, '/systems/1');
  expect(actual.elements['system-1'].all.slice(0, 5)).toEqual(original.elements['system-1'].all);
  expect(actual.elements['system-1'].all.at(-1)).toMatchObject({ type: 'custom-bar', name: 'Browser sample chart', parameter: 'metric' });
  expect(actual.elements['system-1'].job).toEqual(original.elements['system-1'].job);
  await fitsViewport(page);
});

for (const context of ['system', 'experiment'] as const) {
  test(`RESULT-BUILDER-02 ${context}: a wide native example preview preserves unsaved charts, renders all types and restores focus`, async ({ page }) => {
    const resource = context === 'system' ? '/systems/1' : '/experiments/1';
    let editor: Locator;
    let save: Locator;
    if (context === 'system') {
      editor = await openSystemResults(page);
      save = page.getByRole('button', { name: 'Save result configurations', exact: true });
    } else {
      await login(page, 'member'); await page.goto(resource);
      const configurations = page.locator('app-experiment-results');
      await configurations.getByRole('button', { name: 'Copy to this experiment', exact: true }).first().click();
      editor = configurations.locator('app-result-configuration-editor');
      save = configurations.getByRole('button', { name: 'Save experiment configurations', exact: true });
    }
    const original = await resultConfigurations(page, resource);
    const writes: string[] = [];
    page.on('request', request => {
      if (new URL(request.url()).pathname === `/api/ui${resource}/result-configurations` && request.method() === 'PUT') writes.push(request.postData() ?? '');
    });
    const scope = scopeOutline(editor, context === 'system' ? 'All jobs' : 'Individual configurations');
    await scope.getByRole('button', { name: 'Edit chart Fixture Bar', exact: true }).click();
    const title = editor.getByLabel('Title', { exact: true });
    await title.fill('Unsaved chart preview – Zürich');
    await expect(save).toBeEnabled();
    const open = editor.getByRole('button', { name: 'Preview charts', exact: true });
    const dialog = page.getByRole('dialog', { name: 'Result configuration preview', exact: true });
    await open.click(); await expect(dialog).toBeVisible();
    expect(await dialog.evaluate(element => element.matches(':modal'))).toBe(true);
    const bounds = (await dialog.boundingBox())!;
    const viewport = page.viewportSize()!;
    expect(bounds.width).toBeGreaterThanOrEqual(viewport.width * 0.9);
    expect(bounds.x).toBeGreaterThanOrEqual(0); expect(bounds.y).toBeGreaterThanOrEqual(0);
    expect(bounds.x + bounds.width).toBeLessThanOrEqual(viewport.width + 1);
    expect(bounds.y + bounds.height).toBeLessThanOrEqual(viewport.height + 1);
    expect(await dialog.evaluate(element => element.scrollWidth <= element.clientWidth + 1)).toBe(true);
    const close = dialog.getByRole('button', { name: 'Close preview', exact: true });
    const closeBounds = (await close.boundingBox())!;
    expect(closeBounds.width).toBeGreaterThanOrEqual(40); expect(closeBounds.height).toBeGreaterThanOrEqual(40);
    const preview = dialog.locator('app-result-configuration-preview');
    await expect(preview).toContainText('Example data only');
    await expect(preview.locator('app-result-chart')).toHaveCount(10);
    await expect(preview.locator('canvas')).toHaveCount(10);
    await expect(preview.getByRole('heading', { name: 'Unsaved chart preview – Zürich', exact: true })).toBeVisible();
    for (const png of await preview.getByRole('button', { name: 'Download PNG', exact: true }).all()) await expect(png).toBeEnabled();
    const body = dialog.locator('.dialog-body');
    expect(await body.evaluate(element => element.scrollHeight > element.clientHeight)).toBe(true);
    await body.evaluate(element => { element.scrollTop = element.scrollHeight; });
    expect(await body.evaluate(element => element.scrollTop)).toBeGreaterThan(0);
    await expect(close).toBeInViewport();
    await close.click(); await expect(dialog).not.toBeVisible(); await expect(open).toBeFocused();
    await expect(title).toHaveValue('Unsaved chart preview – Zürich');
    await open.click(); await expect(dialog).toBeVisible(); await page.keyboard.press('Escape');
    await expect(dialog).not.toBeVisible(); await expect(open).toBeFocused();
    await open.click(); await expect(dialog).toBeVisible();
    const reopened = (await dialog.boundingBox())!;
    expect(reopened.x).toBeGreaterThan(1);
    await page.mouse.click(reopened.x / 2, reopened.y + 1);
    await expect(dialog).not.toBeVisible(); await expect(open).toBeFocused();
    await expect(title).toHaveValue('Unsaved chart preview – Zürich'); await expect(save).toBeEnabled();
    expect(await resultConfigurations(page, resource)).toEqual(original);
    expect(writes).toEqual([]);
    await fitsViewport(page);
  });
}
