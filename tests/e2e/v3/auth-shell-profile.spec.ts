import { observeLive } from './live-helper';
import { execFileSync } from 'node:child_process';
import { v3Stack } from '../paths';
import { test, expect, login, fitsViewport, waitForLiveBuild, type Page } from './fixture';
import type { Browser, BrowserContext } from '@playwright/test';

const password = 'Chronos-test-2026!';
async function profile(page: Page): Promise<Record<string, unknown>> {
  const response = await page.request.get('/api/ui/profile'); expect(response.ok()).toBeTruthy(); return (await response.json()).data;
}
async function signOut(page: Page): Promise<void> {
  await page.locator('.account-menu summary').click(); await page.getByRole('button', { name: 'Sign out', exact: true }).click(); await expect(page.getByRole('heading', { name: 'Please sign in', exact: true })).toBeVisible();
}
async function localContext(browser: Browser, baseURL: string, cookies: Awaited<ReturnType<BrowserContext['cookies']>> = []): Promise<BrowserContext> {
  const context = await browser.newContext({ baseURL, storageState: { cookies, origins: [] } });
  const origin = new URL(baseURL).origin;
  await context.route('**/*', route => ['data:', 'blob:'].includes(new URL(route.request().url()).protocol) || new URL(route.request().url()).origin === origin ? route.continue() : route.abort('blockedbyclient'));
  return context;
}
function build(action: 'newer' | 'restore'): void {
  execFileSync(v3Stack, ['ui-build', action], { timeout: 15_000, stdio: 'pipe' });
}

test('AUTH-01/03, SHELL-05/08: invalid and disabled logins do not replace a protected deep link', async ({ page }) => {
  await page.goto('/experiments/1?tab=results'); await expect(page.getByRole('heading', { name: 'Please sign in', exact: true })).toBeVisible();
  expect(new URL(page.url()).searchParams.get('returnUrl')).toBe('/experiments/1?tab=results');
  await expect(page.getByRole('button', { name: 'Sign in', exact: true })).toBeDisabled();
  for (const [username, credential] of [['owner', 'Incorrect-password-2026!'], ['disabled', password]]) {
    await page.getByLabel('Username', { exact: true }).fill(username); await page.getByLabel('Password', { exact: true }).fill(credential);
    const submitted = page.waitForResponse(response => new URL(response.url()).pathname === '/api/ui/session' && response.request().method() === 'POST');
    await page.getByRole('button', { name: 'Sign in', exact: true }).click();
    const response = await submitted; expect(response.status()).toBe(401); expect(response.request().postDataJSON().username).toBe(username);
    await expect(page.getByRole('alert')).toContainText('Invalid username or password');
    await expect(page.getByRole('button', { name: 'Sign in', exact: true })).toBeEnabled();
    expect((await (await page.request.get('/api/ui/session')).json()).data.user).toBeNull();
  }
  await page.getByLabel('Username', { exact: true }).fill('owner'); await page.getByLabel('Password', { exact: true }).fill(password);
  const authenticated = page.waitForResponse(response => new URL(response.url()).pathname === '/api/ui/session' && response.request().method() === 'POST');
  await page.getByRole('button', { name: 'Sign in', exact: true }).click();
  const response = await authenticated; expect(response.status()).toBe(200); expect(response.request().postDataJSON().username).toBe('owner');
  await expect(page).toHaveURL(/\/experiments\/1\?tab=results$/); await expect(page.locator('details.configuration-editor')).toHaveAttribute('open', ''); await expect(page.getByRole('heading', { name: 'Experiment: Fixture Experiment', level: 1, exact: true })).toBeVisible(); await fitsViewport(page);
});

