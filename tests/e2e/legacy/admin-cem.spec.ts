import { test, expect, login, logout, fixture, panel, createUser, clickAndAccept } from './helpers';

test('ADMIN-01, ADMIN-02: admin creates a user and can include disabled accounts in the list', async ({ page }) => {
  await login(page, 'admin');
  await page.goto('/admin/main');
  const disabled = panel(page, 'Users').locator('tr').filter({ has: page.locator('a[href="/user/edit/id=6"]') });
  await expect(disabled).not.toBeVisible();
  await page.locator('#filter').uncheck({ force: true });
  await expect(disabled).toBeVisible();
  const user = await createUser(page);
  await expect(panel(page, 'Users')).toContainText(user.username);
  await logout(page);
  await login(page, user.username);
  await expect(page.locator('.content-header h1')).toContainText('Chronos Control');
});

test('PROFILE-02, PROFILE-03: admin updates identity/resets a password, then user changes their own password', async ({ page }) => {
  await login(page, 'admin');
  const user = await createUser(page);
  await page.goto(`/user/edit/id=${user.id}`);
  const renamed = `${user.username}edited`;
  await page.locator('[name="username"]').fill(renamed);
  await page.locator('[name="firstname"]').fill('Edited Fixture');
  await page.locator('button[name="group"][value="identity"]').click();
  await expect(page.locator('body')).toContainText('Successfully updated user settings.');
  await expect(page.locator('[name="username"]')).toHaveValue(renamed);
  await expect(page.locator('[name="old-password"]')).toHaveCount(0);
  await page.locator('[name="new-password"]').fill('Reset-password-2026!');
  await page.locator('[name="new-password-repeat"]').fill('Reset-password-2026!');
  await page.locator('button[name="group"][value="password"]').click();
  await expect(page.locator('body')).toContainText('Successfully changed password.');
  await logout(page);
  await login(page, renamed, 'Reset-password-2026!');
  await page.goto('/user/edit');
  await expect(page.locator('[name="firstname"]')).toHaveValue('Edited Fixture');
  await page.locator('[name="old-password"]').fill('Wrong-password-2026!');
  await page.locator('[name="new-password"]').fill('Changed-password-2026!');
  await page.locator('[name="new-password-repeat"]').fill('Changed-password-2026!');
  await page.locator('button[name="group"][value="password"]').click();
  await expect(page.locator('body')).toContainText('old password is wrong');
  await page.goto('/user/edit');
  await page.locator('[name="old-password"]').fill('Reset-password-2026!');
  await page.locator('[name="new-password"]').fill('Changed-password-2026!');
  await page.locator('[name="new-password-repeat"]').fill('Changed-password-2026!');
  await page.locator('button[name="group"][value="password"]').click();
  await expect(page.locator('body')).toContainText('Successfully changed password.');
  await logout(page);
  await login(page, renamed, 'Changed-password-2026!');
});

test('PROFILE-04: admin disables/reactivates an account and login respects the enabled flag', async ({ page }) => {
  await login(page, 'admin');
  const user = await createUser(page);
  await page.goto(`/user/edit/id=${user.id}`);
  await page.locator('[name="alive"]').uncheck({ force: true });
  await page.locator('[name="activated"]').uncheck({ force: true });
  const deactivating = page.waitForResponse(response => response.request().isNavigationRequest() && response.request().method() === 'POST' && new URL(response.url()).pathname.replace(/\/$/, '') === `/user/edit/id=${user.id}`);
  await page.locator('button[name="group"][value="admin"]').click();
  const deactivated = await deactivating; expect(deactivated.status()).toBe(200); expect(await deactivated.finished()).toBeNull();
  await expect(page.locator('body')).toContainText('Successfully updated admin settings.');
  await page.reload();
  await expect(page.locator('[name="alive"]')).not.toBeChecked();
  await expect(page.locator('[name="activated"]')).not.toBeChecked();
  await logout(page);
  await page.goto('/user/login');
  await page.locator('[name="username"]').fill(user.username);
  await page.locator('[name="password"]').fill(fixture.password);
  await page.getByRole('button', { name: 'Sign In' }).click();
  await expect(page.locator('.alert')).toContainText('deactivated');
  await login(page, 'admin');
  await page.goto(`/user/edit/id=${user.id}`);
  await page.locator('[name="alive"]').check({ force: true });
  await page.locator('[name="activated"]').check({ force: true });
  const reactivating = page.waitForResponse(response => response.request().isNavigationRequest() && response.request().method() === 'POST' && new URL(response.url()).pathname.replace(/\/$/, '') === `/user/edit/id=${user.id}`);
  await page.locator('button[name="group"][value="admin"]').click();
  const reactivated = await reactivating; expect(reactivated.status()).toBe(200); expect(await reactivated.finished()).toBeNull();
  await expect(page.locator('body')).toContainText('Successfully updated admin settings.');
  await logout(page);
  await login(page, user.username);
});

