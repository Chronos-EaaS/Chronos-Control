import { test, expect, fixture, login, logout, noServerError, createProject } from './helpers';

test('AUTH-01: invalid and disabled accounts cannot sign in; valid owner can', async ({ page }) => {
  await page.goto('/user/login');
  await page.locator('[name="username"]').fill('owner');
  await page.locator('[name="password"]').fill('Incorrect-fixture-password');
  await page.getByRole('button', { name: 'Sign In' }).click();
  await expect(page.locator('.alert')).toContainText('Wrong username or password');
  await page.locator('[name="username"]').fill('disabled');
  await page.locator('[name="password"]').fill(fixture.password);
  await page.getByRole('button', { name: 'Sign In' }).click();
  await expect(page.locator('.alert')).toContainText('deactivated');
  await login(page);
  await noServerError(page);
});

test('AUTH-02: remember-me restores a session and normal logout revokes it', async ({ page, context }) => {
  await page.goto('/user/login');
  await page.locator('[name="username"]').fill('owner');
  await page.locator('[name="password"]').fill(fixture.password);
  await page.locator('.icheck label').click();
  await expect(page.locator('[name="remember"]')).toBeChecked();
  await page.getByRole('button', { name: 'Sign In' }).click();
  await expect(page.locator('.content-header h1')).toContainText('Chronos Control');
  const remembered = (await context.cookies()).filter(cookie => cookie.name === 'rememberMe');
  expect(remembered).toHaveLength(1);
  await context.clearCookies();
  await context.addCookies(remembered);
  await page.goto('/home/main');
  await expect(page.locator('.content-header h1')).toContainText('Chronos Control');
  await logout(page);
  const afterLogout = (await context.cookies()).filter(cookie => cookie.name === 'rememberMe');
  if (afterLogout.length) {
    test.info().annotations.push({ type: 'legacy-defect', description: 'Logout expires a cookie without the original root path, so the browser retains rememberMe. This scenario verifies that its server-side token is nevertheless revoked.' });
  }
  await context.clearCookies();
  await context.addCookies(afterLogout);
  await page.goto('/home/main');
  await expect(page.getByRole('button', { name: 'Sign In' })).toBeVisible();
});

test('AUTH-03, SHELL-05: sign-in preserves a protected deep link and contextual navigation', async ({ page }) => {
  await page.goto('/job/detail/id=3');
  await expect(page.getByRole('button', { name: 'Sign In' })).toBeVisible();
  await page.locator('[name="username"]').fill('owner');
  await page.locator('[name="password"]').fill(fixture.password);
  await page.getByRole('button', { name: 'Sign In' }).click();
  await expect(page).toHaveURL(/\/job\/detail\/id=3/);
  await expect(page.locator('.content-header h1')).toContainText('Job Details');
  await page.locator('.breadcrumb').getByRole('link', { name: /Experiment$/ }).click();
  await expect(page).toHaveURL(/\/experiment\/detail\/id=1/);
  await page.goBack();
  await expect(page).toHaveURL(/\/job\/detail\/id=3/);
  await page.goForward();
  await expect(page).toHaveURL(/\/experiment\/detail\/id=1/);
});

test('AUTH-04, SHELL-04: account menu opens profile and logs out', async ({ page }) => {
  await login(page);
  await page.locator('.user-menu > a').click();
  await page.getByRole('link', { name: /Profile$/ }).click();
  await expect(page.locator('.content-header h1')).toHaveText('Profile');
  await page.locator('.user-menu > a').click();
  await page.getByRole('link', { name: /Log out$/ }).click();
  await expect(page.getByRole('button', { name: 'Sign In' })).toBeVisible();
  await page.goto('/home/main');
  await expect(page.getByRole('button', { name: 'Sign In' })).toBeVisible();
});

test('SHELL-01, SHELL-02, SHELL-03, SHELL-06: owner desktop/mobile navigation, branding and footer', async ({ page }) => {
  // This scenario explicitly exercises both layouts in every engine. Other
  // scenarios retain each project's configured desktop/mobile viewport.
  await page.setViewportSize({ width: 1440, height: 1000 });
  await login(page);
  const menu = page.locator('.sidebar-menu');
  for (const name of ['Overview', 'Projects', 'Status', 'Systems']) {
    await expect(menu.getByRole('link', { name: new RegExp(`${name}$`) })).toBeVisible();
  }
  await expect(menu.getByRole('link', { name: /CEM$/ })).toHaveCount(0);
  await expect(page.locator('a[href="/admin/main"]')).toHaveCount(0);
  await menu.getByRole('link', { name: /Projects$/ }).click();
  await expect(menu.locator('li.active')).toContainText('Projects');
  await page.locator('.sidebar-toggle').click();
  await expect(page.locator('body')).toHaveClass(/sidebar-collapse/);
  await page.locator('.sidebar-toggle').click();
  await page.locator('.logo').click();
  await expect(page.locator('.content-header h1')).toContainText('Chronos Control');
  await expect(page.locator('.main-footer')).toContainText(/v\d/);
  await expect(page.locator('.main-footer')).toContainText('University of Basel');
  await page.setViewportSize({ width: 390, height: 844 });
  await page.locator('.sidebar-toggle').click();
  await expect(page.locator('body')).toHaveClass(/sidebar-open/);
  await menu.getByRole('link', { name: /Systems$/ }).click();
  await expect(page.locator('.content-header h1')).toHaveText('Systems');
});

