import { test, expect, login, fitsViewport, type Page } from './fixture';
import { observeLive } from './live-helper';

interface EventRow { id: number; at: string; title: string; }
interface EventPage { items: EventRow[]; total: number; page: number; pageSize: number; }
async function events(page: Page, resource: string, params: Record<string, string | number>): Promise<EventPage> {
  const response = await page.request.get(`/api/ui${resource}/events`, { params });
  expect(response.ok(), `Authorized timeline ${resource}`).toBe(true);
  return (await response.json()).data;
}

test('TIMELINE-01: pushed descendant activity preserves drafts and navigation while workflow search, paging and ordering stay consistent', async ({ page }) => {
  test.setTimeout(90_000);
  const live = observeLive(page);
  await login(page);
  const httpTimelineReads: string[] = [];
  page.on('request', request => {
    const path = new URL(request.url()).pathname;
    if (request.method() === 'GET' && /^\/api\/ui\/(projects|experiments|evaluations|jobs)\/\d+\/events$/.test(path)) httpTimelineReads.push(path);
  });
  await page.goto('/projects/1');
  const initial = await live.next('/projects/1/events');
  expect(initial.query).toEqual({}); expect(initial.data.pageSize).toBe(5);
  await expect(page.locator('#project-description')).toBeEnabled();
  await page.locator('#project-description').fill('Unsaved project draft');

  const session = (await (await page.request.get('/api/ui/session')).json()).data;
  const headers = { Origin: new URL(page.url()).origin, 'X-CSRF-Token': session.csrfToken };
  const changeJob = async (status: number) => {
    const response = await page.request.patch('/api/ui/jobs/1', { headers, data: { status } });
    expect(response.ok()).toBe(true);
  };
  const beforeProject = live.mark();
  for (let index = 0; index < 6; ++index) await changeJob(index % 2);
  const seeded = await events(page, '/jobs/1', { pageSize: 100, sort: '-id' });
  expect(seeded.total).toBe(6);
  const newestProject = await live.next('/projects/1/events', frame => frame.data.items[0]?.id === seeded.items[0].id, beforeProject);
  expect(newestProject.data.items).toHaveLength(5);
  await expect(page.locator('#project-description')).toHaveValue('Unsaved project draft');
  const projectActivity = page.getByLabel('Project activity', { exact: true });
  const firstProjectEvent = projectActivity.locator('article').first();
  await expect(firstProjectEvent).toContainText('Job status changed');
  await expect(firstProjectEvent.locator('time')).toHaveAttribute('title', /\d{2}\.\d{2}\.\d{4} \d{2}:\d{2}:\d{2}/);
  await expect(firstProjectEvent.getByRole('link', { name: 'Fixture Project', exact: true })).toHaveAttribute('href', '/projects/1');
  const experimentSnapshot = live.next('/experiments/1/events', () => true, live.mark());
  page.once('dialog', dialog => dialog.accept());
  await firstProjectEvent.getByRole('link', { name: 'Fixture Experiment', exact: true }).click();
  const experiment = await experimentSnapshot;
  expect(experiment.query).toEqual({ pageSize: '20', sort: '-id' });
  expect(experiment.data.items).toHaveLength(6);
  expect(live.active('/projects/1/events')).toHaveLength(0);
  await expect(page.locator('#experiment-description')).toBeEnabled();
  await page.locator('#experiment-description').fill('Unsaved experiment draft');
  const externalExperiment = live.next('/experiments/1/events', frame => frame.data.items[0]?.id > seeded.items[0].id, live.mark());
  await changeJob(2);
  const updatedExperiment = await externalExperiment;
  await expect(page.locator('#experiment-description')).toHaveValue('Unsaved experiment draft');
  const jobSnapshot = live.next('/jobs/1/events', () => true, live.mark());
  page.once('dialog', dialog => dialog.accept());
  await page.getByLabel('Experiment timeline', { exact: true }).locator('article').first().getByRole('link', { name: 'Job #1', exact: true }).click();
  const job = await jobSnapshot;
  expect(job.query).toEqual({}); expect(job.data.pageSize).toBe(5);
  expect(live.active('/experiments/1/events')).toHaveLength(0);
  await expect(page.getByLabel('Description', { exact: true })).toBeEnabled();
  await page.getByLabel('Description', { exact: true }).fill('Unsaved job draft');
  const externalJob = live.next('/jobs/1/events', frame => frame.data.items[0]?.id > updatedExperiment.data.items[0].id, live.mark());
  await changeJob(0); await externalJob;
  await expect(page.getByLabel('Description', { exact: true })).toHaveValue('Unsaved job draft');

  const query = { search: 'job status changed', pageSize: 100, sort: '-id' };
  const complete = await events(page, '/jobs/1', query);
  expect(complete.total).toBe(8);
  const ids = complete.items.map(event => event.id);
  for (const resource of ['/projects/1', '/experiments/1', '/evaluations/1', '/jobs/1']) {
    const all = await events(page, resource, query);
    expect(all.items.map(event => event.id)).toEqual(ids);
    const second = await events(page, resource, { ...query, pageSize: 2, page: 2 });
    expect(second).toMatchObject({ total: 8, page: 2, pageSize: 2 });
    expect(second.items.map(event => event.id)).toEqual(ids.slice(2, 4));
    const last = await events(page, resource, { ...query, pageSize: 2, page: 999 });
    expect(last.page).toBe(4); expect(last.items.map(event => event.id)).toEqual(ids.slice(6));
    const ascending = await events(page, resource, { ...query, sort: 'id' });
    expect(ascending.items.map(event => event.id)).toEqual([...ids].reverse());
    for (const sort of ['at', '-at']) {
      const chronological = await events(page, resource, { ...query, sort });
      const expected = [...complete.items].sort((left, right) => (sort === 'at' ? 1 : -1) * (Date.parse(left.at) - Date.parse(right.at)) || left.id - right.id);
      expect(chronological.items.map(event => event.id)).toEqual(expected.map(event => event.id));
    }
    const missing = await events(page, resource, { ...query, search: 'no_such_%_timeline_event' });
    expect(missing).toMatchObject({ items: [], total: 0, page: 1, pageSize: 100 });
  }
  expect(live.active('/jobs/1/events')).toHaveLength(1);
  expect(httpTimelineReads, 'Healthy activity panels use their existing socket, including after child updates.').toEqual([]);
  await fitsViewport(page);
});
