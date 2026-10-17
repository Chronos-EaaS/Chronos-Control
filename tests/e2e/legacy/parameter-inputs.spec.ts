import { test, expect, login, api } from './helpers';

test('PARAM-04, PARAM-05, PARAM-06, PARAM-07, PARAM-08, PARAM-09, PARAM-10, PARAM-11, PARAM-12, PARAM-13, PARAM-14, PARAM-16, EXPERIMENT-01, EXPERIMENT-02: input calculations, validation, conditional fields and generated jobs', async ({ page }) => {
  await login(page);
  // The baseline software-version renderer crashes on a fresh form (copy=null).
  // Remove that element through the real builder so the other eleven input
  // types can be tested independently. PARAM-15 has its own explicit fixme.
  await page.goto('/builder/build/id=1');
  page.once('dialog', dialog => dialog.accept());
  await page.locator('#fixture-version').getByRole('link', { name: /Delete$/ }).click();
  await page.getByRole('button', { name: /Save$/ }).click();
  await expect(page.locator('.content-header h1')).toContainText('System: Fixture System');
  await page.goto('/builder/create/projectId=1');
  await expect(page.locator('[name="enabled"]')).toHaveValue('false');
  await expect(page.locator('[name="quantity"]')).toHaveValue('0');
  await page.locator('[name="name"]').fill('Generated parameter regression');
  await page.locator('[name="description"]').fill('All stable parameter types through actual inputs.');
  await page.locator('[name="runs"]').fill('1');
  await page.locator('[name="run-distribution"]').selectOption('alter');
  await page.locator('[name="enabled"]').selectOption('true');
  await page.locator('[name="quantity"]').fill('200');
  await page.locator('[name="label"]').fill('Zürich Unicode value');
  await page.locator('[name="mode"]').selectOption('basic');
  await expect(page.locator('#fixture-conditional')).not.toBeVisible();
  await expect(page.locator('[name="detail"]')).toBeDisabled();
  await page.locator('[name="mode"]').selectOption('advanced');
  await expect(page.locator('#fixture-conditional')).toBeVisible();
  await expect(page.locator('[name="detail"]')).toBeEnabled();
  await page.locator('[name="detail"]').fill('Visible advanced value');
  await page.locator('[name="mode"]').selectOption('basic');
  await page.locator('[name="choice-read"]').uncheck();
  await page.locator('[name="choice-write"]').uncheck();
  await page.getByRole('button', { name: /Create$/ }).click();
  await expect(page.locator('label.error').filter({ hasText: 'Minimum one checked!' }).first()).toBeVisible();
  await page.locator('[name="choice-read"]').check();
  await page.locator('[name="size-min"]').fill('10');
  await page.locator('[name="size-max"]').fill('20');
  await page.locator('[name="size-step"]').fill('10');
  await page.locator('[name="share-percentage"]').fill('50');
  await expect(page.locator('#parameter-share')).toHaveValue('100');
  await page.locator('[name="portion-percentage-start"]').fill('0');
  await page.locator('[name="portion-percentage-end"]').fill('100');
  await page.locator('[name="portion-percentage-step"]').fill('100');
  await expect(page.locator('#parameter-portion-start')).toHaveValue('0');
  await expect(page.locator('#parameter-portion-end')).toHaveValue('200');
  await expect(page.locator('#parameter-portion-step')).toHaveValue('200');
  await page.locator('[name="dependent-percentage"]').fill('50');
  await page.locator('[name="dependent-percentage"]').press('Tab');
  await expect(page.locator('#parameter-dependent-end')).toHaveValue('100');
  await expect(page.locator('#parameter-dependent-step')).toHaveValue('100');
  await page.locator('[name="left,right-start"]').fill('0%:100%');
  await page.locator('[name="left,right-end"]').fill('100%:0%');
  await page.locator('[name="left,right-step"]').fill('100%');
  await page.locator('[name="tag"]').fill('alpha,beta');
  await page.getByRole('button', { name: /Create$/ }).click();
  await expect(page.locator('.content-header h1')).toContainText('Experiment: Generated parameter regression');
  const experimentId = Number(await page.locator('#id').inputValue());
  expect(experimentId).toBeGreaterThan(3);
  await page.getByRole('button', { name: /Run Evaluation$/ }).click();
  await page.locator('#environment').selectOption('system-local');
  await page.locator('#modal-run-evaluation').getByRole('button', { name: /Run$/ }).click();
  await expect(page.locator('.content-header h1')).toContainText('Evaluation:');
  const evaluationId = Number(await page.locator('#id').inputValue());
  const generated = await api<{ jobs: Array<{ id: number; status: string }> }>(
    page.request, `/api/ui/evaluation/id=${evaluationId}/action=jobs`,
  );
  // 2 sizes × 2 percentage points × 2 ratios × 2 tags × 1 run.
  expect(generated.jobs).toHaveLength(16);
  expect(generated.jobs.every(job => job.status === 'SCHEDULED')).toBeTruthy();
  await page.goto(`/job/detail/id=${generated.jobs[0].id}`);
  await page.getByRole('button', { name: /View CDL$/ }).click();
  const cdl = page.locator('#modal-cdl code');
  await expect(cdl).toContainText('quantity');
  await expect(cdl).toContainText('200');
  await expect(cdl).toContainText('Zürich Unicode value');
  await expect(cdl).toContainText('dependent');
  await expect(cdl).toContainText('left');
  await expect(cdl).toContainText('right');
  await expect(cdl).not.toContainText('Visible advanced value');
  await page.goto(`/builder/create/projectId=1/copyExperimentId=${experimentId}`);
  await expect(page.locator('[name="enabled"]')).toHaveValue('true');
  await expect(page.locator('[name="quantity"]')).toHaveValue('200');
  await expect(page.locator('[name="label"]')).toHaveValue('Zürich Unicode value');
  await expect(page.locator('[name="choice-read"]')).toBeChecked();
  await expect(page.locator('[name="size-min"]')).toHaveValue('10');
  await expect(page.locator('[name="size-max"]')).toHaveValue('20');
  await expect(page.locator('[name="tag"]')).toHaveValue('alpha,beta');
});
