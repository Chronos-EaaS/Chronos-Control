import { test, expect, login, fitsViewport } from './fixture';
import type { Request } from '@playwright/test';

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
  await page.addInitScript(() => {
    const consumed: { id: string; body: string }[] = [];
    Object.defineProperty(window, '__chronosConsumedStalePolls', { value: consumed });
    const originalFetch = window.fetch.bind(window);
    window.fetch = async (...args) => {
      const response = await originalFetch(...args);
      const id = response.headers.get('x-chronos-test-stale-poll');
      const stream = response.body;
      if (id && stream) {
        // Observe the exact reader used by Angular, without cloning or consuming
        // another stream. Chromium may omit CDP completion after body delivery.
        const originalGetReader: () => ReadableStreamDefaultReader<Uint8Array> = stream.getReader.bind(stream);
        Object.defineProperty(stream, 'getReader', { value: () => {
          const reader = originalGetReader(); const originalRead = reader.read.bind(reader);
          const chunks: Uint8Array[] = [];
          Object.defineProperty(reader, 'read', { value: async () => {
            const result = await originalRead();
            if (result.value) chunks.push(result.value.slice());
            if (result.done) {
              const body = new Uint8Array(chunks.reduce((size, chunk) => size + chunk.length, 0));
              let offset = 0; for (const chunk of chunks) { body.set(chunk, offset); offset += chunk.length; }
              consumed.push({ id, body: new TextDecoder().decode(body) });
            }
            return result;
          } });
          return reader;
        } });
      }
      return response;
    };
  });
  await login(page);
  for (const item of [
    { route: '/evaluations/1', path: '/api/v3/evaluations/1', label: 'Name', button: 'Save evaluation', value: 'Saved evaluation after polling', success: 'Evaluation saved.' },
    { route: '/jobs/1', path: '/api/v3/jobs/1', label: 'Description', button: 'Save description', value: 'Saved job after polling', success: 'Job description saved.' },
  ]) {
    await page.goto(item.route); const input = page.getByLabel(item.label, { exact: true }); await expect(input).toBeVisible();
    const field = item.path.includes('evaluations') ? 'name' : 'description';
    const original = (await (await page.request.get(item.path)).json()).data[field];
    expect(original).not.toBe(item.value);
    let release!: () => void; let releaseFollowing!: () => void; let started!: (capture: { body: Buffer; request: Request }) => void; let captureFailed!: (error: unknown) => void; let held = false;
    const gate = new Promise<void>(resolve => { release = resolve; });
    const followingGate = new Promise<void>(resolve => { releaseFollowing = resolve; });
    const intercepted = new Promise<{ body: Buffer; request: Request }>((resolve, reject) => { started = resolve; captureFailed = reject; });
    await page.route(`**${item.path}`, async route => {
      if (route.request().method() !== 'GET') { await route.continue(); return; }
      try {
        // A later fresh poll must not hide a stale overwrite before assertions.
        if (held) { await followingGate; await route.continue(); return; }
        held = true;
        const response = await route.fetch(); const body = await response.body(); const status = response.status();
        expect(status).toBe(200);
        // Own the captured bytes before PATCH and identify this exact response
        // for the passive browser-side body-consumption observer.
        const headers = { ...response.headers() };
        delete headers['content-length']; delete headers['content-encoding']; delete headers['transfer-encoding'];
        headers['x-chronos-test-stale-poll'] = item.path;
        started({ body, request: route.request() }); await gate;
        await route.fulfill({ status, headers, body });
      } catch (error) { captureFailed(error); if (!page.isClosed()) throw error; }
    });
    try {
      const captured = await test.step(`Capture a real pre-save poll for ${item.route}`, () => intercepted, { timeout: 10_000 });
      expect(JSON.parse(captured.body.toString('utf8')).data[field]).toBe(original);
      await input.fill(item.value);
      const saving = page.waitForResponse(response => new URL(response.url()).pathname === item.path && response.request().method() === 'PATCH');
      await page.getByRole('button', { name: item.button, exact: true }).click(); expect((await saving).status()).toBe(200);
      await expect(page.getByRole('status').filter({ hasText: item.success })).toBeVisible();
      const oldPoll = page.waitForResponse(response => response.request() === captured.request);
      release();
      await test.step(`Consume the buffered stale response for ${item.route}`, async () => {
        expect((await oldPoll).status()).toBe(200);
        const observation = await page.waitForFunction(id => (window as Window & {
          __chronosConsumedStalePolls?: { id: string; body: string }[];
        }).__chronosConsumedStalePolls?.find(item => item.id === id), item.path);
        expect(await observation.jsonValue()).toEqual({ id: item.path, body: captured.body.toString('utf8') });
      }, { timeout: 8_000 });
      await page.evaluate(() => new Promise<void>(resolve => requestAnimationFrame(() => requestAnimationFrame(() => resolve()))));
      await expect(input).toHaveValue(item.value);
      const saved = (await (await page.request.get(item.path)).json()).data;
      expect(saved[field]).toBe(item.value);
    } finally {
      release(); releaseFollowing();
      if (!page.isClosed()) await page.unroute(`**${item.path}`).catch(error => { if (!page.isClosed()) throw error; });
    }
  }
  await fitsViewport(page);
});