test('AUTH-02/04: remember-cookie restoration works without the original PHP session and logout revokes it', async ({ page, context, browser, baseURL }) => {
  await page.goto('/login'); await expect(page.getByLabel('Remember me', { exact: true })).toBeChecked();
  await expect(page.locator('.login-brand')).toHaveText('');
  await expect(page.locator('.login-footer')).toContainText('Version 3.0-dev');
  await expect(page.locator('.login-footer').getByRole('link', { name: 'The Chronos Project', exact: true })).toHaveAttribute('href', 'https://chronos-eaas.org');
  await page.getByLabel('Username', { exact: true }).fill('owner'); await page.getByLabel('Password', { exact: true }).fill(password); await page.getByRole('button', { name: 'Sign in', exact: true }).click(); await expect(page.locator('.app-wrapper')).toBeVisible();
  const remember = (await context.cookies()).filter(cookie => cookie.name === 'rememberMe'); expect(remember).toHaveLength(1); expect(remember[0].httpOnly).toBe(true);
  const restored = await localContext(browser, baseURL!, remember); const restoredPage = await restored.newPage();
  try {
    await restoredPage.goto('/profile'); await expect(restoredPage.getByRole('heading', { name: 'Profile', exact: true })).toBeVisible(); expect((await profile(restoredPage)).username).toBe('owner');
    await signOut(restoredPage); expect((await (await restoredPage.request.get('/api/ui/session')).json()).data.user).toBeNull();
    await expect.poll(async () => (await restored.cookies()).some(cookie => cookie.name === 'rememberMe')).toBe(false);
    const replay = await localContext(browser, baseURL!, remember); const replayPage = await replay.newPage();
    try { await replayPage.goto('/profile'); await expect(replayPage.getByRole('heading', { name: 'Please sign in', exact: true })).toBeVisible(); }
    finally { await replay.close(); }
  } finally { await restored.close(); }
});

test('AUTH-02/04, PROFILE-01: opting out of Remember me sets no token and logout protects subsequent deep links', async ({ page, context }) => {
  await login(page); expect((await context.cookies()).some(cookie => cookie.name === 'rememberMe')).toBe(false); await page.goto('/profile'); await expect(page.getByLabel('Username', { exact: true })).toHaveAttribute('readonly', '');
  await page.getByLabel('First name', { exact: true }).fill('Unsaved logout draft'); await page.locator('.account-menu summary').click();
  page.once('dialog', dialog => dialog.dismiss()); await page.getByRole('button', { name: 'Sign out', exact: true }).click(); await expect(page.getByLabel('First name', { exact: true })).toHaveValue('Unsaved logout draft'); expect((await profile(page)).firstName).toBe('Owner');
  page.once('dialog', dialog => dialog.accept()); await page.getByRole('button', { name: 'Sign out', exact: true }).click(); await expect(page.getByRole('heading', { name: 'Please sign in', exact: true })).toBeVisible();
  await page.goto('/jobs/5'); await expect(page.getByRole('heading', { name: 'Please sign in', exact: true })).toBeVisible(); expect(new URL(page.url()).searchParams.get('returnUrl')).toBe('/jobs/5');
});

