import {
  test, expect, login, logout, fixture, createProject, clickAndAccept,
  panel, unique, api,
} from './helpers';

test('PROJECT-01, PROJECT-02: project rows open, sort and stay scoped to the current member', async ({ page }) => {
  await login(page);
  await page.goto('/project/overview');
  await expect(page.locator('#projects')).toContainText('Fixture Project');
  await expect(page.locator('#projects')).not.toContainText('Private Project');
  await page.locator('#projects th').filter({ hasText: /^Name$/ }).click();
  await expect(page.locator('#projects th').filter({ hasText: /^Name$/ })).toHaveAttribute('aria-sort', /ascending|descending/);
  await page.locator('#projects [data-href="/project/detail/id=1"]').click();
  await expect(page.locator('.content-header h1')).toContainText('Fixture Project');
  await logout(page);
  await login(page, 'admin');
  await page.goto('/project/overview');
  await expect(page.locator('#projects')).not.toContainText('Private Project');
  await page.locator('#showAllUser').uncheck();
  await expect(page.locator('#projects')).toContainText('Fixture Project');
  await expect(page.locator('#projects')).toContainText('Private Project');
});

test('PROJECT-03, PROJECT-04, PROJECT-06: create, archive, filter and restore a project', async ({ page }) => {
  await login(page);
  const name = unique('Archive Project');
  const id = await createProject(page, name);
  await page.goto(`/project/detail/id=${id}`);
  await clickAndAccept(page, () => page.getByRole('button', { name: /Archive this Project$/ }).click());
  await expect(panel(page, /General/)).toContainText('(Archived Project)');
  await page.goto('/project/overview');
  await expect(page.locator('#projects')).not.toContainText(name);
  await page.locator('#showArchivedProjects').check();
  await expect(page.locator('#projects')).toContainText(name);
  await page.locator(`#projects [data-href="/project/detail/id=${id}"]`).click();
  await clickAndAccept(page, () => page.getByRole('button', { name: /Un\-Archive this Project$/ }).click());
  await expect(panel(page, /General/)).not.toContainText('(Archived Project)');
  await page.goto('/project/overview');
  await expect(page.locator('#projects')).toContainText(name);
});

test('PROJECT-05: project save persists Unicode fields and rejects an overlong name', async ({ page }) => {
  await login(page);
  const id = await createProject(page);
  await page.goto(`/project/detail/id=${id}`);
  await page.locator('#name').fill('Updated Zürich Project');
  await page.locator('#description').fill('Saved description <plain text> & Unicode ä.');
  await page.getByRole('button', { name: /Save$/ }).click();
  await expect(page.locator('#saveResultSuccess')).toBeVisible();
  await page.reload();
  await expect(page.locator('#name')).toHaveValue('Updated Zürich Project');
  await expect(page.locator('#description')).toHaveValue('Saved description <plain text> & Unicode ä.');
  await page.locator('#name').fill('x'.repeat(251));
  await page.getByRole('button', { name: /Save$/ }).click();
  await expect(page.locator('#saveResultError')).toContainText('250');
  await page.reload();
  await expect(page.locator('#name')).toHaveValue('Updated Zürich Project');
});

test('PROJECT-07: owner adds a member once, and removal revokes project access', async ({ page }) => {
  await login(page);
  const id = await createProject(page, 'Membership Project');
  await page.goto(`/project/detail/id=${id}`);
  await page.locator('[name="member"]').selectOption('4');
  await page.getByRole('button', { name: 'Add as Member' }).click();
  await expect(panel(page, 'Members').locator('a[href$="/remove=4"]')).toHaveCount(1);
  await page.locator('[name="member"]').selectOption('4');
  const addingDuplicateMember = page.waitForResponse(response => response.request().isNavigationRequest() && response.request().method() === 'POST' && new URL(response.url()).pathname.replace(/\/$/, '') === `/project/detail/id=${id}`);
  await page.getByRole('button', { name: 'Add as Member' }).click();
  const duplicateMemberAdded = await addingDuplicateMember; expect(duplicateMemberAdded.status()).toBe(200); expect(await duplicateMemberAdded.finished()).toBeNull();
  await expect(panel(page, 'Members').locator('a[href$="/remove=4"]')).toHaveCount(1);
  await expect(panel(page, 'Members')).toContainText('Owner');
  await logout(page);
  await login(page, 'member');
  await page.goto(`/project/detail/id=${id}`);
  await expect(page.locator('.content-header h1')).toContainText('Membership Project');
  await expect(page.getByRole('button', { name: 'Add as Member' })).toHaveCount(0);
  await logout(page);
  await login(page);
  await page.goto(`/project/detail/id=${id}`);
  await page.locator(`a[href="/project/detail/id=${id}/remove=4"]`).click();
  await expect(panel(page, 'Members').locator('a[href$="/remove=4"]')).toHaveCount(0);
  await logout(page);
  await login(page, 'member');
  await page.goto(`/project/detail/id=${id}`);
  await expect(page.locator('body')).toContainText('Not enough privileges to view this project!');
});

