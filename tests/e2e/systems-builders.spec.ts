import {
  test, expect, login, logout, panel, createSystem, clickAndAccept, unique,
} from './helpers';

test('SYSTEM-01, SYSTEM-03, SYSTEM-04: create a local system, edit it and transfer ownership', async ({ page }) => {
  await login(page, 'admin');
  const id = await createSystem(page, 'Created Regression System');
  await page.locator('[name="name"]').fill('Transferred Regression System');
  await page.locator('[name="description"]').fill('Ownership transfer description.');
  await page.locator('[name="owner"]').selectOption('5');
  await page.locator('button[name="group"][value="general"]').click();
  await page.reload();
  await expect(page.locator('[name="name"]')).toHaveValue('Transferred Regression System');
  await expect(page.locator('[name="owner"]')).toHaveValue('5');
  await logout(page);
  await login(page);
  await page.goto(`/admin/system/id=${id}`);
  await expect(page.locator('body')).toContainText('Not enough privileges to view this system!');
  await logout(page);
  await login(page, 'outsider');
  await page.goto('/admin/systems');
  const card = page.locator('.box-profile').filter({ hasText: 'Transferred Regression System' });
  await expect(card).toContainText('Ownership transfer description.');
  await card.getByRole('link', { name: /View$/ }).click();
  await expect(page.locator('.content-header h1')).toContainText('Transferred Regression System');
});

test('SYSTEM-02, SYSTEM-06: reject archiving an in-use system and include archived unused systems', async ({ page }) => {
  await login(page);
  await page.goto('/admin/system/id=1');
  await clickAndAccept(page, () => page.getByRole('button', { name: 'Archive this System' }).click());
  await expect(page.locator('body')).toContainText('still used by at least one not archived project');
  await logout(page);
  await login(page, 'admin');
  const id = await createSystem(page, 'Unused Archived System');
  await clickAndAccept(page, () => page.getByRole('button', { name: 'Archive this System' }).click());
  await expect(panel(page, /General/)).toContainText('(Archived System)');
  await page.goto('/admin/systems');
  await expect(page.locator(`a[href="/admin/system/id=${id}"]`)).toHaveCount(0);
  await page.locator('#showArchivedSystems').check();
  await expect(page.locator(`a[href="/admin/system/id=${id}"]`)).toBeVisible();
  await expect(page.locator('.box-profile').filter({ hasText: 'Unused Archived System' })).toContainText('(Archived)');
});

test('SYSTEM-07: add and delete a local environment and reject a duplicate', async ({ page }) => {
  await login(page);
  await page.goto('/admin/system/id=1');
  await page.locator('[name="newEnvironmentName"]').fill('regression-env');
  await page.locator('button[name="group"][value="newEnvironment"]').click();
  await expect(panel(page, 'Available Environments').locator('input[value="regression-env"]')).toBeVisible();
  await page.locator('[name="newEnvironmentName"]').fill('regression-env');
  await page.locator('button[name="group"][value="newEnvironment"]').click();
  await expect(page.locator('body')).toContainText('Key already used!');
  await page.goto('/admin/system/id=1');
  await clickAndAccept(page, () => page.locator('a[href*="/deleteEnvironment=regression-env/"]').click());
  await expect(panel(page, 'Available Environments').locator('input[value="regression-env"]')).toHaveCount(0);
});

for (const definition of [
  { feature: 'SYSTEM-08', title: /Additional Setup Settings/, modal: '#newSetupOptionModal', save: 'setupOptions', add: 'newSetupOption', remove: 'deleteSetupOption' },
  { feature: 'SYSTEM-09', title: /Additional Evaluation Settings/, modal: '#newEvaluationSettingModal', save: 'settings', add: 'newSetting', remove: 'deleteEvaluationSetting' },
]) {
  test(`${definition.feature}: add, edit and delete a system key/value setting`, async ({ page }) => {
    await login(page);
    await page.goto('/admin/system/id=1');
    await panel(page, definition.title).getByRole('button', { name: 'Add New Setting' }).click();
    const modal = page.locator(definition.modal);
    await modal.locator('[name="settingKey"]').fill('regressionSetting');
    await modal.locator('[name="settingValue"]').fill('initial-value');
    await modal.locator(`button[name="group"][value="${definition.add}"]`).click();
    await expect(panel(page, definition.title).locator('[name="regressionSetting"]')).toHaveValue('initial-value');
    await panel(page, definition.title).locator('[name="regressionSetting"]').fill('changed-value');
    await panel(page, definition.title).locator(`button[name="group"][value="${definition.save}"]`).click();
    await page.reload();
    await expect(panel(page, definition.title).locator('[name="regressionSetting"]')).toHaveValue('changed-value');
    await clickAndAccept(page, () => page.locator(`a[href*="/${definition.remove}=regressionSetting/"]`).click());
    await expect(panel(page, definition.title).locator('[name="regressionSetting"]')).toHaveCount(0);
  });
}

