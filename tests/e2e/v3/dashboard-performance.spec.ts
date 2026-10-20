import { test, expect, fitsViewport } from './fixture';
import { observeLive } from './live-helper';

test('HOME-03: a cold authenticated dashboard shares one socket and performs no metadata or dashboard HTTP reads', async ({ page, baseURL }) => {
  // Seed only this disposable browser context's cookie, without loading an
  // application document that could hide duplicate startup connections.
  const sessionResponse = await page.request.get('/api/ui/session');
  expect(sessionResponse.ok()).toBe(true);
  const session = (await sessionResponse.json()).data;
  const login = await page.request.post('/api/ui/session', {
    headers: { Origin: new URL(baseURL!).origin, 'X-CSRF-Token': session.csrfToken },
    data: { username: 'admin', password: 'Chronos-test-2026!', remember: false },
  });
  expect(login.ok()).toBe(true);

  const requests: string[] = [];
  let sockets = 0;
  page.on('request', request => {
    const path = new URL(request.url()).pathname;
    if (path.startsWith('/api/ui/')) requests.push(`${request.method()} ${path}`);
  });
  page.on('websocket', socket => { if (new URL(socket.url()).pathname === '/api/ui/live') ++sockets; });
  const live = observeLive(page);
  await page.goto('/');
  const [dashboard, metadata] = await Promise.all([live.next('/dashboard'), live.next('/meta'), live.next('/session')]);
  await expect(page.locator('.dashboard-metrics .info-box')).toHaveCount(7);
  await expect(page.locator('.app-footer')).toContainText(`Version ${metadata.data.applicationVersion}`);
  await expect(page.getByLabel('Overview scope', { exact: true })).toBeEnabled();

  const all = live.next('/dashboard', frame => frame.query['all'] === 'true', live.mark());
  await page.getByLabel('Overview scope', { exact: true }).selectOption({ label: 'All users' });
  const updated = await all;
  expect(updated.data.counts.projects).toBeGreaterThanOrEqual(dashboard.data.counts.projects);
  await expect(page.locator('[data-metric="projects"] .info-box-number')).toHaveText(String(updated.data.counts.projects));
  expect(sockets, 'Identity lookup must precede the initial socket instead of replacing an anonymous connection.').toBe(1);
  expect(live.active('/meta')).toHaveLength(1);
  expect(live.subscriptions.filter(subscription => subscription.path === '/meta')).toHaveLength(1);
  expect(requests, 'Startup reads the identity once; metadata and dashboard scope changes are pushed.').toEqual(['GET /api/ui/session']);
  await fitsViewport(page);
});