test('PROJECT-08, PROJECT-09, PROJECT-10: current/archived experiments, running evaluations and timeline', async ({ page }) => {
  await login(page);
  await page.goto('/project/detail/id=1');
  const experiments = panel(page, 'Experiments');
  await expect(experiments.locator('[data-href="/experiment/detail/id=1"]')).toBeVisible();
  await expect(experiments.locator('[data-href="/experiment/detail/id=2"]')).toHaveCount(0);
  await expect(panel(page, 'Currently running').locator('[data-href="/evaluation/detail/id=1"]')).toBeVisible();
  await expect(page.locator('.timeline-item')).not.toHaveCount(0);
  await experiments.locator('[data-href="/experiment/detail/id=1"] button[name="archiveExperiment"]').click();
  await expect(page.locator('body')).toContainText('running evaluations on this experiment');
  await page.goto('/project/detail/id=1/show=archived');
  await expect(panel(page, 'Archived Experiments').locator('[data-href="/experiment/detail/id=2"]')).toBeVisible();
  const unarchiving = page.waitForResponse(response => response.request().isNavigationRequest() && response.request().method() === 'POST' && new URL(response.url()).pathname.replace(/\/$/, '') === '/project/detail/id=1');
  await panel(page, 'Archived Experiments').locator('button[name="unarchiveExperiment"]').click();
  const unarchived = await unarchiving; expect(unarchived.status()).toBe(200); expect(await unarchived.finished()).toBeNull();
  await expect(page).toHaveURL(/\/project\/detail\/id=1\/?(?:\?.*)?$/);
  await expect(panel(page, 'Experiments').locator('[data-href="/experiment/detail/id=2"]')).toBeVisible();
});

test('EXPERIMENT-03, PARAM-05: copy preloads saved experiment values into a new form', async ({ page }) => {
  await login(page);
  await page.goto('/project/detail/id=1');
  await page.locator('a[href="/builder/create/projectId=1/copyExperimentId=1"]').click();
  await expect(page.locator('[name="runs"]')).toHaveValue('1');
  await expect(page.locator('[name="run-distribution"]')).toHaveValue('alter');
  await expect(page.locator('[name="quantity"]')).toHaveValue('10');
  await expect(page.locator('[name="name"]')).toHaveValue('');
  await expect(page.getByRole('button', { name: /Create$/ })).toBeVisible();
});

test('EXPERIMENT-04, EXPERIMENT-05, EXPERIMENT-09: edit experiment and use the saved run environment', async ({ page }) => {
  await login(page);
  await page.goto('/experiment/detail/id=1');
  await page.locator('#name').fill('Updated Fixture Experiment');
  await page.locator('#description').fill('Edited experiment description.');
  await page.locator('#default-environment').selectOption('system-local');
  await page.getByRole('button', { name: /Save$/ }).click();
  await expect(page.locator('#saveResultSuccess')).toBeVisible();
  await page.getByRole('button', { name: /Run Evaluation$/ }).click();
  await expect(page.locator('#modal-run-evaluation')).toBeVisible();
  await expect(page.locator('#environment')).toHaveValue('system-local');
  await page.locator('#modal-run-evaluation').getByRole('button', { name: /Cancel$/ }).click();
  await page.reload();
  await expect(page.locator('#name')).toHaveValue('Updated Fixture Experiment');
  await expect(page.locator('#description')).toHaveValue('Edited experiment description.');
  await page.getByRole('button', { name: /Run Evaluation$/ }).click();
  await page.locator('#environment').selectOption('system-local');
  await page.locator('#modal-run-evaluation').getByRole('button', { name: /Run$/ }).click();
  await expect(page.locator('.content-header h1')).toContainText('Evaluation:');
  await page.goto('/experiment/detail/id=1');
  await expect(page.locator('.timeline-item').filter({ hasText: 'Evaluation Started' }).first()).toBeVisible();
});

