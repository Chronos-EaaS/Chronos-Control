import { test, expect, login, fitsViewport } from './fixture';
import { observeLive } from './live-helper';

test('LIVE-01: real pushed job snapshots retain drafts and produce no automatic HTTP reads', async ({ page }) => {
  const live = observeLive(page);
  let heartbeats = 0;
  page.on('websocket', socket => socket.on('framereceived', frame => {
    try { if (JSON.parse(frame.payload.toString()).type === 'heartbeat') heartbeats++; } catch { /* Ignore non-JSON frames. */ }
  }));
  await login(page); await page.goto('/jobs/1');
  await live.next('/jobs/1'); await live.next('/jobs/1/log');
  await expect(page.getByLabel('Description', { exact: true })).toBeVisible();
  const session = (await (await page.request.get('/api/ui/session')).json()).data;
  const headers = { 'X-CSRF-Token': session.csrfToken, Origin: new URL(page.url()).origin };
  const reads: string[] = [];
  page.on('request', request => {
    const path = new URL(request.url()).pathname;
    if (request.method() === 'GET' && path.startsWith('/api/ui/')) reads.push(path);
  });
  const first = live.next('/jobs/1', value => value.data.description === 'Changed in another session', live.mark());
  expect((await page.request.patch('/api/ui/jobs/1', { headers, data: { description: 'Changed in another session' } })).ok()).toBe(true);
  await first; await expect(page.getByLabel('Description', { exact: true })).toHaveValue('Changed in another session');
  await page.getByLabel('Description', { exact: true }).fill('Unsaved local draft');
  const second = live.next('/jobs/1', value => value.data.description === 'A second external change', live.mark());
  expect((await page.request.patch('/api/ui/jobs/1', { headers, data: { description: 'A second external change' } })).ok()).toBe(true);
  await second; await expect(page.getByLabel('Description', { exact: true })).toHaveValue('Unsaved local draft');
  const previousBeats = heartbeats;
  await expect.poll(() => heartbeats, { timeout: 20_000 }).toBeGreaterThan(previousBeats);
  expect(reads, 'Healthy pushed views must not issue HTTP refresh or health requests.').toEqual([]);
  await fitsViewport(page);
});

test('LIVE-02: a missing heartbeat probes once, shows a centered notice over a result dialog, and recovers on heartbeat', async ({ page }) => {
  let suppress = false;
  let heartbeat = '';
  let receivedHeartbeats = 0;
  let deliver: ((message: string) => void) | undefined;
  await page.routeWebSocket('**/api/ui/live', socket => {
    const server = socket.connectToServer();
    server.onMessage(message => {
      let frame: any; try { frame = JSON.parse(message.toString()); } catch { socket.send(message); return; }
      if (frame.type === 'heartbeat') { receivedHeartbeats++; heartbeat = message.toString(); deliver = value => socket.send(value); if (suppress) return; }
      socket.send(message);
    });
    socket.onMessage(message => server.send(message));
  });
  await login(page); await page.goto('/jobs/5');
  await page.getByRole('button', { name: 'Results', exact: true }).click();
  const dialog = page.getByRole('dialog', { name: 'Job results', exact: true });
  await expect(dialog.locator('app-results-explorer')).toContainText('Raw job results');
  await expect.poll(() => heartbeat).not.toBe('');
  let probes = 0;
  await page.route('**/api/ui/live/health', route => { probes++; return route.abort('failed'); });
  await page.clock.install(); suppress = true;
  await page.clock.fastForward(40_000); expect(probes).toBe(0);
  await page.clock.fastForward(6_000);
  const notice = page.getByRole('status').filter({ hasText: 'Chronos cannot be reached.' });
  await expect(notice).toBeVisible(); expect(probes).toBe(1);
  const bounds = (await notice.boundingBox())!, viewport = page.viewportSize()!;
  expect(Math.abs(bounds.x + bounds.width / 2 - viewport.width / 2)).toBeLessThanOrEqual(2);
  expect(bounds.y).toBeGreaterThan(viewport.height / 2);
  expect(await notice.evaluate(element => element.matches(':popover-open'))).toBe(true);
  const beforeReconnect = receivedHeartbeats;
  await page.unroute('**/api/ui/live/health');
  await page.clock.fastForward(5_000);
  await expect.poll(() => receivedHeartbeats).toBeGreaterThan(beforeReconnect);
  await expect(notice).toBeVisible();
  // This real transport fault resumes the last server heartbeat; neither a data
  // frame nor a successful health response is allowed to dismiss the notice.
  suppress = false; deliver!(heartbeat);
  await expect(notice).not.toBeVisible(); await expect(dialog).toBeVisible();
  await fitsViewport(page);
});

test('LIVE-03: logging out in another tab revokes the open socket session', async ({ page }) => {
  await login(page); await page.goto('/jobs/1');
  await expect(page.getByLabel('Description', { exact: true })).toBeVisible();
  const session = (await (await page.request.get('/api/ui/session')).json()).data;
  expect((await page.request.delete('/api/ui/session', { headers: { 'X-CSRF-Token': session.csrfToken, Origin: new URL(page.url()).origin } })).ok()).toBe(true);
  await expect(page).toHaveURL(/\/login(?:\?|$)/, { timeout: 20_000 });
  await expect(page.getByRole('button', { name: 'Sign in', exact: true })).toBeVisible();
  await expect(page.getByLabel('Description', { exact: true })).toHaveCount(0);
});
