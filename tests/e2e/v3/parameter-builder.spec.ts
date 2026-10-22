import { test, expect, login, fitsViewport, type Page } from './fixture';

async function parameterGroups(page: Page): Promise<any[]> {
  const response = await page.request.get('/api/ui/systems/1/parameters');
  expect(response.ok()).toBe(true);
  return (await response.json()).data.groups;
}

test('PARAM-BUILDER-01: focused editing preserves search, issue navigation and group-only typed creation with move undo', async ({ page }) => {
  await login(page); await page.goto('/systems/1');
  const original = await parameterGroups(page);
  const writes: string[] = [];
  page.on('request', request => {
    if (new URL(request.url()).pathname === '/api/ui/systems/1/parameters' && request.method() === 'PUT') writes.push(request.postData() ?? '');
  });
  await page.getByRole('navigation', { name: 'System sections', exact: true }).getByRole('button', { name: 'Parameters', exact: true }).click();
  const editor = page.locator('app-parameter-schema-editor');
  const structure = editor.getByRole('navigation', { name: 'Parameter structure', exact: true });
  const parameters = structure.getByRole('button', { name: /^Edit parameter / });
  const search = editor.getByLabel('Search parameters', { exact: true });
  const save = page.getByRole('button', { name: 'Save parameters', exact: true });
  await expect(parameters).toHaveCount(13);

  await search.fill('quantity');
  await expect(parameters).toHaveCount(1);
  await expect(structure.getByRole('button', { name: 'Edit parameter Quantity', exact: true })).toBeVisible();
  await expect(save).toBeDisabled();
  expect(await parameterGroups(page)).toEqual(original);
  await search.fill(''); await expect(parameters).toHaveCount(13);

  await structure.getByRole('button', { name: 'Edit parameter Quantity', exact: true }).click();
  const quantity = editor.getByRole('group', { name: 'Quantity', exact: true });
  await expect(quantity.getByLabel('Default value', { exact: true })).toHaveValue('0');
  await expect(editor.getByRole('button', { name: 'Add parameter', exact: true })).toHaveCount(0);
  await expect(editor.getByLabel('New input type', { exact: true })).toHaveCount(0);
  await expect(editor.getByRole('button', { name: 'Edit group settings', exact: true })).toBeVisible();
  expect(await parameterGroups(page)).toEqual(original);
  expect(writes).toEqual([]);

  await quantity.getByLabel('Parameter name', { exact: true }).fill('quantity with spaces');
  await expect(save).toBeDisabled();
  await expect(structure.getByRole('button', { name: 'Edit parameter Quantity', exact: true }))
    .toHaveAccessibleDescription('1 validation issue for this parameter');
  await structure.getByRole('button', { name: 'Edit parameter Enabled', exact: true }).click();
  await expect(quantity).toHaveCount(0);
  await editor.getByRole('button', { name: 'Use a parameter name beginning with a letter or underscore, without spaces or XML punctuation.', exact: true }).click();
  await expect(quantity).toBeVisible();
  await expect(quantity.getByLabel('Parameter name', { exact: true })).toHaveValue('quantity with spaces');
  await quantity.getByLabel('Parameter name', { exact: true }).fill('quantity');
  await expect(structure.getByRole('button', { name: 'Edit parameter Quantity', exact: true }))
    .toHaveAccessibleDescription('');

  await editor.getByRole('button', { name: 'Add group', exact: true }).click();
  await editor.getByLabel('Group title', { exact: true }).fill('Builder destination');
  await editor.getByLabel('Show when parameter', { exact: true }).fill('runs');
  await editor.getByLabel('Has value', { exact: true }).fill('2');
  await expect(editor.getByRole('button', { name: 'Add parameter', exact: true })).toBeVisible();
  await editor.getByLabel('New input type', { exact: true }).selectOption('interval');
  await editor.getByRole('button', { name: 'Add parameter', exact: true }).click();
  let range = editor.getByRole('group', { name: 'New parameter', exact: true });
  await expect(range.getByLabel('Input type', { exact: true })).toHaveValue('interval');
  await range.getByLabel('Parameter name', { exact: true }).fill('builderRange');
  await range.getByLabel('Label', { exact: true }).fill('Builder range');
  range = editor.getByRole('group', { name: 'Builder range', exact: true });
  for (const [label, value] of [['Default minimum', '0'], ['Default maximum', '2'], ['Default step', '1']]) await range.getByLabel(label, { exact: true }).fill(value);
  await range.getByLabel('Move Builder range to another group', { exact: true }).selectOption({ label: original[0].title });
  await expect(editor.getByRole('button', { name: 'Move group ' + original[0].title + ' up', exact: true })).toBeDisabled();
  await editor.getByRole('button', { name: 'Undo last change', exact: true }).click();
  await expect(range).toBeVisible();
  await expect(editor.getByRole('button', { name: 'Move group Builder destination up', exact: true })).toBeEnabled();
  await range.getByLabel('Move Builder range to another group', { exact: true }).selectOption({ label: original[0].title });
  await expect(editor.getByLabel('Input type', { exact: true })).toHaveCount(1);
  await expect(save).toBeEnabled();
  expect(await parameterGroups(page)).toEqual(original);
  expect(writes).toEqual([]);
  await fitsViewport(page);

  const response = page.waitForResponse(value => new URL(value.url()).pathname === '/api/ui/systems/1/parameters' && value.request().method() === 'PUT');
  await save.click(); expect((await response).status()).toBe(200);
  await expect(page.getByRole('status').filter({ hasText: 'Experiment parameters saved.' })).toBeVisible();
  const actual = await parameterGroups(page);
  expect(actual[0].elements.slice(0, original[0].elements.length)).toEqual(original[0].elements);
  expect(actual[0].elements.at(-1)).toMatchObject({ name: 'Builder range', parameter: 'builderRange', type: 'interval', 'default-min': '0', 'default-max': '2', 'default-step': '1' });
  expect(actual[1]).toEqual(original[1]);
  expect(actual[2]).toMatchObject({ title: 'Builder destination', depends: 'runs', dependsValue: '2', elements: [] });
  expect(writes).toHaveLength(1);
});

