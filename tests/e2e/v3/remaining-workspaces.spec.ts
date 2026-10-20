import { readFile } from 'node:fs/promises';
import path from 'node:path';
import { repositoryRoot } from '../paths';
import { test, expect, login, fitsViewport, type Page } from './fixture';

async function compactShell(page: Page): Promise<void> {
  await expect(page.locator('.app-wrapper')).toHaveClass(/reference-workspace/);
  await expect(page.locator('.brand-symbol')).toHaveAttribute('src', '/chronos-symbol.svg');
  await expect(page.locator('.app-main')).toHaveCSS('font-size', '14px');
  await fitsViewport(page);
}

test('SHELL-09, AUTH-05: original local vector logo and centered login retain mobile controls', async ({ page }) => {
  await page.goto('/login');
  const asset = await page.request.get('/chronos-logo.svg');
  expect(asset.ok()).toBeTruthy();
  expect(await asset.text()).toBe(await readFile(path.join(repositoryRoot, 'frontend/public/chronos-logo.svg'), 'utf8'));
  await expect(page.getByRole('img', { name: 'Chronos', exact: true })).toBeVisible();
  await expect(page.getByLabel('Remember me', { exact: true })).toBeChecked();
  for (const width of [320, 390, 768, 1440]) {
    await page.setViewportSize({ width, height: 900 });
    const box = await page.locator('.login-box').boundingBox();
    expect(box).not.toBeNull(); expect(Math.abs(box!.x + box!.width / 2 - width / 2)).toBeLessThanOrEqual(1);
    const logo = await page.locator('.login-logo').boundingBox(); expect(logo).not.toBeNull();
    expect(logo!.width / logo!.height).toBeCloseTo(1.5, 2);
    await expect(page.getByRole('button', { name: 'Sign in', exact: true })).toBeVisible();
    await fitsViewport(page);
  }
});

test('SHELL-11: symbol-only desktop navigation remains accessible after switching to a mobile drawer', async ({ page }) => {
  await page.setViewportSize({ width: 1440, height: 1000 }); await login(page);
  const toggle = page.getByRole('button', { name: 'Toggle navigation', exact: true });
  const sidebar = page.locator('#main-navigation');
  const wordmark = sidebar.locator('.brand-wordmark');
  await expect(wordmark).toBeVisible(); await toggle.click();
  await expect(toggle).toHaveAttribute('aria-expanded', 'false');
  await expect(wordmark).toBeHidden(); await expect(sidebar.locator('.brand-symbol')).toBeVisible();
  await expect.poll(async () => (await sidebar.boundingBox())!.x).toBeGreaterThanOrEqual(0);
  await expect.poll(async () => (await sidebar.boundingBox())!.width).toBeLessThan(100);
  await sidebar.getByRole('link', { name: 'Projects', exact: true }).click();
  await expect(page.getByRole('heading', { name: 'Projects', level: 1, exact: true })).toBeVisible();
  await page.setViewportSize({ width: 390, height: 844 });
  await expect(sidebar).toHaveAttribute('inert', '');
  await expect(page.getByRole('link', { name: 'Chronos overview', exact: true })).toBeVisible();
  await toggle.click(); await expect(wordmark).toBeVisible();
  await expect.poll(async () => (await sidebar.boundingBox())!.x).toBeGreaterThanOrEqual(0);
  await page.keyboard.press('Escape'); await expect(toggle).toBeFocused();
  await page.getByRole('link', { name: 'Chronos overview', exact: true }).click();
  await expect(page.getByRole('heading', { name: 'Chronos Control', exact: true })).toBeVisible();
  await page.setViewportSize({ width: 1440, height: 1000 });
  await expect(wordmark).toBeHidden(); await toggle.click(); await expect(wordmark).toBeVisible();
  await fitsViewport(page);
});

test('PROJECT-12, SYSTEM-24: compact resource lists keep every sort and contextual action at phone width', async ({ page }) => {
  await login(page); await page.setViewportSize({ width: 390, height: 844 });
  for (const [route, kind, title, label] of [['/projects', 'projects', 'Projects', 'Fixture Project'], ['/systems', 'systems', 'Systems', 'Fixture System']] as const) {
    await page.goto(route); await expect(page.getByRole('heading', { name: title, level: 1, exact: true })).toBeVisible();
    await compactShell(page);
    if (kind === 'systems') {
      await expect(page.locator('.system-cards')).toBeVisible();
      await expect(page.getByRole('link', { name: `Open ${label}`, exact: true })).toBeVisible();
      await page.getByLabel('View', { exact: true }).selectOption('table');
    }
    const table = page.getByRole('table');
    for (const key of ['id', 'name', 'description', ...(kind === 'projects' ? ['system'] : []), 'owner']) {
      const text = key === 'id' ? 'ID' : key[0].toUpperCase() + key.slice(1);
      const button = table.getByRole('button', { name: new RegExp(`^${text}(?:\\s|$)`) });
      const sort = await button.locator('..').getAttribute('aria-sort') === 'ascending' ? `-${key}` : key;
      const response = page.waitForResponse(value => new URL(value.url()).pathname === `/api/ui/${kind}` && new URL(value.url()).searchParams.get('sort') === sort);
      await button.click(); expect((await response).ok()).toBeTruthy();
    }
    const row = table.getByRole('row').filter({ has: page.getByRole('link', { name: label, exact: true }) });
    await expect(row.getByRole('link', { name: `Open ${label}`, exact: true })).toBeVisible();
    const bodyBounds = await table.boundingBox(); expect(bodyBounds!.width).toBeLessThanOrEqual(360);
    await row.locator('td[data-label="Description"]').click(); await expect(page).toHaveURL(new RegExp(`/${kind}/1$`));
    await compactShell(page);
  }
});