test('PROFILE-01/02: identity including gender zero and password updates persist with clear failure feedback', async ({ page }) => {
  const live = observeLive(page); let heartbeats = 0;
  page.on('websocket', socket => {
    if (new URL(socket.url()).pathname !== '/api/ui/live') return;
    socket.on('framereceived', frame => {
      try { if (JSON.parse(frame.payload.toString()).type === 'heartbeat') heartbeats++; } catch { /* Observe JSON heartbeats only. */ }
    });
  });
  await login(page); await page.goto('/profile');
  await page.getByLabel('First name', { exact: true }).fill('Zoë Zürich'); await page.getByLabel('Last name', { exact: true }).fill("O'Connor"); await page.getByLabel('Email', { exact: true }).fill('not-an-email'); await expect(page.getByRole('button', { name: 'Save profile', exact: true })).toBeDisabled(); expect((await profile(page)).email).toBe('owner@example.test');
  await page.getByLabel('Email', { exact: true }).fill('owner+v3@example.test'); await page.getByLabel('Gender', { exact: true }).selectOption({ label: 'Not specified' });
  await page.getByRole('button', { name: 'Save profile', exact: true }).click(); await expect(page.getByRole('status').filter({ hasText: 'Your profile has been updated' })).toBeVisible(); expect(await profile(page)).toMatchObject({ firstName: 'Zoë Zürich', lastName: "O'Connor", email: 'owner+v3@example.test', gender: 0 });
  await page.reload(); await expect(page.getByLabel('First name', { exact: true })).toHaveValue('Zoë Zürich');
  await page.getByLabel('Current password', { exact: true }).fill('Incorrect-password-2026!'); await page.getByLabel('New password', { exact: true }).fill('Changed-password-2026!'); await page.getByLabel('Confirm new password', { exact: true }).fill('Mismatch-password-2026!');
  await page.getByRole('button', { name: 'Update password', exact: true }).click(); await expect(page.getByRole('alert')).toContainText('new passwords do not match');
  const rejectedRebound = live.next('/session', frame => frame.data.user?.username === 'owner', live.mark());
  await page.getByLabel('Confirm new password', { exact: true }).fill('Changed-password-2026!'); await page.getByRole('button', { name: 'Update password', exact: true }).click(); await expect(page.getByRole('alert')).toContainText('current password is incorrect');
  const previousSession = await rejectedRebound;
  const rebound = live.next('/session', frame => frame.data.user?.username === 'owner' && frame.id !== previousSession.id, live.mark());
  await page.getByLabel('Current password', { exact: true }).fill(password); await page.getByRole('button', { name: 'Update password', exact: true }).click(); await expect(page.getByRole('status').filter({ hasText: 'Your password has been updated' })).toBeVisible();
  expect((await rebound).data.user.username).toBe('owner');
  const reconnectedHeartbeats = heartbeats;
  // Cross a complete server lease-validation interval: the revoked old socket
  // must not sign out a browser whose own password change has already succeeded.
  await page.waitForTimeout(15_100);
  expect(heartbeats).toBeGreaterThan(reconnectedHeartbeats);
  await expect(page).toHaveURL(/\/profile$/); await expect(page.getByLabel('Username', { exact: true })).toHaveValue('owner');
  expect((await profile(page)).username).toBe('owner');
  await expect(page.getByLabel('Current password', { exact: true })).toHaveValue(''); await expect(page.getByLabel('New password', { exact: true })).toHaveValue('');
  await signOut(page); await login(page, 'owner', 'Changed-password-2026!'); await page.goto('/profile'); await expect(page.getByLabel('First name', { exact: true })).toHaveValue('Zoë Zürich'); await fitsViewport(page);
});

test('PROFILE-03/05, SHELL-01/08: role-limited navigation and direct mutations remain protected', async ({ page }) => {
  await login(page, 'member'); const navigation = page.getByRole('navigation', { name: 'Main navigation', exact: true });
  await expect(page.getByRole('link', { name: 'Administration', exact: true })).toHaveCount(0); await expect(navigation.getByRole('link', { name: 'CEM', exact: true })).toHaveCount(0);
  const session = (await (await page.request.get('/api/ui/session')).json()).data;
  for (const resource of ['/api/ui/admin/settings', '/api/ui/cem/nodes', '/api/ui/users']) expect((await page.request.get(resource)).status()).toBe(403);
  expect((await page.request.patch('/api/ui/users/3', { data: { role: 2 }, headers: { 'X-CSRF-Token': session.csrfToken } })).status()).toBe(403);
  expect((await page.request.patch('/api/ui/profile', { data: { role: 2 }, headers: { 'X-CSRF-Token': session.csrfToken } })).status()).toBe(422);
  expect((await profile(page)).role).toBe(0);
  await page.goto('/admin'); await expect(page.getByRole('heading', { name: 'Access denied', exact: true })).toBeVisible();
  await page.getByRole('link', { name: 'Return to overview', exact: true }).click(); await expect(page.getByRole('heading', { name: 'Chronos Control', exact: true })).toBeVisible();
  await page.goto('/unknown-chronos-route'); await expect(page.getByRole('heading', { name: 'Page not found', exact: true })).toBeVisible();
});