test('PARAM-BUILDER-02: the wide form preview fits the viewport, isolates samples and restores focus on every dismissal', async ({ page }) => {
  await login(page); await page.goto('/systems/1');
  const original = await parameterGroups(page);
  const writes: string[] = [];
  page.on('request', request => {
    if (new URL(request.url()).pathname === '/api/ui/systems/1/parameters' && request.method() === 'PUT') writes.push(request.postData() ?? '');
  });
  await page.getByRole('navigation', { name: 'System sections', exact: true }).getByRole('button', { name: 'Parameters', exact: true }).click();
  const editor = page.locator('app-parameter-schema-editor');
  await editor.getByRole('navigation', { name: 'Parameter structure', exact: true }).getByRole('button', { name: 'Edit parameter Quantity', exact: true }).click();
  const quantity = editor.getByRole('group', { name: 'Quantity', exact: true });
  const defaultValue = quantity.getByLabel('Default value', { exact: true });
  await defaultValue.fill('5');
  const save = page.getByRole('button', { name: 'Save parameters', exact: true });
  await expect(save).toBeEnabled();
  const open = editor.getByRole('button', { name: 'Preview form', exact: true });
  const dialog = page.getByRole('dialog', { name: 'Parameter form preview', exact: true });
  await open.click();
  await expect(dialog).toBeVisible();
  expect(await dialog.evaluate(element => element.matches(':modal'))).toBe(true);
  const closeBox = await dialog.getByRole('button', { name: 'Close preview', exact: true }).boundingBox();
  expect(closeBox!.width).toBeGreaterThanOrEqual(40);
  expect(closeBox!.height).toBeGreaterThanOrEqual(40);
  const box = await dialog.boundingBox();
  const viewport = page.viewportSize()!;
  expect(box).not.toBeNull();
  expect(box!.width).toBeGreaterThanOrEqual(viewport.width * 0.9);
  expect(box!.x).toBeGreaterThanOrEqual(0);
  expect(box!.y).toBeGreaterThanOrEqual(0);
  expect(box!.x + box!.width).toBeLessThanOrEqual(viewport.width + 1);
  expect(box!.y + box!.height).toBeLessThanOrEqual(viewport.height + 1);
  expect(await dialog.evaluate(element => element.scrollWidth <= element.clientWidth + 1)).toBe(true);
  const preview = dialog.locator('app-parameter-schema-preview');
  const sampleQuantity = preview.getByRole('group', { name: 'Quantity (quantity)', exact: true }).getByLabel('Value', { exact: true });
  await expect(sampleQuantity).toHaveValue('5');
  await sampleQuantity.fill('77'); await expect(sampleQuantity).toHaveValue('77');
  await preview.getByRole('button', { name: 'Reset preview', exact: true }).click();
  await expect(sampleQuantity).toHaveValue('5');
  await sampleQuantity.fill('88');
  await dialog.getByRole('button', { name: 'Close preview', exact: true }).click();
  await expect(dialog).not.toBeVisible(); await expect(open).toBeFocused();
  await expect(defaultValue).toHaveValue('5');
  await open.click(); await expect(dialog).toBeVisible();
  await expect(sampleQuantity).toHaveValue('5');
  await page.keyboard.press('Escape');
  await expect(dialog).not.toBeVisible(); await expect(open).toBeFocused();
  await open.click(); await expect(dialog).toBeVisible();
  const reopened = await dialog.boundingBox();
  expect(reopened).not.toBeNull();
  // Use the visible backdrop gutter, outside the dialog's interactive content.
  expect(reopened!.x).toBeGreaterThan(1);
  await page.mouse.click(reopened!.x / 2, reopened!.y + 1);
  await expect(dialog).not.toBeVisible(); await expect(open).toBeFocused();
  await expect(defaultValue).toHaveValue('5'); await expect(save).toBeEnabled();
  expect(await parameterGroups(page)).toEqual(original);
  expect(writes).toEqual([]);
  await fitsViewport(page);
});