test('SHELL-01: admin navigation exposes CEM and administration', async ({ page }) => {
  await login(page, 'admin');
  if ((page.viewportSize()?.width ?? 1440) < 768) {
    await page.locator('.sidebar-toggle').click();
    await expect(page.locator('body')).toHaveClass(/sidebar-open/);
  }
  await page.locator('.sidebar-menu').getByRole('link', { name: /CEM$/ }).click();
  await expect(page.locator('.content-header h1')).toContainText('Chronos Environment Management');
  await page.locator('.navbar-custom-menu a[href="/admin/main"]').click();
  await expect(page.locator('.content-header h1')).toContainText('Administration');
});

test('SHELL-08: forbidden, missing and invalid-resource screens provide recovery', async ({ page }) => {
  await login(page);
  await page.goto('/admin/main');
  await expect(page.locator('.error-page')).toContainText('403');
  await page.getByRole('link', { name: 'return to dashboard' }).click();
  await expect(page.locator('.content-header h1')).toContainText('Chronos Control');
  await page.goto('/missing-controller/missing-action');
  await expect(page.locator('.error-page')).toContainText('404');
  await page.getByRole('link', { name: 'return to dashboard' }).click();
  await page.goto('/job/detail/id=999999');
  await expect(page.locator('body')).toContainText('No job with id: 999999');
  await expect(page.locator('body')).not.toContainText('Stacktrace:');
});

test('HOME-01: creating a project increments only the project dashboard counter', async ({ page }) => {
  await login(page);
  const before = await page.locator('.info-box-number').allTextContents();
  expect(before).toHaveLength(7);
  await createProject(page);
  await page.goto('/home/main');
  const after = await page.locator('.info-box-number').allTextContents();
  expect(Number(after[0])).toBe(Number(before[0]) + 1);
  expect(after.slice(1)).toEqual(before.slice(1));
});

test('HOME-02, SHELL-07: activity timeline shows actor, timestamp and related project link', async ({ page }) => {
  await login(page);
  const item = page.locator('.timeline-item').filter({ hasText: 'Fixture project created' }).first();
  await expect(item).toBeVisible();
  await expect(item.locator('.time')).toHaveAttribute('title', /\d{4}-\d{2}-\d{2} \d{2}:\d{2}/);
  await expect(item.locator('.timeline-body')).not.toBeEmpty();
  await item.locator('a[href="/project/detail/id=1"]').last().click();
  await expect(page.locator('.content-header h1')).toContainText('Fixture Project');
});

test('PROFILE-01: own identity edit survives reload; username and other profiles are protected', async ({ page }) => {
  await login(page);
  await page.goto('/user/edit');
  await expect(page.locator('[name="username"]')).toBeDisabled();
  const firstName = await page.locator('[name="firstname"]').inputValue();
  const email = await page.locator('[name="email"]').inputValue();
  await page.locator('[name="firstname"]').fill('Owner Updated');
  await page.locator('[name="email"]').fill('owner.updated@example.test');
  await page.locator('form').filter({ has: page.locator('[name="firstname"]') })
    .getByRole('button', { name: /Save$/ }).click();
  await expect(page.locator('body')).toContainText('Successfully updated user settings.');
  await page.reload();
  await expect(page.locator('[name="firstname"]')).toHaveValue('Owner Updated');
  await expect(page.locator('[name="email"]')).toHaveValue('owner.updated@example.test');
  // Leave shared fixture identity unchanged for the remaining tests.
  await page.locator('[name="firstname"]').fill(firstName);
  await page.locator('[name="email"]').fill(email);
  await page.locator('form').filter({ has: page.locator('[name="firstname"]') })
    .getByRole('button', { name: /Save$/ }).click();
  await page.goto('/user/edit/id=5');
  await expect(page.locator('body')).toContainText('You are not allowed to edit a user except your self.');
});
