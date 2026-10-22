import { execFileSync } from 'node:child_process';
import { v3Stack } from '../paths';
import { test, expect, login, fitsViewport, waitForLiveBuild } from './fixture';

function build(action: 'newer' | 'restore' | 'maintenance' | 'resume'): void {
  execFileSync(v3Stack, ['ui-build', action], { timeout: 15_000, stdio: 'pipe' });
}

test('VERSION-01: a maintenance restart announces a heartbeat build on the results page and above CDL despite failed metadata, protecting draft navigation', async ({ page }) => {
  test.setTimeout(90_000);
  test.skip(process.env['CHRONOS_V3_PREVIEW'] === '1', 'Deployment version notices require the compiled local fixture.');
  build('restore');
  await page.clock.install();
  let failMetadata = false, metadataErrors = 0, latestHeartbeat = '';
  await page.routeWebSocket('**/api/ui/live', socket => {
    const server = socket.connectToServer(), metadataIds = new Set<string>();
    socket.onMessage(message => {
      const frame = JSON.parse(message.toString());
      if (frame.type === 'subscribe' && frame.path === '/meta') metadataIds.add(frame.id);
      if (frame.type === 'unsubscribe') metadataIds.delete(frame.id);
      server.send(message);
    });
    server.onMessage(message => {
      const frame = JSON.parse(message.toString());
      if (frame.type === 'heartbeat') latestHeartbeat = frame.buildId;
      if (failMetadata && frame.type === 'data' && metadataIds.has(frame.id)) {
        ++metadataErrors;
        socket.send(JSON.stringify({ type: 'error', id: frame.id, status: 503, error: { code: 'live_unavailable', message: 'Synthetic unavailable metadata.' } }));
        return;
      }
      socket.send(message);
    });
  });
  await login(page); await page.goto('/jobs/5');
  const originalBuild = (await (await page.request.get('/api/ui/meta')).json()).data.uiBuildId;
  await expect.poll(() => latestHeartbeat).toBe(originalBuild);
  await page.getByRole('link', { name: 'Results', exact: true }).click();
  await expect(page).toHaveURL(/\/jobs\/5\/results$/);
  const results = page.getByRole('heading', { name: /^Job results/, level: 1 });
  await expect(results).toBeVisible();
  await expect(page.locator('app-results-explorer').getByLabel('Result configuration', { exact: true })).toHaveValue('system-1');
  const update = page.getByRole('complementary', { name: 'Application update', exact: true });
  await expect(update).toHaveCount(0);
  const reads: string[] = [];
  page.on('request', request => { if (request.method() === 'GET' && new URL(request.url()).pathname.startsWith('/api/ui/')) reads.push(new URL(request.url()).pathname); });
  try {
    // Self-update enters maintenance before replacing files, so its daemon stops
    // before the ordinary build-file-change announcement can run.
    build('maintenance');
    await expect.poll(async () => (await page.request.get('/api/ui/live/health')).status()).toBe(503);
    await page.clock.fastForward(46_000);
    const connection = page.getByRole('status').filter({ hasText: 'Chronos cannot be reached.' });
    await expect(connection).toBeVisible();
    failMetadata = true;
    build('newer'); build('resume');
    await waitForLiveBuild(page, page.url(), 'f'.repeat(40));
    await page.clock.fastForward(5_000);
    await expect.poll(() => latestHeartbeat).toBe('f'.repeat(40));
    await expect.poll(() => metadataErrors).toBeGreaterThan(0);
    await expect(connection).not.toBeVisible();
    await expect(update).toBeVisible(); await expect(results).toBeVisible();
    expect(await update.evaluate(element => element.matches(':popover-open'))).toBe(true);
    expect(await update.evaluate(element => element.closest('dialog'))).toBeNull();
    const centered = await update.evaluate(element => {
      const rect = element.getBoundingClientRect();
      return { center: Math.abs(rect.x + rect.width / 2 - innerWidth / 2), bottom: rect.bottom, top: rect.top, height: innerHeight };
    });
    expect(centered.center).toBeLessThanOrEqual(2); expect(centered.bottom).toBeLessThan(centered.height); expect(centered.top).toBeGreaterThan(centered.height / 2);
    const reload = update.getByRole('button', { name: 'Load latest version', exact: true });
    await reload.focus(); await expect(reload).toBeFocused();
    await page.getByRole('link', { name: /Job details/ }).click();
    await expect(page).toHaveURL(/\/jobs\/5$/);
    await expect(update).toBeVisible();
    await page.getByLabel('Description', { exact: true }).fill('Unsaved deployment draft');
    const previousUrl = page.url();
    page.once('dialog', dialog => dialog.dismiss());
    await page.getByRole('link', { name: 'Results', exact: true }).click();
    await expect(page).toHaveURL(previousUrl);
    await expect(page.getByLabel('Description', { exact: true })).toHaveValue('Unsaved deployment draft');
    await reload.focus(); await expect(reload).toBeFocused();
    page.once('dialog', dialog => dialog.dismiss()); await page.keyboard.press('Enter');
    expect(page.url()).toBe(previousUrl);
    await expect(page.getByLabel('Description', { exact: true })).toHaveValue('Unsaved deployment draft');
    await page.getByRole('button', { name: 'View CDL', exact: true }).click();
    const cdl = page.getByRole('dialog', { name: 'Chronos Description Language', exact: true });
    await expect(cdl).toBeVisible();
    await expect.poll(() => update.evaluate(element => element.closest('dialog')?.getAttribute('aria-labelledby'))).toBe('cdl-title');
    page.once('dialog', dialog => dialog.dismiss()); await reload.click();
    await expect(page.getByLabel('Description', { exact: true })).toHaveValue('Unsaved deployment draft');
    expect(reads.filter(path => path === '/api/ui/meta')).toEqual([]);
    expect(reads.filter(path => path === '/api/ui/live/health').length).toBeGreaterThan(0);
    await fitsViewport(page);
    // Cross-tab logout destroys the routed job and its open dialog. The global
    // prompt must return home even if no new build or metadata event follows.
    const session = (await (await page.request.get('/api/ui/session')).json()).data;
    page.once('dialog', dialog => dialog.accept());
    const logout = await page.request.delete('/api/ui/session', {
      headers: { Origin: new URL(page.url()).origin, 'X-CSRF-Token': session.csrfToken },
    });
    expect(logout.ok()).toBe(true);
    await expect(page).toHaveURL(/\/login(?:\?|$)/, { timeout: 20_000 });
    await expect(page.getByRole('button', { name: 'Sign in', exact: true })).toBeVisible();
    await expect(update).toBeVisible();
    expect(await update.evaluate(element => element.isConnected && element.closest('dialog') === null)).toBe(true);
  } finally {
    failMetadata = false; build('restore');
    await waitForLiveBuild(page, page.url(), originalBuild);
  }
});