test('EXPERIMENT-06, DORMANT-03: run the saved experiment and inspect generated job CDL and phases', async ({ page }) => {
  await login(page);
  await page.goto('/experiment/detail/id=1');
  await page.getByRole('button', { name: /Run Evaluation$/ }).click();
  await page.locator('#environment').selectOption('system-local');
  await page.locator('#modal-run-evaluation').getByRole('button', { name: /Run$/ }).click();
  await expect(page.locator('.content-header h1')).toContainText(/Evaluation: \d{2}\.\d{2}\.\d{4} - \d{2}:\d{2}/);
  const evaluationId = Number(await page.locator('#id').inputValue());
  expect(evaluationId).toBeGreaterThan(4);
  const result = await api<{ jobs: Array<{ id: number; status: string; phases: string[] }> }>(
    page.request, `/api/ui/evaluation/id=${evaluationId}/action=jobs`,
  );
  expect(result.jobs).toHaveLength(1);
  expect(result.jobs[0].status).toBe('SCHEDULED');
  expect(result.jobs[0].phases).toEqual(expect.arrayContaining(['PREPARE', 'EXECUTE', 'ANALYZE', 'CLEAN']));
  await page.goto(`/job/detail/id=${result.jobs[0].id}`);
  await page.getByRole('button', { name: /View CDL$/ }).click();
  await expect(page.locator('#modal-cdl')).toContainText('quantity');
  await expect(page.locator('#modal-cdl')).toContainText('10');
});

test('EXPERIMENT-07: archived evaluation restore works and active evaluation archive is rejected', async ({ page }) => {
  await login(page);
  await page.goto('/experiment/detail/id=1');
  await page.locator('[data-href="/evaluation/detail/id=1"] button[name="archiveEvaluation"]').click();
  await expect(page.locator('body')).toContainText('This evaluation is still running!');
  await page.goto('/experiment/detail/id=1/show=archived');
  await expect(page.locator('[data-href="/evaluation/detail/id=3"]')).toBeVisible();
  const restoring = page.waitForResponse(response => response.request().isNavigationRequest() && response.request().method() === 'POST' && new URL(response.url()).pathname.replace(/\/$/, '') === '/experiment/detail/id=1');
  await page.locator('[data-href="/evaluation/detail/id=3"] button[name="unarchiveEvaluation"]').click();
  const restored = await restoring; expect(restored.status()).toBe(200); expect(await restored.finished()).toBeNull();
  await expect(page).toHaveURL(/\/experiment\/detail\/id=1\/?(?:\?.*)?$/);
  await expect(page.locator('[data-href="/evaluation/detail/id=3"]')).toBeVisible();
});

test('EXPERIMENT-08: experiment result configurations support create, rename, copy, selection and safe deletion', async ({ page }) => {
  await login(page);
  await page.goto('/experiment/detail/id=1');
  await expect(page.locator('button[name="deleteResult"]').first()).toBeDisabled();
  await page.getByRole('button', { name: /Create New$/ }).click();
  const localRow = page.locator('tr').filter({
    has: page.locator('input[name="resultId"][value^="experiment-1-"]'),
  }).first();
  await localRow.getByRole('button', { name: /Rename$/ }).click();
  const rename = page.locator('.modal.in');
  await rename.locator('[name="newName"]').fill('Experiment regression results');
  await rename.getByRole('button', { name: /Rename$/ }).click();
  const renamed = page.locator('tr').filter({ hasText: 'Experiment regression results' }).first();
  await expect(renamed).toBeVisible();
  await renamed.getByRole('button', { name: /Copy$/ }).click();
  const rows = page.locator('tr').filter({ has: page.locator('input[name="resultId"][value^="experiment-1-"]') });
  await expect(rows).toHaveCount(2);
  await rows.first().getByRole('button', { name: /Select$/ }).click();
  await expect(rows.first().getByRole('button', { name: /Select$/ })).toBeDisabled();
  await clickAndAccept(page, () => rows.first().getByRole('button', { name: /Delete$/ }).click());
  await expect(page.locator('body')).toContainText('You cannot delete this result as it is selected currently!');
  await page.goto('/experiment/detail/id=1');
  const unselected = page.locator('tr').filter({ has: page.locator('input[name="resultId"][value^="experiment-1-"]') }).last();
  await clickAndAccept(page, () => unselected.getByRole('button', { name: /Delete$/ }).click());
  await expect(page.locator('tr').filter({ has: page.locator('input[name="resultId"][value^="experiment-1-"]') })).toHaveCount(1);
});