test('SYSTEM-10: saved warm-up default propagates to new experiment creation', async ({ page }) => {
  await login(page);
  await page.goto('/admin/system/id=1');
  await page.locator('[name="default_environment"]').selectOption('local');
  await page.locator('[name="default_phase_warmUp"]').selectOption('unchecked');
  await page.locator('button[name="group"][value="defaultValues"]').click();
  await page.reload();
  await expect(page.locator('[name="default_environment"]')).toHaveValue('local');
  await expect(page.locator('[name="default_phase_warmUp"]')).toHaveValue('unchecked');
  await page.goto('/builder/create/projectId=1');
  await expect(page.locator('input[type="checkbox"][name="phase_warmUp"]')).not.toBeChecked();
});

test('SYSTEM-11: CEM dialog toggles setup capabilities and reports the current state', async ({ page }) => {
  await login(page);
  await page.goto('/admin/system/id=1');
  await page.getByRole('button', { name: /CEM$/ }).click();
  await expect(page.locator('#modal-cem')).toContainText('enabled');
  await page.locator('#modal-cem').getByRole('button', { name: /disable$/ }).click();
  await expect(panel(page, /Additional Setup Settings/)).toHaveCount(0);
  await page.getByRole('button', { name: /CEM$/ }).click();
  await expect(page.locator('#modal-cem')).toContainText('disabled');
  await page.locator('#modal-cem').getByRole('button', { name: /enable$/ }).click();
  await expect(panel(page, /Additional Setup Settings/)).toBeVisible();
});

test('SYSTEM-12, SYSTEM-13: system integration identifier and revision history dialogs work', async ({ page }) => {
  await login(page);
  await page.goto('/admin/system/id=1');
  await page.getByRole('button', { name: /System ID$/ }).click();
  await expect(page.locator('#modal-systemid code')).not.toBeEmpty();
  await page.locator('#modal-systemid').getByRole('button', { name: /Close$/ }).last().click();
  await page.getByRole('button', { name: /History$/ }).click();
  await expect(page.locator('#modal-history')).toBeVisible();
  await expect.poll(async () => page.locator('#gitGraph').evaluate((canvas: HTMLCanvasElement) => {
    const pixels = canvas.getContext('2d')?.getImageData(0, 0, canvas.width, canvas.height).data;
    return pixels ? pixels.some(value => value !== 0) : false;
  })).toBeTruthy();
  await page.locator('#modal-history').getByRole('button', { name: /Close$/ }).last().click();
  await expect(page.locator('#modal-history')).not.toBeVisible();
});

test('SYSTEM-15: upload a supported logo and reject an unsupported file', async ({ page }) => {
  await login(page);
  await page.goto('/admin/system/id=1');
  await page.getByRole('button', { name: /Upload Logo$/ }).click();
  await page.locator('#logoUpload').setInputFiles({
    name: 'regression-logo.png', mimeType: 'image/png',
    buffer: Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jWQAAAABJRU5ErkJggg==', 'base64'),
  });
  await page.locator('#modal-logo').getByRole('button', { name: /Upload$/ }).click();
  const downloaded = await page.request.get('/systems/1/logo.png');
  expect(downloaded.ok()).toBeTruthy();
  expect((await downloaded.body()).subarray(0, 8)).toEqual(Buffer.from([137, 80, 78, 71, 13, 10, 26, 10]));
  await page.getByRole('button', { name: /Upload Logo$/ }).click();
  await page.locator('#logoUpload').setInputFiles({ name: 'invalid.txt', mimeType: 'text/plain', buffer: Buffer.from('not an image') });
  await page.locator('#modal-logo').getByRole('button', { name: /Upload$/ }).click();
  await expect(page.locator('body')).toContainText('Invalid file type!');
});

test('SYSTEM-17: import parameter definitions and render the imported experiment input', async ({ page }) => {
  await login(page);
  await page.goto('/admin/system/id=1');
  await page.getByRole('button', { name: /Import$/ }).click();
  await page.locator('#inputFile').setInputFiles({
    name: 'parameters.json', mimeType: 'application/json',
    buffer: Buffer.from(JSON.stringify([{
      id: 'import-group', title: 'Imported Parameters', depends: '', dependsValue: '',
      elements: [{ id: 'import-field', type: 'string', name: 'Imported label', parameter: 'imported', default: 'Zürich value' }],
    }])),
  });
  await page.locator('#modal-import').getByRole('button', { name: /Upload$/ }).click();
  await page.goto('/builder/create/projectId=1');
  await expect(page.getByRole('heading', { name: 'Imported Parameters' })).toBeVisible();
  await expect(page.locator('[name="imported"]')).toHaveValue('Zürich value');
});

