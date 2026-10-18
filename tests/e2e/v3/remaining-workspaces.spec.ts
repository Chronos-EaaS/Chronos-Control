import { readFile } from 'node:fs/promises';
import path from 'node:path';
import { repositoryRoot } from '../paths';
import { test, expect, login, fitsViewport, type Page } from './fixture';

async function compactShell(page: Page): Promise<void> {
  await expect(page.locator('.app-wrapper')).toHaveClass(/reference-workspace/);
  await expect(page.locator('.brand-link img')).toHaveAttribute('src', '/chronos-logo.svg');
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

test('PROJECT-12, SYSTEM-24: compact resource lists keep every sort and contextual action at phone width', async ({ page }) => {
  await login(page); await page.setViewportSize({ width: 390, height: 844 });
  for (const [route, kind, title, label] of [['/projects', 'projects', 'Projects', 'Fixture Project'], ['/systems', 'systems', 'Systems', 'Fixture System']] as const) {
    await page.goto(route); await expect(page.getByRole('heading', { name: title, exact: true })).toBeVisible();
    await compactShell(page);
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
    await expect(page.locator('.app-main h1')).toHaveCSS('font-size', '24px');
  }
});