test('PROFILE-06: identity and password use original paired panels and stack without clipping on phones', async ({ page }) => {
  await login(page); await page.goto('/profile'); await page.setViewportSize({ width: 1440, height: 1000 });
  const identity = page.locator('section.card').filter({ has: page.getByRole('heading', { name: 'Identity', exact: true }) });
  const password = page.locator('section.card').filter({ has: page.getByRole('heading', { name: 'Password', exact: true }) });
  const left = await identity.boundingBox(), right = await password.boundingBox();
  expect(left).not.toBeNull(); expect(right).not.toBeNull();
  expect(Math.abs(left!.y - right!.y)).toBeLessThanOrEqual(1); expect(Math.abs(left!.width - right!.width)).toBeLessThanOrEqual(1);
  for (const width of [390, 320]) {
    await page.setViewportSize({ width, height: 844 }); await compactShell(page);
    const first = await identity.boundingBox(), second = await password.boundingBox(); expect(second!.y).toBeGreaterThanOrEqual(first!.y + first!.height);
    for (const label of ['Username', 'First name', 'Last name', 'Email', 'Gender', 'Current password', 'New password', 'Confirm new password']) await expect(page.getByLabel(label, { exact: true })).toBeVisible();
  }
});

test('SHELL-10: remaining authenticated page families share the compact responsive workspace', async ({ page }) => {
  await login(page, 'superadmin');
  await page.setViewportSize({ width: 390, height: 844 });
  for (const route of ['/projects/1/experiments/new', '/experiments/1', '/evaluations/1', '/jobs/5', '/status', '/systems/1', '/systems/1?tab=parameters', '/systems/1?tab=results', '/admin', '/admin/users/3', '/cem', '/cem/nodes/fixture-warning', '/profile', '/forbidden']) {
    await page.goto(route); await expect(page.locator('h1')).toBeVisible(); await compactShell(page);
    // Administration loads its system list after metadata. Do not unload the
    // document while that follow-up fetch is still starting in WebKit.
    if (route === '/admin') await expect(page.getByRole('table', { name: 'Administration systems', exact: true }).getByRole('link', { name: 'Fixture System', exact: true })).toBeVisible();
    await expect(page.locator('.app-main h1')).toHaveCSS('font-size', '24px');
  }
});

test('SYSTEM-25: original system cards keep admin scope and card/table sorting without leaking filters between pages', async ({ page }) => {
  await login(page, 'admin'); await page.goto('/systems');
  await expect(page.getByLabel('Visibility', { exact: true }).locator('option:checked')).toHaveText('All systems');
  await expect(page.locator('.system-cards .system-card:not(.system-add)')).toHaveCount(2);
  await expect(page.getByRole('link', { name: 'Fixture System', exact: true })).toBeVisible();
  for (const [sort, direction] of [['owner', 'ascending'], ['-owner', 'descending']] as const) {
    const response = page.waitForResponse(value => new URL(value.url()).pathname === '/api/ui/systems' && new URL(value.url()).searchParams.get('sort') === sort);
    if (direction === 'ascending') await page.getByLabel('Sort systems', { exact: true }).selectOption('owner');
    else await page.getByRole('button', { name: 'Sort systems descending', exact: true }).click();
    expect((await response).ok()).toBeTruthy();
  }
  const view = page.getByLabel('View', { exact: true });
  await view.focus(); await view.selectOption('table'); await expect(view).toBeFocused();
  await expect(page.getByRole('columnheader', { name: /^Owner/ })).toHaveAttribute('aria-sort', 'descending');
  await view.selectOption('cards'); await expect(view).toBeFocused();
  await expect(page.locator('.system-cards')).toBeVisible();
  await page.getByLabel('Visibility', { exact: true }).selectOption({ label: 'My systems' });
  await expect(page.getByRole('link', { name: 'Fixture System', exact: true })).toHaveCount(0);
  const navigation = page.getByRole('navigation', { name: 'Main navigation', exact: true });
  if (page.viewportSize()!.width < 992) await page.getByRole('button', { name: 'Toggle navigation', exact: true }).click();
  await navigation.getByRole('link', { name: 'Projects', exact: true }).click();
  await expect(page.getByLabel('Visibility', { exact: true }).locator('option:checked')).toHaveText('My projects');
  if (page.viewportSize()!.width < 992) await page.getByRole('button', { name: 'Toggle navigation', exact: true }).click();
  await navigation.getByRole('link', { name: 'Systems', exact: true }).click();
  await expect(page.getByLabel('Visibility', { exact: true }).locator('option:checked')).toHaveText('All systems');
  await expect(page.getByLabel('View', { exact: true })).toHaveValue('cards');
  await expect(page.getByLabel('Sort systems', { exact: true })).toHaveValue('id');
  await expect(page.getByRole('link', { name: 'Fixture System', exact: true })).toBeVisible();
  await fitsViewport(page);
});