test('SYSTEM-18, PARAM-01, PARAM-02, PARAM-03: add/edit/delete parameter groups and persist builder changes', async ({ page }) => {
  await login(page);
  await page.goto('/admin/system/id=1');
  await page.getByRole('button', { name: /Configure Parameters$/ }).click();
  await expect(page.locator('.content-header h1')).toContainText('System Parameter builder');
  await page.locator('[data-target="#modal-group"]').click();
  await page.locator('#group-form [name="name"]').fill('RegressionGroup');
  await page.locator('#group-form [name="depends"]').fill('mode');
  await page.locator('#group-form [name="dependsValue"]').fill('advanced');
  await page.locator('#modal-group').getByRole('button', { name: /Add$/ }).click();
  const group = panel(page, 'RegressionGroup');
  await expect(group).toContainText('Depends on mode has value advanced');
  await group.getByRole('button', { name: /Add Element$/ }).click();
  await page.locator('#element-form [name="type"]').selectOption('string');
  await page.locator('#modal-element').getByRole('button', { name: /Add$/ }).click();
  await group.locator('input[name="name"]').fill('Regression Label');
  await group.locator('input[name="parameter"]').fill('regression');
  await group.locator('input[name="default"]').fill('Zürich default');
  await page.getByRole('button', { name: /Save$/ }).click();
  await expect(page.locator('.content-header h1')).toContainText('System:');
  await page.goto('/builder/build/id=1');
  await expect(panel(page, 'RegressionGroup').locator('input[name="default"]')).toHaveValue('Zürich default');
  await panel(page, 'RegressionGroup').locator('input[name="default"]').fill('unsaved');
  await page.getByRole('button', { name: /Reset$/ }).click();
  await expect(panel(page, 'RegressionGroup').locator('input[name="default"]')).toHaveValue('Zürich default');
  await clickAndAccept(page, () => panel(page, 'RegressionGroup').getByRole('link', { name: /Delete$/ }).click());
  await expect(panel(page, 'RegressionGroup').locator('input[name="type"]')).toHaveCount(0);
  await clickAndAccept(page, () => panel(page, 'RegressionGroup').getByRole('button', { name: /Delete Group$/ }).click());
  await expect(panel(page, 'RegressionGroup')).toHaveCount(0);
  await page.getByRole('button', { name: /Save$/ }).click();
  await expect(page.locator('.content-header h1')).toContainText('System: Fixture System');
  await page.goto('/builder/build/id=1');
  await expect(panel(page, 'RegressionGroup')).toHaveCount(0);
});

test('SYSTEM-19: create/copy/rename/delete results definitions and protect a configuration in use', async ({ page }) => {
  await login(page);
  await page.goto('/admin/system/id=1');
  const builder = panel(page, 'System Builder');
  await builder.locator('button[name="newResult"]').click();
  const newRow = panel(page, 'System Builder').locator('tr').filter({ has: page.locator('[name="resultId"][value="system-2"]') });
  await newRow.getByRole('button', { name: /Rename$/ }).click();
  await page.locator('#modal-rename-system-2 [name="newName"]').fill('Regression Results');
  await page.locator('#modal-rename-system-2').getByRole('button', { name: /Rename$/ }).click();
  await expect(panel(page, 'System Builder')).toContainText('Regression Results');
  await page.waitForLoadState('load');
  const copyButton = panel(page, 'System Builder').locator('tr').filter({ has: page.locator('[name="resultId"][value="system-2"]') })
    .getByRole('button', { name: /Copy$/ });
  if (test.info().project.use.hasTouch) await copyButton.tap(); else await copyButton.click();
  await expect(panel(page, 'System Builder').locator('[name="resultId"][value="system-3"]').first()).toBeAttached();
  const copy = panel(page, 'System Builder').locator('tr').filter({ has: page.locator('[name="resultId"][value="system-3"]') });
  await clickAndAccept(page, () => copy.getByRole('button', { name: /Delete$/ }).click());
  await expect(panel(page, 'System Builder').locator('[name="resultId"][value="system-3"]')).toHaveCount(0);
  const inUse = panel(page, 'System Builder').locator('tr').filter({ has: page.locator('[name="resultId"][value="system-1"]') });
  await clickAndAccept(page, () => inUse.getByRole('button', { name: /Delete$/ }).click());
  await expect(page.locator('body')).toContainText('You cannot delete this result, as it is used in experiments');
});
