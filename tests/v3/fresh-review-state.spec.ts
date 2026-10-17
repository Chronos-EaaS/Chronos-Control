import { test, expect, login, fitsViewport } from './fixture';

test('AUTH-04/ADMIN-03: discard is resolved before changing the signed-in identity', async ({ page }) => {
  await login(page, 'admin'); await page.goto('/admin');
  const settings = page.locator('section.card').filter({ has: page.getByRole('heading', { name: 'Other settings', exact: true }) });
  await settings.getByLabel('Rows Per Page').fill('17');
  await page.getByRole('button', { name: 'Switch to member', exact: true }).click();
  const dialog = page.getByRole('dialog', { name: 'Switch account', exact: true });
  const switches: string[] = []; page.on('request', request => { if (request.method() === 'POST' && new URL(request.url()).pathname === '/api/v3/session/impersonation') switches.push(request.url()); });
  const declined = page.waitForEvent('dialog'); page.once('dialog', prompt => prompt.dismiss());
  await dialog.getByRole('button', { name: 'Switch account', exact: true }).click(); await declined;
  await expect(dialog).toBeVisible(); expect(switches).toHaveLength(0);
  expect((await (await page.request.get('/api/v3/session')).json()).data.user.username).toBe('admin');
  await expect(settings.getByLabel('Rows Per Page')).toHaveValue('17');
  const accepted = page.waitForEvent('dialog'); page.once('dialog', prompt => prompt.accept());
  await dialog.getByRole('button', { name: 'Switch account', exact: true }).click(); await accepted;
  await expect(page.locator('.impersonation-banner')).toContainText('as member'); expect(switches).toHaveLength(1);
  expect((await (await page.request.get('/api/v3/session')).json()).data.user.username).toBe('member');
  await fitsViewport(page);
});

test('EVALUATION-03/JOB-02: polling that started before save cannot restore obsolete metadata', async ({ page }) => {
  await login(page);
  for (const item of [
    { route: '/evaluations/1', path: '/api/v3/evaluations/1', label: 'Name', button: 'Save evaluation', value: 'Saved evaluation after polling', success: 'Evaluation saved.' },
    { route: '/jobs/1', path: '/api/v3/jobs/1', label: 'Description', button: 'Save description', value: 'Saved job after polling', success: 'Job description saved.' },
  ]) {
    await page.goto(item.route); const input = page.getByLabel(item.label, { exact: true }); await expect(input).toBeVisible();
    let release!: () => void; let started!: () => void; let held = false;
    const gate = new Promise<void>(resolve => { release = resolve; }); const intercepted = new Promise<void>(resolve => { started = resolve; });
    await page.route(`**${item.path}`, async route => {
      if (route.request().method() !== 'GET' || held) { await route.continue(); return; }
      held = true; const response = await route.fetch(); started(); await gate; await route.fulfill({ response });
    });
    try {
      await intercepted; await input.fill(item.value);
      const saving = page.waitForResponse(response => new URL(response.url()).pathname === item.path && response.request().method() === 'PATCH');
      await page.getByRole('button', { name: item.button, exact: true }).click(); expect((await saving).status()).toBe(200);
      await expect(page.getByRole('status').filter({ hasText: item.success })).toBeVisible();
      const oldPoll = page.waitForResponse(response => new URL(response.url()).pathname === item.path && response.request().method() === 'GET');
      release(); await (await oldPoll).finished();
      await page.evaluate(() => new Promise<void>(resolve => requestAnimationFrame(() => requestAnimationFrame(() => resolve()))));
      await expect(input).toHaveValue(item.value);
      const saved = (await (await page.request.get(item.path)).json()).data;
      expect(item.path.includes('evaluations') ? saved.name : saved.description).toBe(item.value);
    } finally { release(); await page.unroute(`**${item.path}`); }
  }
  await fitsViewport(page);
});