test('SHELL-12: administrators open settings through a distinct top-menu gear while sidebar navigation stays focused', async ({ page }) => {
  await login(page, 'admin');
  const navigation = page.getByRole('navigation', { name: 'Main navigation', exact: true });
  await expect(navigation.getByRole('link', { name: 'Administration', exact: true })).toHaveCount(0);
  await expect(page.locator('.navigation-section')).toHaveCount(0);
  await expect(page.locator('.brand-text')).toHaveText('');
  await expect(page.getByRole('link', { name: 'Open projects', exact: true })).toHaveCount(0);
  await expect(navigation.locator('a[href="/systems"] app-icon')).toHaveAttribute('name', 'systems');
  await expect(navigation.locator('a[href="/cem"] app-icon')).toHaveAttribute('name', 'cem');
  const settings = page.locator('.app-header').getByRole('link', { name: 'Administration', exact: true });
  await expect(settings.locator('app-icon')).toHaveAttribute('name', 'settings');
  for (const width of [1440, 390, 320]) {
    await page.setViewportSize({ width, height: 900 });
    await settings.click();
    await expect(page.getByRole('heading', { name: 'Administration', exact: true })).toBeVisible();
    await expect(settings).toHaveAttribute('aria-current', 'page');
    if (width < 992) expect((await settings.boundingBox())!.height).toBeGreaterThanOrEqual(40);
    await fitsViewport(page);
    await page.goto('/');
  }
});

test('SHELL-01/02/03/05: keyboard and touch drawer controls work at phone width and permit zoom', async ({ page, browserName }, testInfo) => {
  await login(page); await page.getByRole('link', { name: 'Skip to content', exact: true }).focus(); await page.keyboard.press('Enter'); await expect(page.locator('#main-content')).toBeFocused();
  await page.setViewportSize({ width: 390, height: 844 }); const toggle = page.getByRole('button', { name: 'Toggle navigation', exact: true });
  await expect(page.getByRole('link', { name: 'Open projects', exact: true })).toHaveCount(0);
  expect((await toggle.boundingBox())!.height).toBeGreaterThanOrEqual(40);
  const viewport = await page.locator('meta[name="viewport"]').getAttribute('content'); expect(viewport).not.toMatch(/user-scalable\s*=\s*(?:no|0)|maximum-scale\s*=\s*1(?:\D|$)/i);
  await expect(toggle).toHaveAttribute('aria-expanded', 'false'); await toggle.focus();
  // macOS WebKit uses Option+Tab to include buttons and links in native traversal.
  const tabKey = browserName === 'webkit' && process.platform === 'darwin' ? 'Alt+Tab' : 'Tab';
  await page.keyboard.press(tabKey); await page.keyboard.press('Shift+' + tabKey); await expect(toggle).toBeFocused();
  await expect(toggle).toHaveCSS('outline-style', 'solid'); await expect(toggle).toHaveCSS('outline-width', '2px');
  await page.keyboard.press('Enter'); await expect(toggle).toHaveAttribute('aria-expanded', 'true');
  const nav = page.getByRole('navigation', { name: 'Main navigation', exact: true }); await expect(nav.getByRole('link', { name: 'Overview', exact: true })).toBeFocused();
  await expect(nav.getByRole('link', { name: 'Overview', exact: true })).toHaveCSS('outline-style', 'solid');
  await expect(nav.getByRole('link', { name: 'Overview', exact: true })).toHaveCSS('outline-width', '2px');
  await nav.getByRole('link', { name: 'Systems', exact: true }).focus(); await page.keyboard.press('Tab'); await expect(page.getByRole('link', { name: 'Chronos Control home', exact: true })).toBeFocused();
  await page.keyboard.press('Shift+Tab'); await expect(nav.getByRole('link', { name: 'Systems', exact: true })).toBeFocused(); await page.keyboard.press('Escape'); await expect(toggle).toHaveAttribute('aria-expanded', 'false'); await expect(toggle).toBeFocused();
  if (testInfo.project.use.hasTouch) { await toggle.tap(); await nav.getByRole('link', { name: 'Projects', exact: true }).tap(); }
  else { await toggle.click(); await nav.getByRole('link', { name: 'Projects', exact: true }).click(); }
  await expect(page.getByRole('heading', { name: 'Projects', level: 1, exact: true })).toBeVisible(); await expect(toggle).toHaveAttribute('aria-expanded', 'false');
  await page.setViewportSize({ width: 320, height: 760 }); await page.evaluate(() => { document.documentElement.style.fontSize = '125%'; }); await fitsViewport(page);
});

