import { test, expect, login, fitsViewport } from './fixture';

test('SYSTEM-08/09: removing the final setup and evaluation setting persists after a full reload', async ({ page }) => {
  await login(page); await page.goto('/systems/1');
  const settingsBefore = await page.request.get('/api/ui/systems/1/settings'); expect(settingsBefore.status()).toBe(200);
  const original = (await settingsBefore.json()).data;
  for (const [section, heading, key] of [['general', 'Evaluation settings', 'fixtureSetting'], ['setup', 'Setup options', 'fixtureSetup']]) {
    await page.getByRole('navigation', { name: 'System sections', exact: true }).getByRole('button', { name: 'Settings and defaults', exact: true }).click();
    const card = page.locator('section.card').filter({ has: page.getByRole('heading', { name: heading, exact: true }) });
    await expect(card.getByLabel('Value', { exact: true })).toHaveCount(1);
    await card.getByRole('button', { name: `Remove setting ${key}`, exact: true }).click();
    await expect(card.getByLabel('Value', { exact: true })).toHaveCount(0);
    const saved = page.waitForResponse(response => new URL(response.url()).pathname === '/api/ui/systems/1/settings' && response.request().method() === 'PUT');
    await card.getByRole('button', { name: `Save ${heading}`, exact: true }).click();
    const response = await saved; expect(response.status()).toBe(200);
    expect(response.request().postDataJSON()).toEqual({ [section]: {} });
    await expect(page.getByRole('status').filter({ hasText: 'System settings saved.' })).toBeVisible();
    const persisted = await page.request.get('/api/ui/systems/1/settings'); expect(persisted.status()).toBe(200);
    const current = (await persisted.json()).data;
    expect(Object.keys(current[section])).toEqual([]); expect(current.defaultValues).toEqual(original.defaultValues);
    await page.reload();
    await page.getByRole('navigation', { name: 'System sections', exact: true }).getByRole('button', { name: 'Settings and defaults', exact: true }).click();
    await expect(card.getByLabel(key, { exact: true })).toHaveCount(0);
    await expect(card.getByLabel('Value', { exact: true })).toHaveCount(0);
  }
  await fitsViewport(page);
});
