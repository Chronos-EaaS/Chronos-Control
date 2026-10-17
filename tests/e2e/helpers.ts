import {
  test as playwright,
  expect,
  type APIRequestContext,
  type Locator,
  type Page,
} from '@playwright/test';
import { execFileSync } from 'node:child_process';
import path from 'node:path';

export const fixture = {
  password: 'Chronos-test-2026!',
  system: 1,
  privateSystem: 2,
  project: 1,
  privateProject: 2,
  experiment: 1,
  archivedExperiment: 2,
  privateExperiment: 3,
  evaluation: 1,
  completeEvaluation: 2,
  archivedEvaluation: 3,
  privateEvaluation: 4,
  jobs: { scheduled: 1, setup: 2, running: 3, failed: 4, finished: 5, aborted: 6 },
  completeJobs: [7, 8, 9, 10, 11, 12],
} as const;

export type Account = 'superadmin' | 'admin' | 'owner' | 'member' | 'outsider' | 'disabled';

/** Never allow this destructive regression suite to target a deployed Chronos. */
export function assertLocalURL(value: string | undefined): URL {
  if (!value) throw new Error('Set a loopback baseURL for the isolated Chronos fixture stack.');
  const url = new URL(value);
  if (url.origin !== 'http://127.0.0.1:8088' || url.pathname !== '/') {
    throw new Error(`Refusing regression actions outside an HTTP loopback fixture: ${url.origin}`);
  }
  return url;
}

export const test = playwright.extend<{ localFixtureGuard: void }>({
  localFixtureGuard: [async ({ baseURL, page }, use) => {
    const allowed = assertLocalURL(baseURL);
    // Serial workers plus a fresh seed per case make writes independent of
    // test order and prevent a failed case leaking its changes into another.
    execFileSync(path.resolve('scripts/test-stack'), ['reset'], {
      cwd: process.cwd(), timeout: 15_000, stdio: 'pipe',
    });
    const health = await page.request.get(new URL('/healthz.txt', allowed).href);
    if (!health.ok() || (await health.text()).trim() !== 'chronos-test-ready') throw new Error('The loopback server is not the isolated Chronos fixture.');
    // The legacy UI requests Google Fonts and Gravatar. They are unnecessary
    // for behavior checks; blocking them keeps local fixture data local.
    await page.route('**/*', async route => {
      const url = new URL(route.request().url());
      if (['http:', 'https:'].includes(url.protocol) && url.origin !== allowed.origin) {
        await route.abort('blockedbyclient');
      } else {
        await route.continue();
      }
    });
    await use();
  }, { auto: true }],
});

export { expect };

export function unique(prefix: string): string {
  return `${prefix}-${Date.now()}-${Math.random().toString(36).slice(2, 7)}`;
}

export function panel(page: Page, title: string | RegExp): Locator {
  return page.locator('.box').filter({
    has: page.locator('.box-title').filter({ hasText: typeof title === 'string'
      ? new RegExp(`^${title.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}$`)
      : title }),
  }).first();
}

export async function login(page: Page, username: string = 'owner', password: string = fixture.password): Promise<void> {
  await page.goto('/user/login');
  await page.locator('[name="username"]').fill(username);
  await page.locator('[name="password"]').fill(password);
  await page.getByRole('button', { name: 'Sign In', exact: true }).click();
  await expect(page.locator('.content-header h1')).toContainText('Chronos Control');
}

export async function logout(page: Page): Promise<void> {
  await page.goto('/user/logout');
  await expect(page.getByRole('button', { name: 'Sign In', exact: true })).toBeVisible();
}

export async function noServerError(page: Page): Promise<void> {
  await expect(page.locator('body')).not.toContainText(/Fatal error|Uncaught\s+(?:Error|Exception)|Stacktrace:/);
}

export async function api<T = Record<string, unknown>>(
  request: APIRequestContext,
  path: string,
): Promise<T> {
  const response = await request.get(path);
  expect(response.ok(), `GET ${path}`).toBeTruthy();
  const body = await response.json();
  expect(body.status?.code, `GET ${path}: ${body.status?.message}`).toBe(200);
  return body.response as T;
}

export async function patchJob(
  request: APIRequestContext,
  id: number,
  form: Record<string, string | number>,
): Promise<void> {
  const response = await request.patch(`/api/ui/job/id=${id}`, { form });
  expect(response.ok()).toBeTruthy();
  const body = await response.json();
  expect(body.status?.code, body.status?.message).toBe(200);
}

export async function createProject(page: Page, name = unique('Regression Project'), system = fixture.system): Promise<number> {
  await page.goto('/project/create');
  await page.locator('[name="name"]').fill(name);
  await page.locator('[name="description"]').fill('Disposable UI regression project — Zürich.');
  await page.locator('[name="system"]').selectOption(String(system));
  if (await page.locator('[name="owner"]').count()) {
    await page.locator('[name="owner"]').selectOption('3');
  }
  await page.getByRole('button', { name: 'Create', exact: true }).click();
  await expect(page.locator('#projects')).toBeVisible();
  // Admin-created projects are owned by owner, so admin must select all.
  if (await page.locator('#showAllUser').count()) {
    await page.locator('#showAllUser').uncheck();
  }
  const row = page.locator('#projects tbody tr').filter({ hasText: name });
  await expect(row).toHaveCount(1);
  const href = await row.getAttribute('data-href');
  const id = Number(href?.match(/id=(\d+)/)?.[1]);
  expect(id).toBeGreaterThan(0);
  return id;
}

export async function createSystem(page: Page, name = unique('Regression System')): Promise<number> {
  await page.goto('/admin/createSystem');
  await page.locator('[name="name"]').fill(name);
  await page.locator('[name="description"]').fill('Disposable UI regression system.');
  await page.locator('[name="owner"]').selectOption('3');
  await page.getByRole('button', { name: 'Add', exact: true }).click();
  await expect(page.locator('.content-header h1')).toContainText(`System: ${name}`);
  const id = Number(await page.locator('input[name="id"]').first().inputValue());
  expect(id).toBeGreaterThan(0);
  return id;
}

export async function createUser(page: Page): Promise<{ username: string; id: number }> {
  const username = unique('reguser');
  await page.goto('/admin/newUser');
  await page.locator('[name="username"]').fill(username);
  await page.locator('[name="lastname"]').fill('Regression');
  await page.locator('[name="firstname"]').fill('Fixture');
  await page.locator('[name="gender"]').selectOption('1');
  await page.locator('[name="email"]').fill(`${username}@example.test`);
  await page.locator('[name="password"]').fill(fixture.password);
  await page.locator('[name="password-repeat"]').fill(fixture.password);
  await page.getByRole('button', { name: 'Save', exact: true }).click();
  await expect(page.locator('.content-header h1')).toContainText('Administration');
  const row = panel(page, 'Users').locator('tr').filter({ hasText: username });
  const href = await row.locator('a[href^="/user/edit/id="]').getAttribute('href');
  const id = Number(href?.match(/id=(\d+)/)?.[1]);
  expect(id).toBeGreaterThan(0);
  return { username, id };
}

export async function clickAndAccept(page: Page, action: () => Promise<unknown>): Promise<void> {
  page.once('dialog', dialog => dialog.accept());
  await action();
}
