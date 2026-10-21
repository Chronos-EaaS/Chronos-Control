import { test, expect, login, fitsViewport } from './fixture';
import { observeLive } from './live-helper';

test('AUTH-04/ADMIN-03: discard is resolved before changing the signed-in identity', async ({ page }) => {
  await login(page, 'admin'); await page.goto('/admin');
  const settings = page.locator('section.card').filter({ has: page.getByRole('heading', { name: 'Other settings', exact: true }) });
  await settings.getByLabel('Rows Per Page').fill('17');
  await page.getByRole('button', { name: 'Switch to member', exact: true }).click();
  const dialog = page.getByRole('dialog', { name: 'Switch account', exact: true });
  const switches: string[] = []; page.on('request', request => { if (request.method() === 'POST' && new URL(request.url()).pathname === '/api/ui/session/impersonation') switches.push(request.url()); });
  const declined = page.waitForEvent('dialog'); page.once('dialog', prompt => prompt.dismiss());
  await dialog.getByRole('button', { name: 'Switch account', exact: true }).click(); await declined;
  await expect(dialog).toBeVisible(); expect(switches).toHaveLength(0);
  expect((await (await page.request.get('/api/ui/session')).json()).data.user.username).toBe('admin');
  await expect(settings.getByLabel('Rows Per Page')).toHaveValue('17');
  const accepted = page.waitForEvent('dialog'); page.once('dialog', prompt => prompt.accept());
  await dialog.getByRole('button', { name: 'Switch account', exact: true }).click(); await accepted;
  await expect(page.locator('.impersonation-banner')).toContainText('as member'); expect(switches).toHaveLength(1);
  expect((await (await page.request.get('/api/ui/session')).json()).data.user.username).toBe('member');
  await fitsViewport(page);
});

test('EVALUATION-03/JOB-02: a queued pre-save pushed snapshot cannot restore obsolete metadata', async ({ page }) => {
  // Gate actual server message delivery inside the browser, leaving the native
  // socket and every client/server frame intact for the passive observer.
  await page.addInitScript(() => {
    type Gate = { path: string | null; held?: { raw: string; release: () => void }; delivered: string[] };
    const gate: Gate = { path: null, delivered: [] };
    Object.defineProperty(window, '__chronosFrameGate', { value: gate });
    const NativeSocket = window.WebSocket;
    class GatedSocket extends NativeSocket {
      private readonly paths = new Map<string, string>();
      constructor(url: string | URL, protocols?: string | string[]) {
        super(url, protocols);
        this.addEventListener('message', event => {
          if (typeof event.data !== 'string') return;
          let frame: any; try { frame = JSON.parse(event.data); } catch { return; }
          if (frame.type !== 'data' || !gate.path || this.paths.get(frame.id) !== gate.path || gate.held) return;
          event.stopImmediatePropagation();
          const raw = event.data;
          gate.held = { raw, release: () => {
            gate.path = null;
            this.dispatchEvent(new MessageEvent('message', { data: raw }));
            gate.delivered.push(raw);
          } };
        });
      }
      override send(data: string | Blob | BufferSource): void {
        if (typeof data === 'string') {
          try { const frame = JSON.parse(data); if (frame.type === 'subscribe') this.paths.set(frame.id, frame.path); } catch { /* Binary/non-JSON frames are unaffected. */ }
        }
        super.send(data);
      }
    }
    window.WebSocket = GatedSocket;
  });
  const live = observeLive(page);
  await login(page);
  const session = (await (await page.request.get('/api/ui/session')).json()).data;
  for (const item of [
    { route: '/evaluations/1', path: '/evaluations/1', label: 'Name', button: 'Save evaluation', value: 'Saved evaluation after queued push', success: 'Evaluation saved.', triggerJobId: 3, triggerProgress: 100 },
    { route: '/jobs/1', path: '/jobs/1', label: 'Description', button: 'Save description', value: 'Saved job after queued push', success: 'Job description saved.', triggerJobId: 1, triggerProgress: 60 },
  ]) {
    await page.goto(item.route); const input = page.getByLabel(item.label, { exact: true }); await expect(input).toBeVisible();
    const field = item.path.includes('evaluations') ? 'name' : 'description';
    const originalProjection = (await (await page.request.get(`/api/ui${item.path}`)).json()).data;
    const original = originalProjection[field];
    expect(original).not.toBe(item.value);
    const saveButton = page.getByRole('button', { name: item.button, exact: true });
    await expect(input).toHaveValue(original); await expect(saveButton).toBeDisabled();
    await input.fill(item.value); await expect(saveButton).toBeEnabled();
    await page.evaluate(path => { (window as any).__chronosFrameGate.path = path; }, item.path);
    // Scheduled/finished jobs ignore raw progress in the evaluation aggregate.
    // A large enough running-stage change must also survive whole-percent rounding.
    const changed = await page.request.patch(`/api/ui/jobs/${item.triggerJobId}`, { data: { progress: item.triggerProgress }, headers: { 'X-CSRF-Token': session.csrfToken } });
    expect(changed.ok()).toBe(true);
    await page.waitForFunction(() => Boolean((window as any).__chronosFrameGate.held));
    const captured = await page.evaluate(() => (window as any).__chronosFrameGate.held.raw as string);
    expect(JSON.parse(captured).data.progress).not.toBe(originalProjection.progress);
    expect(JSON.parse(captured).data[field]).toBe(original);
    await expect(input).toHaveValue(item.value);
    const saving = page.waitForResponse(response => new URL(response.url()).pathname === `/api/ui${item.path}` && response.request().method() === 'PATCH');
    await saveButton.click(); expect((await saving).status()).toBe(200);
    await expect(page.getByRole('status').filter({ hasText: item.success })).toBeVisible();
    const fresh = await live.next(item.path, frame => frame.data[field] === item.value);
    expect(fresh.id).not.toBe(JSON.parse(captured).id);
    await page.evaluate(() => (window as any).__chronosFrameGate.held.release());
    expect(await page.evaluate(() => (window as any).__chronosFrameGate.delivered)).toContain(captured);
    await page.evaluate(() => new Promise<void>(resolve => requestAnimationFrame(() => requestAnimationFrame(() => resolve()))));
    await expect(input).toHaveValue(item.value);
    expect((await (await page.request.get(`/api/ui${item.path}`)).json()).data[field]).toBe(item.value);
  }
  await fitsViewport(page);
});