test('PROFILE-05: superadmin assigns an admin role that changes subsequent navigation permissions', async ({ page }) => {
  await login(page, 'superadmin');
  const user = await createUser(page);
  await page.goto(`/user/edit/id=${user.id}`);
  await page.locator('[name="admin"]').selectOption('1');
  const savingRole = page.waitForResponse(response => response.request().isNavigationRequest() && response.request().method() === 'POST' && new URL(response.url()).pathname.replace(/\/$/, '') === `/user/edit/id=${user.id}`);
  await page.locator('button[name="group"][value="admin"]').click();
  const roleSaved = await savingRole; expect(roleSaved.status()).toBe(200); expect(await roleSaved.finished()).toBeNull();
  await expect(page.locator('body')).toContainText('Successfully updated admin settings.');
  await page.reload();
  await expect(page.locator('[name="admin"]')).toHaveValue('1');
  await logout(page);
  await login(page, user.username);
  await expect(page.locator('.navbar-custom-menu a[href="/admin/main"]')).toBeVisible();
  await page.goto('/admin/main');
  await expect(page.locator('.content-header h1')).toContainText('Administration');
  await expect(page.getByRole('button', { name: /Update$/ })).toHaveCount(0);
});

test('ADMIN-03, AUTH-04, SHELL-04: soft switch shows target mode and logout returns to original admin', async ({ page }) => {
  await login(page, 'admin');
  await page.goto('/admin/main');
  await page.locator('a[href="/admin/switchUser/username=member"]').click();
  await expect(page.locator('body')).toContainText('Do you really want to switch');
  await page.getByRole('button', { name: /Yes$/ }).click();
  await expect(page.locator('.navbar')).toContainText('Switch User Mode');
  await page.locator('.user-menu > a').click();
  await expect(page.locator('.user-header')).toContainText('(member)');
  await page.goto('/admin/switchUser/username=owner');
  await expect(page.locator('.error-page')).toContainText('403');
  await page.goto('/user/logout');
  await expect(page.locator('.content-header h1')).toContainText('Chronos Control');
  await expect(page.locator('.navbar')).not.toContainText('Switch User Mode');
  await page.locator('.user-menu > a').click();
  await expect(page.locator('.user-header')).toContainText('(admin)');
});

test('ADMIN-04: confirmed hard switch replaces rather than impersonates the session', async ({ page }) => {
  await login(page, 'admin');
  await page.goto('/admin/switchUser/username=member');
  await page.locator('#changeUser').check();
  await page.getByRole('button', { name: /Yes$/ }).click();
  await expect(page.locator('.content-header h1')).toContainText('Chronos Control');
  await expect(page.locator('.navbar')).not.toContainText('Switch User Mode');
  await page.locator('.user-menu > a').click();
  await expect(page.locator('.user-header')).toContainText('(member)');
  await logout(page);
  await page.goto('/admin/main');
  await expect(page.getByRole('button', { name: 'Sign In' })).toBeVisible();
});

test('ADMIN-05, ADMIN-07, ADMIN-12: global settings persist; mount status and system identifiers are visible', async ({ page }) => {
  await login(page, 'admin');
  await page.goto('/admin/main');
  const settings = panel(page, 'Settings - other');
  await settings.locator('[name="other###rowsPerPage"]').fill('10');
  const savingRows = page.waitForResponse(response => response.request().isNavigationRequest() && response.request().method() === 'POST' && new URL(response.url()).pathname.replace(/\/$/, '') === '/admin/main');
  await settings.getByRole('button', { name: /Save$/ }).click();
  const rowsSaved = await savingRows; expect(rowsSaved.status()).toBe(200); expect(await rowsSaved.finished()).toBeNull();
  await page.reload();
  await expect(panel(page, 'Settings - other').locator('[name="other###rowsPerPage"]')).toHaveValue('10');
  await expect(panel(page, 'Mount Status')).toContainText(/mounted|not mounted|unknown/);
  await expect(panel(page, 'Systems')).toContainText('Fixture System');
  await expect(panel(page, 'Systems')).toContainText('Private System');
  await page.goto('/admin/mountDataDirectory');
  await expect(page.locator('.error-page')).toContainText('403');
});

