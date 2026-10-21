import { test, expect, login, fitsViewport } from './fixture';

test('WORKSPACE-01: project creation stays in the table header and page sizing in its footer at every viewport', async ({ page }) => {
  await login(page); await page.goto('/projects');
  const card = page.locator('section.project-browser');
  const header = card.locator(':scope > .card-header');
  const footer = card.locator(':scope > .card-footer');
  const create = header.getByRole('button', { name: 'Create project', exact: true });
  const size = footer.getByLabel('Rows per page', { exact: true });
  await expect(create).toBeEnabled();
  await expect(page.getByRole('button', { name: 'Create project', exact: true })).toHaveCount(1);
  await expect(size).toBeVisible();
  await expect(header.getByLabel('Rows per page', { exact: true })).toHaveCount(0);
  const response = page.waitForResponse(value => new URL(value.url()).pathname === '/api/ui/projects' && new URL(value.url()).searchParams.get('pageSize') === '100');
  await size.selectOption('100');
  expect((await response).ok()).toBe(true);
  await expect(size).toHaveValue('100');
  await expect(card.getByRole('link', { name: 'Fixture Project', exact: true })).toBeVisible();
  for (const width of [320, 390, 768, 1440]) {
    await page.setViewportSize({ width, height: 900 });
    await expect(create).toBeVisible(); await expect(size).toBeVisible();
    const action = await create.boundingBox(), top = await header.boundingBox();
    expect(action!.x).toBeGreaterThanOrEqual(top!.x);
    expect(action!.x + action!.width).toBeLessThanOrEqual(top!.x + top!.width + 1);
    await fitsViewport(page);
  }
  await create.click();
  const dialog = page.getByRole('dialog', { name: 'Create project', exact: true });
  await expect(dialog.getByLabel('Name', { exact: true })).toBeEnabled();
  await expect(dialog.getByLabel('System', { exact: true })).toBeVisible();
  await dialog.getByRole('button', { name: 'Close', exact: true }).click();
  await expect(dialog).not.toBeVisible();
  await expect(size).toHaveValue('100');
});

