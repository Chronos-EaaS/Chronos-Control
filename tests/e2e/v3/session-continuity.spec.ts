import { execFileSync } from 'node:child_process';
import { v3Stack } from '../paths';
import { test, expect, type Page } from './fixture';
import { observeLive } from './live-helper';

async function signIn(page: Page, remember: boolean): Promise<void> {
  await page.goto('/login');
  await page.getByLabel('Username', { exact: true }).fill('owner');
  await page.getByLabel('Password', { exact: true }).fill('Chronos-test-2026!');
  await page.getByLabel('Remember me', { exact: true }).setChecked(remember);
  await page.getByRole('button', { name: 'Sign in', exact: true }).click();
  await expect(page.locator('.app-wrapper')).toBeVisible();
}

async function expirePhpSession(page: Page): Promise<string> {
  const cookie = (await page.context().cookies()).find(value => value.name === 'PHPSESSID');
  expect(cookie, 'The real browser must have a synthetic PHP session.').toBeDefined();
  execFileSync(v3Stack, ['php', '/opt/chronos/tests/fixtures/session-revocation.php', 'expire-session'], {
    input: cookie!.value, timeout: 30_000, stdio: ['pipe', 'pipe', 'pipe'],
  });
  return cookie!.value;
}

test('AUTH-06: a valid remember cookie restores an expired real PHP session without losing the job draft', async ({ page }) => {
  const live = observeLive(page);
  await signIn(page, true); await page.goto('/jobs/1'); await live.next('/jobs/1');
  const remember = (await page.context().cookies()).find(cookie => cookie.name === 'rememberMe');
  expect(remember?.httpOnly).toBe(true);
  await page.getByLabel('Description', { exact: true }).fill('Draft survives remembered-session restoration');
  const reads: string[] = [];
  page.on('request', request => {
    const path = new URL(request.url()).pathname;
    if (request.method() === 'GET' && path.startsWith('/api/ui/')) reads.push(path);
  });
  const resumed = live.next('/jobs/1', () => true, live.mark());
  const previousId = await expirePhpSession(page);
  await resumed;
  await expect(page).toHaveURL(/\/jobs\/1$/);
  await expect(page.getByLabel('Description', { exact: true })).toHaveValue('Draft survives remembered-session restoration');
  const cookies = await page.context().cookies();
  expect(cookies.find(cookie => cookie.name === 'PHPSESSID')?.value).not.toBe(previousId);
  expect(cookies.find(cookie => cookie.name === 'rememberMe')?.value).toBe(remember!.value);
  expect(reads, 'Only the server-declared session expiry permits one credential restoration request.').toEqual(['/api/ui/session']);
  expect(live.active('/jobs/1')).toHaveLength(1);
  const saved = page.waitForResponse(response => new URL(response.url()).pathname === '/api/ui/jobs/1' && response.request().method() === 'PATCH');
  await page.getByRole('button', { name: 'Save description', exact: true }).click();
  const response = await saved;
  expect(response.ok(), 'Saving uses the rotated, restored session CSRF token.').toBe(true);
  expect((await response.json()).data.description).toBe('Draft survives remembered-session restoration');
});

test('AUTH-07: an expired real PHP session without Remember me still requires sign-in', async ({ page }) => {
  const live = observeLive(page);
  await signIn(page, false); await page.goto('/jobs/1'); await live.next('/jobs/1');
  expect((await page.context().cookies()).some(cookie => cookie.name === 'rememberMe')).toBe(false);
  await expirePhpSession(page);
  await expect(page).toHaveURL(/\/login\?returnUrl=%2Fjobs%2F1$/, { timeout: 20_000 });
  await expect(page.getByRole('button', { name: 'Sign in', exact: true })).toBeVisible();
  expect(live.active('/jobs/1')).toHaveLength(0);
});

test('AUTH-08: logout in another tab revokes a remembered open browser session', async ({ page }) => {
  const live = observeLive(page);
  await signIn(page, true); await page.goto('/jobs/1'); await live.next('/jobs/1');
  const session = (await (await page.request.get('/api/ui/session')).json()).data;
  const response = await page.request.delete('/api/ui/session', {
    headers: { Origin: new URL(page.url()).origin, 'X-CSRF-Token': session.csrfToken },
  });
  expect(response.ok()).toBe(true);
  await expect(page).toHaveURL(/\/login(?:\?|$)/, { timeout: 20_000 });
  await expect(page.getByRole('button', { name: 'Sign in', exact: true })).toBeVisible();
  expect((await page.context().cookies()).some(cookie => cookie.name === 'rememberMe')).toBe(false);
  expect(live.active('/jobs/1')).toHaveLength(0);
});