test('SHELL-06/07, JOB-03: English local assets and German absolute dates remain usable with external origins blocked', async ({ page, baseURL }) => {
  const outside: string[] = []; const origin = new URL(baseURL!).origin;
  page.on('request', request => { const url = new URL(request.url()); if (!['data:', 'blob:'].includes(url.protocol) && url.origin !== origin) outside.push(url.origin); });
  await login(page); await page.goto('/'); const times = page.locator('app-activity time'); await expect(times.first()).toBeVisible();
  for (const element of await times.all()) {
    // The restored timeline displays relative time; its exact Zurich timestamp
    // remains available to assistive technology, hover and machine consumers.
    await expect(element).toHaveAttribute('datetime', '2026-01-15T12:00:00+01:00');
    await expect(element).toHaveAttribute('title', '15.01.2026 12:00:00');
    const relative = (await element.innerText()).replace(/\s+/g, ' ').trim();
    expect(relative).not.toBe(''); expect(relative).not.toBe('—');
    await expect(element).toHaveAttribute('aria-label', `15.01.2026 12:00 · ${relative}`);
  }
  await page.goto('/jobs/5'); await expect(page.locator('time').filter({ hasText: '15.01.2026 12:01:00' })).toBeVisible();
  const metadata = (await (await page.request.get('/api/ui/meta')).json()).data;
  expect(metadata.applicationVersion).toBe('3.0-dev'); await expect(page.locator('.app-footer')).toContainText(`Version ${metadata.applicationVersion}`);
  await expect(page.locator('.app-footer').getByRole('link', { name: 'The Chronos Project', exact: true })).toHaveAttribute('href', 'https://chronos-eaas.org'); expect(outside).toEqual([]); await fitsViewport(page);
});

test('new-v3 version notice: real build metadata shows a centered prompt and protects a draft before document reload', async ({ page }) => {
  test.skip(process.env['CHRONOS_V3_PREVIEW'] === '1', 'Development bundles intentionally disable deployment-version notices; run the compiled 8089 artifact.');
  build('restore'); await login(page); await page.goto('/profile'); const initial = await profile(page); await expect(page.getByRole('complementary', { name: 'Application update', exact: true })).toHaveCount(0);
  await page.getByLabel('First name', { exact: true }).fill('Unsaved version-notice draft');
  try {
    build('newer');
    const notice = page.getByRole('complementary', { name: 'Application update', exact: true }); await expect(notice).toBeVisible();
    const bounds = await notice.boundingBox(); const viewport = page.viewportSize()!; expect(bounds).not.toBeNull(); expect(Math.abs(bounds!.x + bounds!.width / 2 - viewport.width / 2)).toBeLessThanOrEqual(2); expect(bounds!.y + bounds!.height).toBeLessThan(viewport.height); expect(bounds!.y).toBeGreaterThan(viewport.height / 2);
    const previousUrl = page.url(); page.once('dialog', dialog => dialog.dismiss()); await notice.getByRole('button', { name: 'Load latest version', exact: true }).click(); expect(page.url()).toBe(previousUrl); await expect(page.getByLabel('First name', { exact: true })).toHaveValue('Unsaved version-notice draft'); expect((await profile(page)).firstName).toBe(initial.firstName);
    await waitForLiveBuild(page, page.url(), 'f'.repeat(40));
    page.once('dialog', dialog => dialog.accept());
    const navigationRequest = page.waitForRequest(request => request.isNavigationRequest() && new URL(request.url()).searchParams.get('_ui') === 'f'.repeat(40));
    await notice.getByRole('button', { name: 'Load latest version', exact: true }).click(); await navigationRequest; await expect(page.getByLabel('First name', { exact: true })).toHaveValue(String(initial.firstName)); expect(new URL(page.url()).searchParams.get('_ui')).toBe('f'.repeat(40));
    // This fixture changes the real metadata boundary, not compiled JavaScript.
    // Release package tests separately verify replacement bundle identity.
  } finally { build('restore'); }
  await expect(page.getByRole('complementary', { name: 'Application update', exact: true })).toHaveCount(0);
});
