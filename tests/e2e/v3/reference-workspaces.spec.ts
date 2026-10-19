import { test, expect, login, fitsViewport } from './fixture';

test('VISUAL-HOME: original counter order, semantic colors and adjacent desktop activity remain recognizable', async ({ page }) => {
  await login(page);
  const metrics = page.locator('.dashboard-metrics');
  await expect(metrics.locator('.info-box-text')).toHaveText(['Projects', 'Experiments', 'Completed evaluations', 'Running evaluations', 'Total jobs', 'Active jobs', 'Failed jobs']);
  const boxes = metrics.locator('.info-box');
  await expect(boxes).toHaveCount(7);
  expect(await metrics.locator('.info-box-icon').evaluateAll(elements => elements.map(element => getComputedStyle(element).backgroundColor))).toEqual([
    'rgb(61, 114, 153)', 'rgb(61, 114, 153)', 'rgb(45, 133, 104)', 'rgb(183, 121, 36)', 'rgb(61, 114, 153)', 'rgb(183, 121, 36)', 'rgb(187, 84, 77)',
  ]);
  const first = (await boxes.first().boundingBox())!;
  const last = (await boxes.last().boundingBox())!;
  const activity = (await page.locator('.dashboard-activity').boundingBox())!;
  expect(last.y).toBeGreaterThan(first.y + first.height * 5);
  if (page.viewportSize()!.width >= 992) {
    expect(first.height).toBeGreaterThanOrEqual(89); expect(first.height).toBeLessThanOrEqual(91);
    expect(first.y).toBeLessThanOrEqual(128);
    expect(activity.x).toBeGreaterThan(first.x + first.width);
    expect(Math.abs(activity.y - first.y)).toBeLessThanOrEqual(2);
    expect((await page.locator('.app-header').boundingBox())!.height).toBeLessThanOrEqual(52);
  } else if (page.viewportSize()!.width < 768) {
    expect(activity.y).toBeGreaterThan(last.y + last.height);
  }
  await fitsViewport(page);
});

test('VISUAL-PROJECT: desktop composition and mobile table readability retain all original sections', async ({ page }) => {
  await login(page); await page.goto('/projects/1');
  await expect(page.getByRole('heading', { name: 'Project: Fixture Project', exact: true })).toBeVisible();
  await expect(page.locator('.project-workspace > section .card-header h2')).toHaveText(['General', 'Experiments', 'Currently running', 'Starred evaluations', 'Members']);
  await expect(page.getByLabel('Description', { exact: true })).toHaveAttribute('rows', '8');
  const general = (await page.locator('.project-general').boundingBox())!;
  const workspace = (await page.locator('.project-workspace').boundingBox())!;
  const timeline = (await page.locator('.project-timeline').boundingBox())!;
  if (page.viewportSize()!.width >= 992) {
    expect(general.y).toBeLessThanOrEqual(120); expect(general.height).toBeLessThanOrEqual(450);
    expect(timeline.x).toBeGreaterThan(general.x + general.width);
    expect(Math.abs(timeline.y - general.y)).toBeLessThanOrEqual(2);
    const row = (await page.getByRole('table', { name: 'Experiments', exact: true }).locator('tbody tr').first().boundingBox())!;
    expect(row.height).toBeLessThanOrEqual(64);
  } else {
    expect(timeline.y).toBeGreaterThanOrEqual(workspace.y + workspace.height);
    expect(await page.getByRole('link', { name: '1.90', exact: true }).evaluate(element => element.getClientRects().length), 'Hierarchical evaluation IDs must remain on one line at phone width').toBe(1);
    for (const table of await page.locator('.reference-grid .grid-table').all()) {
      expect(await table.evaluate(element => element.scrollWidth <= element.clientWidth + 1), 'Reference tables must wrap readable cells instead of requiring horizontal scrolling').toBe(true);
    }
  }
  await expect(page.getByRole('table', { name: 'Currently running evaluations', exact: true })).toContainText('Mixed Evaluation');
  await expect(page.getByRole('table', { name: 'Starred evaluations', exact: true })).toContainText('Complete Evaluation');
  await fitsViewport(page);
});

test('PROJECT-07: complete-row navigation preserves native action buttons and keyboard links', async ({ page }) => {
  await login(page); await page.goto('/projects/1');
  const experiments = page.getByRole('table', { name: 'Experiments', exact: true });
  page.once('dialog', dialog => dialog.dismiss());
  await experiments.getByRole('button', { name: 'Archive Fixture Experiment', exact: true }).click();
  await expect(page).toHaveURL('/projects/1');
  await experiments.getByRole('cell', { name: 'Synthetic experiment 1', exact: true }).click();
  await expect(page).toHaveURL('/experiments/1');
  await page.goto('/projects/1');
  const link = page.getByRole('table', { name: 'Experiments', exact: true }).getByRole('link', { name: 'Fixture Experiment', exact: true });
  await link.focus(); await page.keyboard.press('Enter');
  await expect(page).toHaveURL('/experiments/1');
});
