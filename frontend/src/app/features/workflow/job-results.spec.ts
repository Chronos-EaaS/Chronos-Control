import { Component, input } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { By } from '@angular/platform-browser';
import { provideRouter } from '@angular/router';
import { RouterTestingHarness } from '@angular/router/testing';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { Api, ApiError } from '../../core/api';
import { Realtime } from '../../core/realtime';
import { RealtimeMock, provideRealtimeMock } from '../../core/realtime-testing';
import { ResultsExplorer } from '../results/results-explorer';
import { JobResults } from './job-results';

@Component({
  selector: 'app-results-explorer',
  template: '{{ resourceType() }}/{{ resourceId() }}',
})
class ResultsStub {
  readonly resourceType = input('evaluations');
  readonly resourceId = input.required<number>();
  readonly allowConfigurationManagement = input(true);
}

describe('JOB-14 dedicated job results workspace', () => {
  const job = {
    id: 8,
    internalId: 2,
    projectId: 3,
    projectName: 'Project',
    experimentId: 4,
    experimentName: 'Experiment',
    evaluationId: 5,
    evaluationName: 'Evaluation',
  };
  let api: { get: ReturnType<typeof vi.fn> };
  beforeEach(() => {
    api = { get: vi.fn(async () => structuredClone(job)) };
    TestBed.configureTestingModule({
      providers: [
        provideRouter([{ path: 'jobs/:id/results', component: JobResults }]),
        { provide: Api, useValue: api },
        provideRealtimeMock(api),
      ],
    });
    TestBed.overrideComponent(JobResults, {
      remove: { imports: [ResultsExplorer] },
      add: { imports: [ResultsStub] },
    });
  });

  it('opens job-specific results directly with full hierarchy and a return link', async () => {
    const harness = await RouterTestingHarness.create();
    await harness.navigateByUrl('/jobs/8/results', JobResults);
    await harness.fixture.whenStable();
    harness.detectChanges();
    const root = harness.routeNativeElement!;
    expect(api.get).toHaveBeenCalledWith('/jobs/8', {});
    expect(root.querySelector('h1')?.textContent).toContain('Job results');
    expect(root.querySelector('h1')?.textContent).toContain('#8 · 2');
    expect(root.querySelector('app-results-explorer')?.textContent).toBe('jobs/8');
    for (const [href, text] of [
      ['/projects/3', 'Project'],
      ['/experiments/4', 'Experiment'],
      ['/evaluations/5', 'Evaluation'],
      ['/jobs/8', 'Job 2'],
    ]) {
      expect(root.querySelector(`nav a[href="${href}"]`)?.textContent).toContain(text);
    }
    expect(root.querySelector('a.btn[href="/jobs/8"]')?.textContent).toBe('‹ Job details');
    expect(root.querySelector('dialog')).toBeNull();
    expect(root.querySelector('form')).toBeNull();
    const explorer = harness.routeDebugElement!.query(By.directive(ResultsStub))
      .componentInstance as ResultsStub;
    expect(explorer.allowConfigurationManagement()).toBe(true);
  });

  it.each(['0', '-1', 'invalid', '9007199254740992'])(
    'rejects invalid job ID %s without opening a stream or explorer',
    async (id) => {
      const harness = await RouterTestingHarness.create();
      await harness.navigateByUrl(`/jobs/${id}/results`, JobResults);
      await harness.fixture.whenStable();
      harness.detectChanges();
      expect(api.get).not.toHaveBeenCalled();
      expect(harness.routeNativeElement!.textContent).toContain('Choose a valid job.');
      expect(harness.routeNativeElement!.querySelector('app-results-explorer')).toBeNull();
    },
  );

  it('loads independently authorized results concurrently with their context and removes them on initial failure', async () => {
    let reject!: (error: Error) => void;
    api.get.mockReturnValue(
      new Promise((_resolve, fail) => {
        reject = fail;
      }),
    );
    const harness = await RouterTestingHarness.create();
    await harness.navigateByUrl('/jobs/8/results', JobResults);
    harness.detectChanges();
    expect(harness.routeNativeElement!.querySelector('app-results-explorer')?.textContent).toBe(
      'jobs/8',
    );
    reject(new ApiError('Job access denied.', 403, 'forbidden'));
    await harness.fixture.whenStable();
    harness.detectChanges();
    expect(harness.routeNativeElement!.querySelector('app-results-explorer')).toBeNull();
    expect(harness.routeNativeElement!.textContent).toContain('Job access denied.');
  });

  it.each([0, 500, 503])(
    'retains the mounted workspace after a temporary context error %s and recovers by push',
    async (status) => {
      const watch = vi.spyOn(TestBed.inject(Realtime), 'watch');
      const harness = await RouterTestingHarness.create();
      await harness.navigateByUrl('/jobs/8/results', JobResults);
      await harness.fixture.whenStable();
      harness.detectChanges();
      const explorer = harness.routeNativeElement!.querySelector('app-results-explorer');
      watch.mock.calls[0][3]!(new ApiError('Temporary context failure', status, 'temporary'));
      harness.detectChanges();
      expect(harness.routeNativeElement!.querySelector('app-results-explorer')).toBe(explorer);
      expect(harness.routeNativeElement!.textContent).toContain('Temporary context failure');
      const realtime = TestBed.inject(Realtime) as unknown as RealtimeMock;
      realtime.push('/jobs/8', { ...job, evaluationName: 'Updated evaluation' });
      harness.detectChanges();
      expect(harness.routeNativeElement!.textContent).not.toContain('Temporary context failure');
      expect(harness.routeNativeElement!.textContent).toContain('Updated evaluation');
      expect(api.get).toHaveBeenCalledTimes(1);
    },
  );

  it.each([401, 403, 404])(
    'removes private context and charts as soon as access fails with %s',
    async (status) => {
      const watch = vi.spyOn(TestBed.inject(Realtime), 'watch');
      const harness = await RouterTestingHarness.create();
      await harness.navigateByUrl('/jobs/8/results', JobResults);
      await harness.fixture.whenStable();
      harness.detectChanges();
      watch.mock.calls[0][3]!(new ApiError('Job unavailable', status, 'denied'));
      harness.detectChanges();
      expect(harness.routeNativeElement!.querySelector('h1')).toBeNull();
      expect(harness.routeNativeElement!.querySelector('app-results-explorer')).toBeNull();
    },
  );

  it('ignores old denied responses and pushed snapshots when navigating to another job, and unsubscribes on exit', async () => {
    let reject!: (error: Error) => void;
    api.get.mockImplementation((path: string) =>
      path === '/jobs/8'
        ? new Promise((_resolve, fail) => {
            reject = fail;
          })
        : Promise.resolve({ ...job, id: 9, internalId: 3 }),
    );
    const harness = await RouterTestingHarness.create();
    await harness.navigateByUrl('/jobs/8/results', JobResults);
    const realtime = TestBed.inject(Realtime) as unknown as RealtimeMock;
    const stale = [...realtime.listeners][0];
    await harness.navigateByUrl('/jobs/9/results', JobResults);
    await harness.fixture.whenStable();
    reject(new ApiError('Old job denied', 403, 'denied'));
    stale.apply(job);
    await harness.fixture.whenStable();
    harness.detectChanges();
    expect(harness.routeNativeElement!.textContent).not.toContain('Old job denied');
    expect(harness.routeNativeElement!.querySelector('app-results-explorer')?.textContent).toBe(
      'jobs/9',
    );
    expect(harness.routeNativeElement!.querySelector('h1')?.textContent).toContain('#9 · 3');
    expect(realtime.listeners.size).toBe(1);
    harness.fixture.destroy();
    expect(realtime.listeners.size).toBe(0);
  });
});
