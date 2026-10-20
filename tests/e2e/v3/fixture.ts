import { execFileSync } from 'node:child_process';
import { v3Stack } from '../paths';
import { test as playwright, expect, type Page } from '@playwright/test';

export const test = playwright.extend<{ localFixtureGuard: void }>({
  localFixtureGuard: [async ({ page, baseURL }, use) => {
    const target = new URL(baseURL ?? '');
    if (!['http://127.0.0.1:4200/', 'http://127.0.0.1:8089/'].includes(target.href)) throw new Error('Only the fixed, disposable v3 test stack is permitted.');
    execFileSync(v3Stack, ['reset'], { timeout: 30_000, stdio: 'pipe' });
    const health = await page.request.get('http://127.0.0.1:8089/healthz.txt');
    expect(health.ok()).toBeTruthy(); expect((await health.text()).trim()).toBe('chronos-test-ready');
    // A preceding deployment-version case can leave the daemon restarting after
    // the database reset is ready. Start browser assertions on the current build.
    await waitForLiveBuild(page, target);
    const errors: string[] = [];
    page.on('pageerror', error => errors.push(error.message));
    page.on('console', message => { if (message.type() === 'error' && /^ERROR\b/.test(message.text())) errors.push(message.text().split('\n')[0]); });
    await page.route('**/*', route => {
      const url = new URL(route.request().url());
      const sameOrigin = url.hostname === target.hostname && url.port === target.port
        && (url.protocol === target.protocol || (url.protocol === 'ws:' && target.protocol === 'http:') || (url.protocol === 'wss:' && target.protocol === 'https:'));
      return ['data:', 'blob:'].includes(url.protocol) || sameOrigin ? route.continue() : route.abort('blockedbyclient');
    });
    await use();
    expect(errors, 'The Angular application must not produce runtime errors.').toEqual([]);
  }, { auto: true }],
});
export { expect };
export type { Page, Locator } from '@playwright/test';
export async function waitForLiveBuild(page: Page, origin: string | URL, expectedBuild?: string): Promise<void> {
  await expect.poll(async () => {
    try {
      const [live, metadata] = await Promise.all([
        page.request.get(new URL('/api/ui/live/health', origin).href),
        page.request.get(new URL('/api/ui/meta', origin).href),
      ]);
      if (!live.ok() || !metadata.ok()) return false;
      const heartbeat = await live.json(); const document = await metadata.json();
      return heartbeat.ready === true && typeof heartbeat.buildId === 'string'
        && heartbeat.buildId === document.data?.uiBuildId
        && (expectedBuild === undefined || heartbeat.buildId === expectedBuild);
    } catch { return false; }
  }, { timeout: 15_000, intervals: [100, 200, 500], message: 'The live daemon must be ready for the current UI build.' }).toBe(true);
}
export async function login(page: Page, username = 'owner', password = 'Chronos-test-2026!'): Promise<void> {
  await page.goto('/login'); await page.getByLabel('Username', { exact: true }).fill(username); await page.getByLabel('Password', { exact: true }).fill(password);
  await page.getByLabel('Remember me', { exact: true }).uncheck();
  await page.getByRole('button', { name: 'Sign in', exact: true }).click(); await expect(page.locator('.app-wrapper')).toBeVisible();
}
export async function fitsViewport(page: Page): Promise<void> {
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1)).toBeTruthy();
}