test('ADMIN-06: global CEM environments add once, reject duplicates, feed run options and delete', async ({ page }) => {
  await login(page, 'admin');
  await page.goto('/admin/main');
  await page.locator('[name="newEnvironmentName"]').fill('regression-cem');
  await page.locator('button[name="group"][value="newEnvironment"]').click();
  await expect(panel(page, 'CEM Environments').locator('input:disabled[value="regression-cem"]')).toHaveCount(1);
  await page.locator('[name="newEnvironmentName"]').fill('regression-cem');
  const addingDuplicate = page.waitForResponse(response => response.request().isNavigationRequest() && response.request().method() === 'POST' && new URL(response.url()).pathname.replace(/\/$/, '') === '/admin/main');
  await page.locator('button[name="group"][value="newEnvironment"]').click();
  const duplicateAdded = await addingDuplicate; expect(duplicateAdded.status()).toBe(200); expect(await duplicateAdded.finished()).toBeNull();
  await expect(panel(page, 'CEM Environments').locator('input:disabled[value="regression-cem"]')).toHaveCount(1);
  await page.goto('/experiment/detail/id=1');
  await page.getByRole('button', { name: /Run Evaluation$/ }).click();
  await expect(page.locator('#environment option[value="cem-regression-cem"]')).toHaveText(/regression-cem.*0 nodes/);
  await page.goto('/admin/main');
  const form = page.locator('form').filter({ has: page.locator('input[name="environmentToDelete"][value="regression-cem"]') });
  await clickAndAccept(page, () => form.getByRole('button', { name: /Delete$/ }).click());
  await expect(panel(page, 'CEM Environments').locator('input:disabled[value="regression-cem"]')).toHaveCount(0);
});

test('CEM-01, CEM-02: missing-node filter, hostname search and node navigation work', async ({ page }) => {
  await login(page, 'admin');
  await page.goto('/cem/overview');
  await expect(page.locator('#nodes tbody tr')).toHaveCount(3);
  await expect(page.locator('#nodes')).not.toContainText('missing.fixture.test');
  await page.locator('#showMissingNodes').check();
  await expect(page.locator('#nodes tbody tr')).toHaveCount(4);
  await expect(page.locator('#nodes')).toContainText('missing.fixture.test');
  await page.locator('#nodes_filter input').fill('warning.fixture.test');
  await expect(page.locator('#nodes tbody tr')).toHaveCount(1);
  await expect(page.locator('#nodes tbody tr')).toContainText('warning.fixture.test');
  await expect(page.locator('#nodes tbody tr .glyphicon-alert')).toBeVisible();
  await page.locator('#nodes tbody tr').click();
  await expect(page.locator('.widget-user-username')).toHaveText('warning.fixture.test');
});

test('CEM-03, CEM-04, CEM-05: node metadata, utilization thresholds, health and timeline links render', async ({ page }) => {
  await login(page, 'admin');
  await page.goto('/cem/detail/id=fixture-warning');
  await expect(page.locator('body')).toContainText('Fixture Linux');
  await expect(page.locator('body')).toContainText('192.0.2.13');
  await expect(page.locator('.info-box.bg-red').filter({ hasText: 'CPU' })).toContainText('55%');
  await expect(page.locator('.info-box.bg-red').filter({ hasText: 'Memory' })).toContainText('95%');
  await expect(page.locator('body')).toContainText('Fixture memory warning');
  await expect(page.locator('body')).toContainText('Second warning line');
  await expect(page.locator('.timeline-item')).toContainText('Fixture node warning');
  await page.locator('.timeline-footer a[href="/cem/detail/id=fixture-warning"]').click();
  await expect(page.locator('.widget-user-username')).toHaveText('warning.fixture.test');
  await page.goto('/cem/detail/id=fixture-idle');
  await expect(page.locator('.info-box.bg-green').filter({ hasText: 'CPU' })).toContainText('15%');
  await expect(page.locator('.info-box.bg-green').filter({ hasText: 'Memory' })).toContainText('25%');
  await expect(page.locator('.info-box').filter({ hasText: 'Health Status' })).toContainText('OK');
});
