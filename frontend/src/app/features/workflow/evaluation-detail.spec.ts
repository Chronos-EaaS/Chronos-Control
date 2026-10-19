import { TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { RouterTestingHarness } from '@angular/router/testing';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { Api } from '../../core/api';
import { Job } from '../../core/workflow-models';
import { EvaluationDetail } from './evaluation-detail';

const evaluation = {
  id: 1,
  internalId: 1,
  name: 'Evaluation',
  description: 'Saved description',
  experimentId: 1,
  experimentName: 'Experiment',
  projectId: 1,
  projectName: 'Project',
  systemId: 1,
  systemName: 'System',
  environment: 'cem-fixture',
  counts: { total: 2, finished: 1, active: 1, failed: 0, aborted: 0 },
  permissions: { canEdit: true, canAbort: true, canReschedule: false },
};
const job = {
  id: 10,
  internalId: 2,
  description: 'Finished fixture job',
  statusName: 'finished',
  currentPhase: 5,
  progress: 100,
  permissions: { canDownload: true },
};

describe('EVALUATION-03/04/10 details, job controls and response ordering', () => {
  let api: { get: ReturnType<typeof vi.fn>; patch: ReturnType<typeof vi.fn> };
  beforeEach(() => {
    api = {
      get: vi.fn(async (path: string) => {
        if (path === '/evaluations/1') return structuredClone(evaluation);
        if (path.endsWith('/jobs')) return { items: [job], page: 1, pageSize: 7, total: 1 };
        if (path.endsWith('/events')) return { items: [] };
        if (path.endsWith('/results'))
          return {
            totalJobs: 0,
            finishedJobs: 0,
            activeJobs: 0,
            configurations: [],
            charts: [],
            jobs: [],
            warnings: [],
          };
        throw new Error(`Unexpected unit-test request ${path}`);
      }),
      patch: vi.fn(async (_path, body) => ({ ...evaluation, ...body })),
    };
    TestBed.configureTestingModule({
      providers: [
        provideRouter([{ path: 'evaluations/:id', component: EvaluationDetail }]),
        { provide: Api, useValue: api },
      ],
    });
  });
  afterEach(() => vi.restoreAllMocks());
  async function setup() {
    const harness = await RouterTestingHarness.create();
    const component = await harness.navigateByUrl('/evaluations/1', EvaluationDetail);
    await harness.fixture.whenStable();
    harness.detectChanges();
    return { harness, component };
  }
  it('shows the recorded environment and exposes ZIP only for downloadable finished results', async () => {
    const { harness } = await setup();
    const element = harness.routeNativeElement!;
    expect(element.textContent).toContain('CEM: fixture');
    expect(
      element.querySelector('a[aria-label="Download result ZIP for job 2"]')?.getAttribute('href'),
    ).toBe('/api/ui/jobs/10/download');
    expect(
      [...element.querySelectorAll('button')].find((button) =>
        button.textContent?.includes('Reschedule failed'),
      )?.disabled,
    ).toBe(true);
  });
  it('keeps the evaluation workspace free of timeline and result requests while exposing finished results separately', async () => {
    const { harness, component } = await setup();
    const element = harness.routeNativeElement!;
    expect(element.querySelector('app-activity')).toBeNull();
    expect(element.querySelector('app-results-explorer')).toBeNull();
    expect(element.querySelector('a[aria-label="Results"]')?.getAttribute('href')).toBe(
      '/evaluations/1/results',
    );
    expect(element.querySelector('#job-search')?.closest('.card-header')).not.toBeNull();
    expect(element.querySelector('#job-status')?.closest('.card-header')).not.toBeNull();
    await component.refresh();
    expect(api.get.mock.calls.some(([path]) => /\/(events|results)$/.test(path))).toBe(false);
    component.evaluation.set({
      ...component.evaluation()!,
      counts: { total: 2, finished: 0, active: 2, failed: 0, aborted: 0 },
    });
    harness.detectChanges();
    expect(element.querySelector('a[aria-label="Results"]')).toBeNull();
  });
  it('renders the recorded execution stage together with the current stage percentage during polling', async () => {
    const { harness, component } = await setup();
    api.get.mockImplementation(async (path: string) =>
      path.endsWith('/jobs')
        ? {
            items: [{ ...job, statusName: 'running', currentPhase: 3, progress: 37 }],
            page: 1,
            pageSize: 7,
            total: 1,
          }
        : structuredClone(evaluation),
    );
    await component.refresh();
    harness.detectChanges();
    let progress = harness.routeNativeElement!.querySelector('[role="progressbar"]')!;
    expect(progress.getAttribute('aria-valuetext')).toBe('Execute: 37 percent');
    expect(progress.getAttribute('aria-valuenow')).toBe('37');
    expect(harness.routeNativeElement!.querySelector('.job-stage')?.textContent).toBe('Execute');
    api.get.mockImplementation(async (path: string) =>
      path.endsWith('/jobs')
        ? {
            items: [{ ...job, statusName: 'running', currentPhase: 4, progress: 8 }],
            page: 1,
            pageSize: 7,
            total: 1,
          }
        : structuredClone(evaluation),
    );
    await component.refresh();
    harness.detectChanges();
    progress = harness.routeNativeElement!.querySelector('[role="progressbar"]')!;
    expect(progress.getAttribute('aria-valuetext')).toBe('Analyze: 8 percent');
    expect(progress.getAttribute('aria-valuenow')).toBe('8');
    for (const [phase, label] of [
      [1, 'Prepare'],
      [2, 'Warm-up'],
      [5, 'Clean'],
      [null, 'Awaiting stage'],
      [9, 'Awaiting stage'],
    ] as const)
      expect(component.stage({ ...job, statusName: 'running', currentPhase: phase } as Job)).toBe(
        label,
      );
    expect(component.stage({ ...job, statusName: 'setup', currentPhase: null } as Job)).toBe(
      'Setup',
    );
    expect(component.stage({ ...job, statusName: 'scheduled', currentPhase: 3 } as Job)).toBe(
      'Not started',
    );
    expect(component.progress(Number.NaN)).toBe(0);
    expect(component.progress(-20)).toBe(0);
    expect(component.progress(120)).toBe(100);
  });
  it('uses configured page size initially and submits all job-column sort changes', async () => {
    const { harness, component } = await setup();
    expect(component.pageSize).toBe(7);
    expect(api.get).toHaveBeenCalledWith(
      '/evaluations/1/jobs',
      expect.not.objectContaining({ pageSize: expect.anything() }),
    );
    const button = harness.routeNativeElement!.querySelector<HTMLButtonElement>(
      'button[aria-label="Sort by Stage"]',
    )!;
    button.click();
    await harness.fixture.whenStable();
    harness.detectChanges();
    expect(api.get).toHaveBeenCalledWith(
      '/evaluations/1/jobs',
      expect.objectContaining({ sort: 'phase', page: 1 }),
    );
    expect(button.closest('th')?.getAttribute('aria-sort')).toBe('ascending');
    button.click();
    await harness.fixture.whenStable();
    expect(api.get).toHaveBeenCalledWith(
      '/evaluations/1/jobs',
      expect.objectContaining({ sort: '-phase' }),
    );
  });
  it('does not overwrite edited metadata while refreshing statuses', async () => {
    const { harness, component } = await setup();
    const name = harness.routeNativeElement!.querySelector<HTMLInputElement>('#evaluation-name')!;
    name.value = 'Unsaved title';
    name.dispatchEvent(new Event('input', { bubbles: true }));
    await harness.fixture.whenStable();
    await component.refresh();
    harness.detectChanges();
    expect(name.value).toBe('Unsaved title');
  });
  it('does not restore old metadata from polling that began before a successful save', async () => {
    const { harness, component } = await setup();
    let finish!: (value: unknown) => void;
    api.get.mockImplementationOnce(
      () =>
        new Promise((resolve) => {
          finish = resolve;
        }),
    );
    const polling = component.refresh();
    component.name = 'Updated title';
    component.description = 'Updated description';
    component.changes.setDirty(true);
    await component.save();
    finish(structuredClone(evaluation));
    await polling;
    harness.detectChanges();
    expect(component.evaluation()?.name).toBe('Updated title');
    expect(component.name).toBe('Updated title');
    expect(component.description).toBe('Updated description');
    expect(component.changes.dirty()).toBe(false);
    await component.refresh();
    expect(api.get).toHaveBeenLastCalledWith(
      '/evaluations/1/jobs',
      expect.objectContaining({ status: 'all' }),
    );
  });
  it('does not replace newer filtered rows when an older request finishes later', async () => {
    const { harness, component } = await setup();
    let finish!: (value: unknown) => void;
    api.get.mockImplementationOnce(
      () =>
        new Promise((resolve) => {
          finish = resolve;
        }),
    );
    const pending = component.loadJobs();
    component.status = 'running';
    await component.loadJobs();
    finish({ items: [], total: 100, page: 4, pageSize: 99 });
    await pending;
    harness.detectChanges();
    expect(component.jobs()).toEqual([job]);
    expect(component.total()).toBe(1);
    expect(component.page).toBe(1);
    expect(component.pageSize).toBe(7);
  });
});
